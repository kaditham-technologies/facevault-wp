<?php
/**
 * Webhook receiver: POST /wp-json/facevault/v1/webhook.
 *
 * Authentication IS the HMAC signature — FaceVault signs the raw request
 * body with the shared secret, so signature verification runs as the REST
 * permission callback. The payload is then whitelist-extracted: raw
 * verification scores present in the payload are never read, stored, or
 * logged (opsec policy; tests enforce it).
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for inbound FaceVault webhooks.
 */
class Webhook_Controller {

	const LOG_OPTION = 'facevault_webhook_log';
	const LOG_CAP    = 20;

	/**
	 * Max bad-signature entries logged per hour. Beyond this only a counter
	 * increments, so unauthenticated POST spam cannot amplify into a DB
	 * write per request.
	 */
	const BAD_SIG_LOG_LIMIT = 5;

	/**
	 * Status writer.
	 *
	 * @var User_Status
	 */
	private $user_status;

	/**
	 * Whether this request already logged a verification failure. WP REST
	 * re-runs permission callbacks after dispatch to build the Allow
	 * response header, so side effects here must be once-per-request.
	 *
	 * @var bool
	 */
	private $failure_logged = false;

	/**
	 * Constructor.
	 *
	 * @param User_Status $user_status Status writer.
	 */
	public function __construct( User_Status $user_status ) {
		$this->user_status = $user_status;
	}

	/**
	 * Hook route registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the webhook route.
	 */
	public function register_routes() {
		register_rest_route(
			'facevault/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'verify_signature' ),
				'callback'            => array( $this, 'handle' ),
			)
		);
	}

	/**
	 * Permission callback: constant-time HMAC check over the raw body.
	 *
	 * WP_REST_Server::serve_request() sets the body from php://input, so
	 * get_body() returns the exact bytes FaceVault signed. Never re-encode
	 * parsed JSON here — key order and whitespace would change the bytes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function verify_signature( $request ) {
		$secret = (string) Settings::get( 'webhook_secret' );
		if ( '' === $secret ) {
			if ( ! $this->failure_logged ) {
				$this->failure_logged = true;
				$this->log( '', 'not_configured', 401 );
			}
			return new WP_Error(
				'facevault_not_configured',
				__( 'Webhook secret is not configured.', 'facevault-identity-verification' ),
				array( 'status' => 401 )
			);
		}

		$signature = strtolower( trim( (string) $request->get_header( 'x-facevault-signature' ) ) );
		$expected  = hash_hmac( 'sha256', (string) $request->get_body(), $secret );

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			if ( ! $this->failure_logged ) {
				$this->failure_logged = true;
				$this->log_bad_signature();
			}
			return new WP_Error(
				'facevault_bad_signature',
				__( 'Invalid webhook signature.', 'facevault-identity-verification' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle a signature-verified delivery.
	 *
	 * Everything except transport/auth problems returns 200: FaceVault
	 * retries failed deliveries, and a retry cannot fix an unknown session
	 * or an event type we don't consume.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		$payload = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $payload ) ) {
			$this->log( '', 'malformed', 400 );
			return new WP_REST_Response(
				array(
					'received' => false,
					'result'   => 'malformed',
				),
				400
			);
		}

		// Whitelist extraction only — do not read anything else out of the
		// payload (it may carry raw scores that must never be persisted).
		$event            = isset( $payload['event'] ) ? (string) $payload['event'] : '';
		$session_id       = isset( $payload['session_id'] ) ? (string) $payload['session_id'] : '';
		$status           = isset( $payload['status'] ) ? (string) $payload['status'] : '';
		$external_user_id = isset( $payload['external_user_id'] ) ? (string) $payload['external_user_id'] : '';
		$trust_decision   = isset( $payload['trust_decision'] ) ? (string) $payload['trust_decision'] : '';

		if ( 'verification.completed' !== $event ) {
			$this->log( $session_id, 'ignored_event', 200 );
			return new WP_REST_Response(
				array(
					'received' => true,
					'result'   => 'ignored_event',
				),
				200
			);
		}

		$result = $this->user_status->apply_webhook( $session_id, $external_user_id, $trust_decision, $status );
		$this->log( $session_id, $result, 200 );

		return new WP_REST_Response(
			array(
				'received' => true,
				'result'   => $result,
			),
			200
		);
	}

	/**
	 * Append to the delivery ring buffer (settings-page debug panel).
	 *
	 * @param string $session_id Session id ('' when unknown).
	 * @param string $result     Outcome keyword.
	 * @param int    $http_code  Response code we returned.
	 */
	private function log( $session_id, $result, $http_code ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) || ! isset( $log['entries'] ) || ! is_array( $log['entries'] ) ) {
			$log = array( 'entries' => array() );
		}
		array_unshift(
			$log['entries'],
			array(
				'time'       => time(),
				'session_id' => $session_id,
				'event'      => 'verification.completed',
				'result'     => $result,
				'http_code'  => $http_code,
			)
		);
		$log['entries'] = array_slice( $log['entries'], 0, self::LOG_CAP );
		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Rate-limited bad-signature logging.
	 */
	private function log_bad_signature() {
		$count = (int) get_transient( 'facevault_badsig_count' );
		set_transient( 'facevault_badsig_count', $count + 1, HOUR_IN_SECONDS );
		if ( $count < self::BAD_SIG_LOG_LIMIT ) {
			$this->log( '', 'bad_signature', 401 );
		}
	}
}

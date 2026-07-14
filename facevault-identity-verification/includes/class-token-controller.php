<?php
/**
 * Logged-in REST routes: mint a widget token, refresh status, and the
 * admin connection test. The API key never leaves the server; the browser
 * only ever sees the single-use, short-lived widget token.
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
 * REST controller for /token, /refresh, and /test.
 */
class Token_Controller {

	// Widget tokens live 5 minutes; 3 mints per 10 minutes covers retries.
	const USER_LIMIT  = 3;
	const USER_WINDOW = 600;

	// Site-wide ceiling below FaceVault's own per-IP budget, so a couple of
	// hostile accounts can't exhaust verification for the whole store.
	const SITE_LIMIT  = 40;
	const SITE_WINDOW = HOUR_IN_SECONDS;

	const REFRESH_WINDOW = 30;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api_client;

	/**
	 * Status writer.
	 *
	 * @var User_Status
	 */
	private $user_status;

	/**
	 * Constructor.
	 *
	 * @param Api_Client  $api_client  API client.
	 * @param User_Status $user_status Status writer.
	 */
	public function __construct( Api_Client $api_client, User_Status $user_status ) {
		$this->api_client  = $api_client;
		$this->user_status = $user_status;
	}

	/**
	 * Hook route registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes. Cookie-authenticated writes get nonce enforcement
	 * from the REST layer itself (X-WP-Nonce).
	 */
	public function register_routes() {
		register_rest_route(
			'facevault/v1',
			'/token',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'handle_token' ),
			)
		);
		register_rest_route(
			'facevault/v1',
			'/refresh',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'handle_refresh' ),
			)
		);
		register_rest_route(
			'facevault/v1',
			'/test',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'handle_test' ),
			)
		);
	}

	/**
	 * Permission callback for /test.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Mint a widget token for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_token( $request ) {
		$user_id = get_current_user_id();

		if ( User_Status::STATUS_VERIFIED === $this->user_status->get_status( $user_id )
			&& ! apply_filters( 'facevault_allow_reverify', false, $user_id ) ) {
			return new WP_Error(
				'already_verified',
				__( 'You are already verified.', 'facevault-identity-verification' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->bump( 'facevault_rl_token_' . $user_id, self::USER_LIMIT, self::USER_WINDOW ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many verification attempts. Please wait a few minutes and try again.', 'facevault-identity-verification' ),
				array(
					'status'      => 429,
					'retry_after' => self::USER_WINDOW,
				)
			);
		}

		if ( ! $this->bump( 'facevault_rl_site', self::SITE_LIMIT, self::SITE_WINDOW ) ) {
			// Surface to the admin: legit traffic at this level means the
			// store needs the ceiling (and the FaceVault plan) raised.
			update_option( 'facevault_ratecap_notice', time(), false );
			return new WP_Error(
				'rate_limited',
				__( 'Verification is very busy right now. Please try again shortly.', 'facevault-identity-verification' ),
				array(
					'status'      => 429,
					'retry_after' => 300,
				)
			);
		}

		$params     = $request->get_json_params();
		$page       = is_array( $params ) && isset( $params['page'] ) ? (string) $params['page'] : '';
		$return_url = '' === $page ? null : wp_validate_redirect( $page, home_url( '/' ) );

		// Opaque per-user ref (never the raw WP user id — enumerable); the
		// facevault_external_user_id filter is applied inside external_ref().
		$external_user_id = $this->user_status->external_ref( $user_id );

		$result = $this->api_client->create_widget_session( $external_user_id, $return_url );

		if ( is_wp_error( $result ) ) {
			return $this->public_api_error( $result );
		}

		if ( empty( $result['widget_token'] ) || empty( $result['session_id'] ) ) {
			return new WP_Error(
				'unavailable',
				__( 'Verification is temporarily unavailable. Please try again later.', 'facevault-identity-verification' ),
				array( 'status' => 503 )
			);
		}

		$this->user_status->record_mint( $user_id, (string) $result['session_id'] );

		return new WP_REST_Response(
			array(
				'token'      => $result['widget_token'],
				'expires_at' => isset( $result['expires_at'] ) ? $result['expires_at'] : null,
			),
			200
		);
	}

	/**
	 * Server-side status recheck for the current user (post-widget or
	 * poll-fallback refresh). Throttled per user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_refresh( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		$user_id      = get_current_user_id();
		$throttle_key = 'facevault_rl_refresh_' . $user_id;

		if ( false === get_transient( $throttle_key ) ) {
			set_transient( $throttle_key, 1, self::REFRESH_WINDOW );
			$session_id = $this->user_status->get_session_id( $user_id );
			if ( '' !== $session_id ) {
				$session = $this->api_client->get_session( $session_id );
				if ( ! is_wp_error( $session ) ) {
					$this->user_status->apply_poll_result( $user_id, $session );
				}
			}
		}

		return new WP_REST_Response(
			array(
				'status'      => $this->user_status->get_status( $user_id ),
				'verified_at' => $this->user_status->get_verified_at( $user_id ),
			),
			200
		);
	}

	/**
	 * Admin connection test: performs a real (free — never opened, expires
	 * in 5 minutes) widget-session mint, which validates the key, its
	 * scope, and the key↔site pairing in one call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_test( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		if ( ! Settings::is_configured() ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Add your API key and Site ID first, then save.', 'facevault-identity-verification' ),
				),
				200
			);
		}

		$result = $this->api_client->create_widget_session( 'facevault-wp-connection-test' );

		if ( ! is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'message' => sprintf(
						/* translators: %s: FaceVault site id. */
						__( 'Connected ✓ (site %s). Note: the connection test cannot validate the webhook secret — check “Webhook health” after the first real verification.', 'facevault-identity-verification' ),
						Settings::get( 'site_id' )
					),
				),
				200
			);
		}

		$messages = array(
			'facevault_auth'         => __( 'API key rejected (401). Re-copy it from the FaceVault dashboard.', 'facevault-identity-verification' ),
			'facevault_scope'        => __( 'Key/site mismatch or missing scope (403). The API key must belong to this site and have sessions:create.', 'facevault-identity-verification' ),
			'facevault_quota'        => __( 'Connected, but the plan limit is reached: ', 'facevault-identity-verification' ) . $result->get_error_message(),
			'facevault_rate_limited' => __( 'Rate limited by FaceVault — wait a minute and retry.', 'facevault-identity-verification' ),
			'facevault_unreachable'  => __( 'Could not reach the API base URL. Check the Advanced settings and your host’s outbound HTTPS.', 'facevault-identity-verification' ),
		);
		$code     = $result->get_error_code();

		return new WP_REST_Response(
			array(
				'ok'      => false,
				'message' => isset( $messages[ $code ] ) ? $messages[ $code ] : $result->get_error_message(),
			),
			200
		);
	}

	/**
	 * Map internal API errors onto the deliberately vague public codes the
	 * front end shows shoppers. Billing details and configuration problems
	 * are admin business, not shopper business.
	 *
	 * @param WP_Error $error Internal error.
	 * @return WP_Error
	 */
	private function public_api_error( $error ) {
		switch ( $error->get_error_code() ) {
			case 'facevault_quota':
				return new WP_Error(
					'quota',
					__( 'Identity verification is temporarily unavailable. Please try again later.', 'facevault-identity-verification' ),
					array( 'status' => 503 )
				);
			case 'facevault_rate_limited':
				$data        = $error->get_error_data();
				$retry_after = is_array( $data ) && isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 600;
				return new WP_Error(
					'rate_limited',
					__( 'Verification is very busy right now. Please try again shortly.', 'facevault-identity-verification' ),
					array(
						'status'      => 429,
						'retry_after' => $retry_after,
					)
				);
			case 'facevault_auth':
			case 'facevault_scope':
			case 'facevault_not_configured':
				return new WP_Error(
					'misconfigured',
					__( 'Identity verification is not available on this site yet.', 'facevault-identity-verification' ),
					array( 'status' => 503 )
				);
			default:
				return new WP_Error(
					'unavailable',
					__( 'Verification is temporarily unavailable. Please try again later.', 'facevault-identity-verification' ),
					array( 'status' => 503 )
				);
		}
	}

	/**
	 * Sliding transient counter. Returns false when the limit is hit.
	 *
	 * @param string $key    Transient key.
	 * @param int    $limit  Max events per window.
	 * @param int    $window Window seconds.
	 * @return bool
	 */
	private function bump( $key, $limit, $window ) {
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}
}

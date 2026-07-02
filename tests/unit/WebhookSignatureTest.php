<?php
/**
 * Webhook signature verification: HMAC-SHA256 lowercase hex over the raw
 * body, constant-time compare, rate-limited failure logging.
 *
 * @package FaceVault
 */

use FaceVault\WP\Api_Client;
use FaceVault\WP\User_Status;
use FaceVault\WP\Webhook_Controller;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase {

	const SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * @var Webhook_Controller
	 */
	private $controller;

	protected function setUp(): void {
		FV_Test_State::reset();
		FV_Test_State::$options['facevault_settings'] = array( 'webhook_secret' => self::SECRET );
		$this->controller = new Webhook_Controller( new User_Status( new Api_Client() ) );
	}

	private function sign( $body ) {
		return hash_hmac( 'sha256', $body, self::SECRET );
	}

	private function log_entries() {
		$log = get_option( 'facevault_webhook_log', array() );
		return isset( $log['entries'] ) ? $log['entries'] : array();
	}

	public function test_valid_signature_passes(): void {
		$body    = '{"event":"verification.completed","session_id":"s1"}';
		$request = new FV_Test_Request( $body, array( 'X-FaceVault-Signature' => $this->sign( $body ) ) );

		$this->assertTrue( $this->controller->verify_signature( $request ) );
	}

	public function test_uppercase_hex_signature_is_normalized(): void {
		$body    = '{"event":"verification.completed","session_id":"s1"}';
		$request = new FV_Test_Request( $body, array( 'X-FaceVault-Signature' => strtoupper( $this->sign( $body ) ) ) );

		$this->assertTrue( $this->controller->verify_signature( $request ) );
	}

	public function test_tampered_body_fails(): void {
		$body    = '{"event":"verification.completed","session_id":"s1"}';
		$request = new FV_Test_Request(
			str_replace( 's1', 's2', $body ),
			array( 'X-FaceVault-Signature' => $this->sign( $body ) )
		);

		$result = $this->controller->verify_signature( $request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'facevault_bad_signature', $result->get_error_code() );
	}

	public function test_missing_signature_fails(): void {
		$request = new FV_Test_Request( '{}' );

		$result = $this->controller->verify_signature( $request );

		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_unconfigured_secret_fails_closed(): void {
		FV_Test_State::$options['facevault_settings'] = array();
		$body    = '{}';
		$request = new FV_Test_Request( $body, array( 'X-FaceVault-Signature' => $this->sign( $body ) ) );

		$result = $this->controller->verify_signature( $request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'facevault_not_configured', $result->get_error_code() );
	}

	public function test_permission_callback_rerun_logs_once_per_request(): void {
		// WP REST re-runs permission callbacks post-dispatch to build the
		// Allow header; a single request (= single controller instance)
		// must log its failure only once.
		$request = new FV_Test_Request( '{}', array( 'X-FaceVault-Signature' => 'deadbeef' ) );

		$this->controller->verify_signature( $request );
		$this->controller->verify_signature( $request );

		$this->assertCount( 1, $this->log_entries() );
		$this->assertSame( 1, (int) get_transient( 'facevault_badsig_count' ) );
	}

	public function test_bad_signature_logging_is_rate_limited(): void {
		$request = new FV_Test_Request( '{}', array( 'X-FaceVault-Signature' => 'deadbeef' ) );

		for ( $i = 0; $i < 12; $i++ ) {
			// Fresh instance per iteration: each real request gets its own.
			$controller = new Webhook_Controller( new User_Status( new Api_Client() ) );
			$controller->verify_signature( $request );
		}

		$bad = array_filter(
			$this->log_entries(),
			static function ( $entry ) {
				return 'bad_signature' === $entry['result'];
			}
		);

		$this->assertCount( Webhook_Controller::BAD_SIG_LOG_LIMIT, $bad );
		$this->assertSame( 12, (int) get_transient( 'facevault_badsig_count' ) );
	}
}

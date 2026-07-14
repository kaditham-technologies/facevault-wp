<?php
/**
 * Checkout enforcement: classic hard block, Store API hard block, blocks
 * boundary redirect, and the review-policy matrix. All decisions must be
 * pure meta reads — no HTTP in the order path (the http_log stays empty).
 *
 * @package FaceVault
 */

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use FaceVault\WP\Api_Client;
use FaceVault\WP\Checkout_Gate;
use FaceVault\WP\Gating_Rules;
use FaceVault\WP\Render;
use FaceVault\WP\User_Status;
use PHPUnit\Framework\TestCase;

final class CheckoutGateTest extends TestCase {

	/**
	 * @var Checkout_Gate
	 */
	private $gate;

	/**
	 * @var User_Status
	 */
	private $status;

	protected function setUp(): void {
		FV_Test_State::reset();
		$this->status = new User_Status( new Api_Client() );
		$rules        = new Gating_Rules( $this->status );
		$this->gate   = new Checkout_Gate( $rules, $this->status, new Render( $this->status ) );
	}

	private function gate_cart(): void {
		FV_Test_State::$cart_items                              = array( array( 'product_id' => 10 ) );
		FV_Test_State::$post_meta[10][ Gating_Rules::META_KEY ] = 'yes';
	}

	private function log_in( $user_id, $status = null ) {
		FV_Test_State::$options['_test_current_user'] = $user_id;
		if ( null !== $status ) {
			update_user_meta( $user_id, User_Status::META_STATUS, $status );
		}
	}

	private function error_notices() {
		return array_values(
			array_filter(
				FV_Test_State::$notices,
				static function ( $notice ) {
					return 'error' === $notice[0];
				}
			)
		);
	}

	public function test_classic_blocks_unverified_user(): void {
		$this->gate_cart();
		$this->log_in( 1 );

		$this->gate->enforce_classic();

		$this->assertCount( 1, $this->error_notices() );
		$this->assertCount( 0, FV_Test_State::$http_log, 'The order path must never call the API.' );
	}

	public function test_classic_passes_verified_user(): void {
		$this->gate_cart();
		$this->log_in( 1, User_Status::STATUS_VERIFIED );

		$this->gate->enforce_classic();

		$this->assertSame( array(), FV_Test_State::$notices );
	}

	public function test_classic_blocks_guest_with_account_message(): void {
		$this->gate_cart();
		$this->log_in( 0 );

		$this->gate->enforce_classic();

		$errors = $this->error_notices();
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'log in or create an account', $errors[0][1] );
	}

	public function test_ungated_cart_is_untouched(): void {
		FV_Test_State::$cart_items = array( array( 'product_id' => 11 ) );
		$this->log_in( 1 );

		$this->gate->enforce_classic();

		$this->assertSame( array(), FV_Test_State::$notices );
	}

	public function test_review_with_hold_policy_may_order(): void {
		$this->gate_cart();
		$this->log_in( 1, User_Status::STATUS_REVIEW );

		$this->gate->enforce_classic();

		$this->assertSame( array(), FV_Test_State::$notices );
	}

	public function test_review_with_block_policy_is_blocked(): void {
		$this->gate_cart();
		$this->log_in( 1, User_Status::STATUS_REVIEW );
		FV_Test_State::$options['facevault_settings'] = array( 'review_policy' => 'block' );

		$this->gate->enforce_classic();

		$this->assertCount( 1, $this->error_notices() );
	}

	public function test_store_api_throws_for_unverified_user(): void {
		$this->gate_cart();
		$this->log_in( 1 );

		try {
			$this->gate->enforce_store_api( null, null );
			$this->fail( 'Expected RouteException.' );
		} catch ( RouteException $e ) {
			$this->assertSame( 'facevault_verification_required', $e->error_code );
			$this->assertSame( 403, $e->http_status_code );
		}
	}

	public function test_store_api_allows_verified_user(): void {
		$this->gate_cart();
		$this->log_in( 1, User_Status::STATUS_VERIFIED );

		$this->gate->enforce_store_api( null, null );

		$this->assertTrue( true ); // No exception.
	}

	public function test_blocks_checkout_redirects_to_account_tab(): void {
		$this->gate_cart();
		$this->log_in( 1 );
		FV_Test_State::$options['_test_is_checkout']         = 1;
		FV_Test_State::$options['_test_has_checkout_block']  = 1;

		try {
			$this->gate->maybe_redirect_blocks_checkout();
			$this->fail( 'Expected redirect.' );
		} catch ( FV_Test_Redirect $e ) {
			$this->assertStringContainsString( '/my-account/identity-verification/', $e->getMessage() );
		}
		$this->assertCount( 1, $this->error_notices() );
	}

	public function test_blocks_checkout_redirects_guest_to_login(): void {
		$this->gate_cart();
		$this->log_in( 0 );
		FV_Test_State::$options['_test_is_checkout']        = 1;
		FV_Test_State::$options['_test_has_checkout_block'] = 1;

		try {
			$this->gate->maybe_redirect_blocks_checkout();
			$this->fail( 'Expected redirect.' );
		} catch ( FV_Test_Redirect $e ) {
			$this->assertStringContainsString( 'wp-login.php', $e->getMessage() );
		}
	}

	public function test_no_redirect_on_classic_checkout_page(): void {
		$this->gate_cart();
		$this->log_in( 1 );
		FV_Test_State::$options['_test_is_checkout'] = 1; // No checkout block.

		$this->gate->maybe_redirect_blocks_checkout();

		$this->assertTrue( true ); // No redirect exception.
	}

	public function test_no_redirect_on_wc_endpoints(): void {
		$this->gate_cart();
		$this->log_in( 1 );
		FV_Test_State::$options['_test_is_checkout']        = 1;
		FV_Test_State::$options['_test_has_checkout_block'] = 1;
		FV_Test_State::$options['_test_is_endpoint']        = 1; // e.g. order-received.

		$this->gate->maybe_redirect_blocks_checkout();

		$this->assertTrue( true ); // No redirect exception.
	}

	public function test_checkout_notice_renders_verify_button_for_blocked_user(): void {
		$this->gate_cart();
		$this->log_in( 1 );

		ob_start();
		$this->gate->render_checkout_notice();
		$html = ob_get_clean();

		$this->assertCount( 1, $this->error_notices() );
		$this->assertStringContainsString( 'facevault-verify__button', $html );
	}

	public function test_checkout_notice_informs_review_hold(): void {
		$this->gate_cart();
		$this->log_in( 1, User_Status::STATUS_REVIEW );

		ob_start();
		$this->gate->render_checkout_notice();
		ob_end_clean();

		$this->assertCount( 1, FV_Test_State::$notices );
		$this->assertSame( 'notice', FV_Test_State::$notices[0][0] );
	}
}

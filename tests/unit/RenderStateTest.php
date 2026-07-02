<?php
/**
 * Renderer state selection: each verification status yields the right
 * surface, and redirect attributes are same-origin-validated.
 *
 * @package FaceVault
 */

use FaceVault\WP\Api_Client;
use FaceVault\WP\Render;
use FaceVault\WP\User_Status;
use PHPUnit\Framework\TestCase;

final class RenderStateTest extends TestCase {

	/**
	 * @var Render
	 */
	private $render;

	/**
	 * @var User_Status
	 */
	private $status;

	protected function setUp(): void {
		FV_Test_State::reset();
		$this->status = new User_Status( new Api_Client() );
		$this->render = new Render( $this->status );
	}

	private function login( $user_id ) {
		FV_Test_State::$options['_test_current_user'] = $user_id;
	}

	public function test_logged_out_shows_login_link(): void {
		$html = $this->render->render_button( array() );

		$this->assertStringContainsString( 'data-status="logged-out"', $html );
		$this->assertStringContainsString( 'wp-login.php', $html );
		$this->assertStringNotContainsString( 'facevault-verify__button', $html );
	}

	public function test_unverified_user_gets_button_and_attribution(): void {
		$this->login( 1 );

		$html = $this->render->render_button( array( 'label' => 'Verify my age' ) );

		$this->assertStringContainsString( 'data-status="none"', $html );
		$this->assertStringContainsString( 'Verify my age', $html );
		$this->assertStringContainsString( 'facevault-verify__attribution', $html );
	}

	public function test_attribution_can_be_disabled(): void {
		FV_Test_State::$options['facevault_settings'] = array( 'attribution' => false );
		$this->login( 1 );

		$html = $this->render->render_button( array() );

		$this->assertStringNotContainsString( 'facevault-verify__attribution', $html );
	}

	public function test_verified_user_gets_badge_not_button(): void {
		$this->login( 1 );
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'accept', 'passed' );

		$html = $this->render->render_button( array() );

		$this->assertStringContainsString( 'data-status="verified"', $html );
		$this->assertStringContainsString( 'facevault-verify__badge--verified', $html );
		$this->assertStringNotContainsString( 'facevault-verify__button', $html );
	}

	public function test_review_user_gets_review_badge(): void {
		$this->login( 1 );
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'review', 'review' );

		$html = $this->render->render_button( array() );

		$this->assertStringContainsString( 'facevault-verify__badge--review', $html );
		$this->assertStringNotContainsString( 'facevault-verify__button', $html );
	}

	public function test_rejected_user_can_retry(): void {
		$this->login( 1 );
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'reject', 'failed' );

		$html = $this->render->render_button( array() );

		$this->assertStringContainsString( 'data-status="rejected"', $html );
		$this->assertStringContainsString( 'facevault-verify__button', $html );
		$this->assertStringContainsString( 'unsuccessful', $html );
	}

	public function test_cross_origin_redirect_is_dropped(): void {
		$this->login( 1 );

		$html = $this->render->render_button( array( 'redirect' => 'https://evil.example/steal' ) );

		$this->assertStringNotContainsString( 'data-redirect', $html );
	}

	public function test_same_origin_redirect_is_kept(): void {
		$this->login( 1 );

		$html = $this->render->render_button( array( 'redirect' => '/thanks/' ) );

		$this->assertStringContainsString( 'data-redirect="/thanks/"', $html );
	}
}

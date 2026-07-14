<?php
/**
 * Manual admin override: set_manual semantics + the Users-screen wiring
 * (capability/nonce enforcement, audit trail, real-webhook precedence).
 *
 * @package FaceVault
 */

use FaceVault\WP\Admin_Users;
use FaceVault\WP\Api_Client;
use FaceVault\WP\User_Status;
use PHPUnit\Framework\TestCase;

final class ManualOverrideTest extends TestCase {

	/**
	 * @var User_Status
	 */
	private $status;

	/**
	 * @var Admin_Users
	 */
	private $admin;

	protected function setUp(): void {
		FV_Test_State::reset();
		$this->status = new User_Status( new Api_Client() );
		$this->admin  = new Admin_Users( $this->status );
		$_POST        = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	public function test_manual_verify_sets_status_and_audit_entry(): void {
		$outcome = $this->status->set_manual( 1, User_Status::STATUS_VERIFIED, 9 );

		$this->assertSame( 'applied', $outcome );
		$this->assertSame( 'verified', $this->status->get_status( 1 ) );
		$this->assertGreaterThan( 0, $this->status->get_verified_at( 1 ) );

		$history = get_user_meta( 1, User_Status::META_HISTORY, true );
		$this->assertSame( 'manual', $history[0]['source'] );
		$this->assertSame( 9, $history[0]['actor'] );

		$fired = end( FV_Test_State::$actions );
		$this->assertSame( array( 'facevault_status_changed', 1, 'verified', 'none', 'manual' ), $fired );
	}

	public function test_manual_unverify_clears_everything(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'accept', 'passed' );

		$outcome = $this->status->set_manual( 1, User_Status::STATUS_NONE, 9 );

		$this->assertSame( 'applied', $outcome );
		$this->assertSame( 'none', $this->status->get_status( 1 ) );
		$this->assertSame( 0, $this->status->get_verified_at( 1 ) );
		$this->assertSame( '', (string) get_user_meta( 1, User_Status::META_VERIFIED_SESSION, true ) );
	}

	public function test_same_status_is_duplicate(): void {
		$this->status->set_manual( 1, User_Status::STATUS_VERIFIED, 9 );

		$this->assertSame( 'duplicate', $this->status->set_manual( 1, User_Status::STATUS_VERIFIED, 9 ) );
	}

	public function test_real_webhook_reject_overrides_manual_verify(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->set_manual( 1, User_Status::STATUS_VERIFIED, 9 );

		$outcome = $this->status->apply_webhook( 's1', '1', 'reject', 'failed' );

		$this->assertSame( 'applied', $outcome );
		$this->assertSame( 'rejected', $this->status->get_status( 1 ) );
	}

	public function test_users_column_labels(): void {
		$this->assertSame( '—', $this->admin->render_column( '', 'facevault_status', 5 ) );

		$this->status->set_manual( 5, User_Status::STATUS_VERIFIED, 9 );
		$this->assertStringContainsString( 'Verified', $this->admin->render_column( '', 'facevault_status', 5 ) );

		$this->assertSame( 'x', $this->admin->render_column( 'x', 'other_column', 5 ) );
	}

	public function test_save_override_applies_with_cap_and_nonce(): void {
		FV_Test_State::$options['_test_current_user'] = 9;
		$_POST = array(
			'facevault_manual_override_nonce' => 'n',
			'facevault_manual_override'       => 'verified',
		);

		$this->admin->save_override( 5 );

		$this->assertSame( 'verified', $this->status->get_status( 5 ) );
	}

	public function test_save_override_requires_capability(): void {
		FV_Test_State::$current_can = false;
		$_POST = array(
			'facevault_manual_override_nonce' => 'n',
			'facevault_manual_override'       => 'verified',
		);

		$this->admin->save_override( 5 );

		$this->assertSame( 'none', $this->status->get_status( 5 ) );
	}

	public function test_save_override_requires_valid_nonce(): void {
		FV_Test_State::$nonce_valid = false;
		$_POST = array(
			'facevault_manual_override_nonce' => 'n',
			'facevault_manual_override'       => 'verified',
		);

		$this->admin->save_override( 5 );

		$this->assertSame( 'none', $this->status->get_status( 5 ) );
	}
}

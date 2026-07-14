<?php
/**
 * State-machine tests: transitions, idempotency, and the abuse paths
 * (reject stickiness, no external_user_id fallback, stale sessions).
 *
 * @package FaceVault
 */

use FaceVault\WP\Api_Client;
use FaceVault\WP\User_Status;
use PHPUnit\Framework\TestCase;

final class StatusMachineTest extends TestCase {

	/**
	 * @var User_Status
	 */
	private $status;

	protected function setUp(): void {
		FV_Test_State::reset();
		$this->status = new User_Status( new Api_Client() );
	}

	private function last_status_change() {
		foreach ( array_reverse( FV_Test_State::$actions ) as $action ) {
			if ( 'facevault_status_changed' === $action[0] ) {
				return $action;
			}
		}
		return null;
	}

	public function test_webhook_accept_verifies_user(): void {
		$this->status->record_mint( 1, 's1' );

		$outcome = $this->status->apply_webhook( 's1', '1', 'accept', 'passed' );

		$this->assertSame( 'applied', $outcome );
		$this->assertSame( 'verified', $this->status->get_status( 1 ) );
		$this->assertGreaterThan( 0, $this->status->get_verified_at( 1 ) );
		$this->assertSame( 's1', get_user_meta( 1, User_Status::META_VERIFIED_SESSION, true ) );

		$change = $this->last_status_change();
		$this->assertNotNull( $change );
		$this->assertSame( array( 'facevault_status_changed', 1, 'verified', 'none', 'webhook' ), $change );
	}

	public function test_duplicate_delivery_is_noop(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'accept', 'passed' );

		$outcome = $this->status->apply_webhook( 's1', '1', 'accept', 'passed' );

		$this->assertSame( 'duplicate', $outcome );
		$this->assertCount( 1, get_user_meta( 1, User_Status::META_HISTORY, true ) );
	}

	public function test_late_veto_overrides_verified(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'accept', 'passed' );

		$outcome = $this->status->apply_webhook( 's1', '1', 'reject', 'failed' );

		$this->assertSame( 'applied', $outcome );
		$this->assertSame( 'rejected', $this->status->get_status( 1 ) );
		$this->assertSame( 0, $this->status->get_verified_at( 1 ) );
		$this->assertSame( '', get_user_meta( 1, User_Status::META_VERIFIED_SESSION, true ) );
	}

	public function test_accept_replay_after_reject_is_blocked(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'accept', 'passed' );
		$this->status->apply_webhook( 's1', '1', 'reject', 'failed' );

		$outcome = $this->status->apply_webhook( 's1', '1', 'accept', 'passed' );

		$this->assertSame( 'reject_sticky', $outcome );
		$this->assertSame( 'rejected', $this->status->get_status( 1 ) );
		$this->assertSame( 0, $this->status->get_verified_at( 1 ) );
	}

	public function test_review_then_human_accept(): void {
		$this->status->record_mint( 1, 's1' );

		$this->assertSame( 'applied', $this->status->apply_webhook( 's1', '1', 'review', 'review' ) );
		$this->assertSame( 'review', $this->status->get_status( 1 ) );

		$this->assertSame( 'applied', $this->status->apply_webhook( 's1', '1', 'accept', 'passed' ) );
		$this->assertSame( 'verified', $this->status->get_status( 1 ) );
	}

	public function test_unknown_session_is_ignored(): void {
		$outcome = $this->status->apply_webhook( 'nope', '1', 'accept', 'passed' );

		$this->assertSame( 'unknown_session', $outcome );
		$this->assertSame( 'none', $this->status->get_status( 1 ) );
	}

	public function test_external_user_id_is_never_a_lookup_fallback(): void {
		// User 5 exists (has some meta) but never minted a session. A signed
		// webhook naming them via external_user_id must not verify them.
		update_user_meta( 5, 'some_meta', 'x' );

		$outcome = $this->status->apply_webhook( 'unknown-session', '5', 'accept', 'passed' );

		$this->assertSame( 'unknown_session', $outcome );
		$this->assertSame( 'none', $this->status->get_status( 5 ) );
	}

	public function test_mismatched_external_user_id_is_ignored(): void {
		$this->status->record_mint( 1, 's1' );

		$outcome = $this->status->apply_webhook( 's1', '999', 'accept', 'passed' );

		$this->assertSame( 'mismatched_user', $outcome );
		$this->assertSame( 'none', $this->status->get_status( 1 ) );
	}

	public function test_default_external_ref_is_opaque_and_stable(): void {
		$ref = $this->status->external_ref( 1 );

		// Never the enumerable raw user id; unguessable; stable per user.
		$this->assertNotSame( '1', $ref );
		$this->assertMatchesRegularExpression( '/^wp_[0-9a-f]{32}$/', $ref );
		$this->assertSame( $ref, $this->status->external_ref( 1 ) );
		$this->assertNotSame( $ref, $this->status->external_ref( 2 ) );
	}

	public function test_external_ref_filter_overrides_default(): void {
		FV_Test_State::$filters['facevault_external_user_id'] = function ( $default_ref, $user_id ) {
			return 'crm-' . $user_id;
		};

		$this->assertSame( 'crm-1', $this->status->external_ref( 1 ) );
	}

	public function test_webhook_echo_of_opaque_ref_applies(): void {
		$ref = $this->status->external_ref( 1 );
		$this->status->record_mint( 1, 's1' );

		$outcome = $this->status->apply_webhook( 's1', $ref, 'accept', 'passed' );

		$this->assertSame( 'applied', $outcome );
		$this->assertSame( 'verified', $this->status->get_status( 1 ) );
	}

	public function test_webhook_wrong_opaque_ref_is_mismatched(): void {
		$this->status->external_ref( 1 );
		$this->status->record_mint( 1, 's1' );

		$outcome = $this->status->apply_webhook( 's1', 'wp_' . str_repeat( '0', 32 ), 'accept', 'passed' );

		$this->assertSame( 'mismatched_user', $outcome );
		$this->assertSame( 'none', $this->status->get_status( 1 ) );
	}

	public function test_legacy_raw_user_id_echo_still_applies_after_ref_exists(): void {
		// A session minted by 0.1.0 echoed the raw user id; its late veto
		// must still apply after the upgrade generated an opaque ref.
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'accept', 'passed' );
		$this->status->external_ref( 1 );

		$outcome = $this->status->apply_webhook( 's1', '1', 'reject', 'failed' );

		$this->assertSame( 'applied', $outcome );
		$this->assertSame( 'rejected', $this->status->get_status( 1 ) );
	}

	public function test_superseded_session_cannot_affect_state(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->record_mint( 1, 's2' );

		// s1 is no longer referenced by any meta key (not latest, never
		// anchored), so the lookup itself refuses it.
		$outcome = $this->status->apply_webhook( 's1', '1', 'accept', 'passed' );

		$this->assertSame( 'unknown_session', $outcome );
		$this->assertSame( 'none', $this->status->get_status( 1 ) );
	}

	public function test_late_veto_still_applies_to_verified_anchor_after_remint(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'accept', 'passed' );
		$this->status->record_mint( 1, 's2' ); // User re-minted since.

		$outcome = $this->status->apply_webhook( 's1', '1', 'reject', 'failed' );

		$this->assertSame( 'applied', $outcome );
		$this->assertSame( 'rejected', $this->status->get_status( 1 ) );
		$this->assertSame( 0, $this->status->get_verified_at( 1 ) );
	}

	public function test_unrecognized_decision_and_status_are_ignored(): void {
		$this->status->record_mint( 1, 's1' );

		$outcome = $this->status->apply_webhook( 's1', '1', '', 'weird_new_state' );

		$this->assertSame( 'ignored_status', $outcome );
		$this->assertSame( 'none', $this->status->get_status( 1 ) );
	}

	public function test_decision_falls_back_to_session_status(): void {
		$this->status->record_mint( 1, 's1' );

		$outcome = $this->status->apply_webhook( 's1', '1', '', 'failed' );

		$this->assertSame( 'applied', $outcome );
		$this->assertSame( 'rejected', $this->status->get_status( 1 ) );
	}

	public function test_poll_passed_verifies(): void {
		$this->status->record_mint( 1, 's1' );

		$this->status->apply_poll_result(
			1,
			array(
				'session_id' => 's1',
				'status'     => 'passed',
			)
		);

		$this->assertSame( 'verified', $this->status->get_status( 1 ) );
	}

	public function test_poll_unknown_status_is_noop(): void {
		$this->status->record_mint( 1, 's1' );

		$outcome = $this->status->apply_poll_result(
			1,
			array(
				'session_id' => 's1',
				'status'     => 'some_future_state',
			)
		);

		$this->assertSame( 'ignored_status', $outcome );
		$this->assertSame( 'none', $this->status->get_status( 1 ) );
	}

	public function test_poll_expired_never_downgrades_verified(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'accept', 'passed' );

		$outcome = $this->status->apply_poll_result(
			1,
			array(
				'session_id' => 's1',
				'status'     => 'expired',
			)
		);

		$this->assertSame( 'ignored_status', $outcome );
		$this->assertSame( 'verified', $this->status->get_status( 1 ) );
	}

	public function test_poll_expired_resets_pending_user(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_poll_result(
			1,
			array(
				'session_id' => 's1',
				'status'     => 'in_progress',
			)
		);
		$this->assertSame( 'pending', $this->status->get_status( 1 ) );

		$this->status->apply_poll_result(
			1,
			array(
				'session_id' => 's1',
				'status'     => 'expired',
			)
		);

		$this->assertSame( 'none', $this->status->get_status( 1 ) );
	}

	public function test_maybe_poll_is_throttled(): void {
		FV_Test_State::$options['facevault_settings'] = array(
			'api_key' => 'k',
			'site_id' => 'fvs_pk_x',
		);
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_poll_result(
			1,
			array(
				'session_id' => 's1',
				'status'     => 'in_progress',
			)
		);

		FV_Test_State::$http_queue[] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'session_id' => 's1',
					'status'     => 'in_progress',
				)
			),
		);

		$this->status->maybe_poll( 1 );
		$this->status->maybe_poll( 1 ); // Second call must not hit HTTP.

		$this->assertCount( 1, FV_Test_State::$http_log );
	}

	public function test_history_is_capped(): void {
		$this->status->record_mint( 1, 's1' );
		$flip = array( 'review', 'in_progress' );
		for ( $i = 0; $i < 14; $i++ ) {
			$this->status->apply_poll_result(
				1,
				array(
					'session_id' => 's1',
					'status'     => $flip[ $i % 2 ],
				)
			);
		}

		$history = get_user_meta( 1, User_Status::META_HISTORY, true );

		$this->assertLessThanOrEqual( User_Status::HISTORY_CAP, count( $history ) );
	}
}

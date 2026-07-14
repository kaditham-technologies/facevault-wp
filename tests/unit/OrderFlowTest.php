<?php
/**
 * Order stamping and the review hold/release lifecycle, including the
 * re-hold cycle guard (release → processing must not re-trigger the hold).
 *
 * @package FaceVault
 */

use FaceVault\WP\Api_Client;
use FaceVault\WP\Gating_Rules;
use FaceVault\WP\Order_Meta;
use FaceVault\WP\User_Status;
use PHPUnit\Framework\TestCase;

final class OrderFlowTest extends TestCase {

	/**
	 * @var Order_Meta
	 */
	private $order_meta;

	/**
	 * @var User_Status
	 */
	private $status;

	protected function setUp(): void {
		FV_Test_State::reset();
		$this->status     = new User_Status( new Api_Client() );
		$this->order_meta = new Order_Meta( new Gating_Rules( $this->status ), $this->status );
	}

	public function test_stamps_verified_customer_order(): void {
		$this->status->record_mint( 1, 's1' );
		$this->status->apply_webhook( 's1', '1', 'accept', 'passed' );
		$order = new FV_Test_Order( 100, 1 );

		$this->order_meta->stamp_order( $order );

		$this->assertSame( 'verified', $order->get_meta( Order_Meta::META_STATUS_AT_PURCHASE ) );
		$this->assertSame( 's1', $order->get_meta( Order_Meta::META_SESSION ) );
		$this->assertGreaterThan( 0, (int) $order->get_meta( Order_Meta::META_VERIFIED_AT ) );
	}

	public function test_skips_orders_with_no_verification_relevance(): void {
		$order = new FV_Test_Order( 101, 2 ); // No status, ungated cart.

		$this->order_meta->stamp_order( $order );

		$this->assertSame( '', $order->get_meta( Order_Meta::META_STATUS_AT_PURCHASE ) );
	}

	public function test_stamps_guestless_gated_order_for_unverified_user(): void {
		FV_Test_State::$cart_items                              = array( array( 'product_id' => 10 ) );
		FV_Test_State::$post_meta[10][ Gating_Rules::META_KEY ] = 'yes';
		$order = new FV_Test_Order( 102, 3 );

		$this->order_meta->stamp_order( $order );

		$this->assertSame( 'none', $order->get_meta( Order_Meta::META_STATUS_AT_PURCHASE ) );
	}

	private function reviewed_order( $order_id, $user_id ) {
		$this->status->record_mint( $user_id, 's-review' );
		$this->status->apply_webhook( 's-review', (string) $user_id, 'review', 'review' );
		$order = new FV_Test_Order( $order_id, $user_id );
		$this->order_meta->stamp_order( $order );
		return $order;
	}

	public function test_review_order_is_held_on_processing(): void {
		$order = $this->reviewed_order( 103, 1 );

		$order->status = 'processing';
		$this->order_meta->maybe_hold_for_review( 103, 'pending', 'processing', $order );

		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'processing', $order->get_meta( Order_Meta::META_HOLD_RELEASE_TO ) );
		$this->assertNotEmpty( $order->notes );
	}

	public function test_accept_releases_hold_to_intended_status(): void {
		$order = $this->reviewed_order( 104, 1 );
		$order->status = 'processing';
		$this->order_meta->maybe_hold_for_review( 104, 'pending', 'processing', $order );

		$this->order_meta->on_status_changed( 1, 'verified', 'review', 'webhook' );

		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '1', $order->get_meta( Order_Meta::META_RELEASED ) );
		$this->assertSame( '', $order->get_meta( Order_Meta::META_HOLD_RELEASE_TO ) );
	}

	public function test_released_order_is_not_reheld(): void {
		$order = $this->reviewed_order( 105, 1 );
		$order->status = 'processing';
		$this->order_meta->maybe_hold_for_review( 105, 'pending', 'processing', $order );
		$this->order_meta->on_status_changed( 1, 'verified', 'review', 'webhook' );

		// The release itself re-enters 'processing' and re-fires the Woo
		// status-changed hook — the released flag must stop a second hold.
		$this->order_meta->maybe_hold_for_review( 105, 'on-hold', 'processing', $order );

		$this->assertSame( 'processing', $order->get_status() );
	}

	public function test_reject_annotates_but_keeps_hold(): void {
		$order = $this->reviewed_order( 106, 1 );
		$order->status = 'processing';
		$this->order_meta->maybe_hold_for_review( 106, 'pending', 'processing', $order );
		$notes_before = count( $order->notes );

		$this->order_meta->on_status_changed( 1, 'rejected', 'review', 'webhook' );

		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertGreaterThan( $notes_before, count( $order->notes ) );
	}

	public function test_release_only_touches_our_held_orders(): void {
		// An on-hold order with no FaceVault hold marker (merchant's own hold).
		$foreign = new FV_Test_Order( 107, 1, 'on-hold' );

		$this->order_meta->on_status_changed( 1, 'verified', 'none', 'webhook' );

		$this->assertSame( 'on-hold', $foreign->get_status() );
	}
}

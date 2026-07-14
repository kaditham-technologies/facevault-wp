<?php
/**
 * Order stamping + review-hold flow.
 *
 * Every gated (or verification-relevant) order records the shopper's
 * verification state at purchase time as order meta, via the order CRUD —
 * HPOS-safe. Orders placed while the shopper's verification is pending
 * human review are forced to on-hold and released automatically when the
 * accept webhook arrives (via the facevault_status_changed action).
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order meta writer, admin badge, and hold/release logic.
 */
class Order_Meta {

	const META_STATUS_AT_PURCHASE = '_facevault_status_at_purchase';
	const META_SESSION            = '_facevault_session_id';
	const META_VERIFIED_AT        = '_facevault_verified_at';
	const META_HOLD_RELEASE_TO    = '_facevault_hold_release_to';
	const META_RELEASED           = '_facevault_released';

	/**
	 * Statuses whose entry triggers the review hold.
	 *
	 * @var string[]
	 */
	const HOLDABLE_TARGETS = array( 'processing', 'completed' );

	/**
	 * Gating rules.
	 *
	 * @var Gating_Rules
	 */
	private $rules;

	/**
	 * Status reader.
	 *
	 * @var User_Status
	 */
	private $user_status;

	/**
	 * Constructor.
	 *
	 * @param Gating_Rules $rules       Gating rules.
	 * @param User_Status  $user_status Status reader.
	 */
	public function __construct( Gating_Rules $rules, User_Status $user_status ) {
		$this->rules       = $rules;
		$this->user_status = $user_status;
	}

	/**
	 * Hook everything.
	 */
	public function register() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_order' ) );
		// Priority 20: after the Checkout_Gate on the same hook — a blocked
		// checkout throws before stamping runs.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'stamp_order' ), 20 );
		add_action( 'add_meta_boxes', array( $this, 'add_badge_meta_box' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_hold_for_review' ), 20, 4 );
		add_action( 'facevault_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * Record the shopper's verification state on the order.
	 *
	 * @param \WC_Order $order Order being created.
	 */
	public function stamp_order( $order ) {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$status = $this->user_status->get_status( $user_id );
		if ( User_Status::STATUS_NONE === $status && ! $this->rules->cart_requires_verification() ) {
			return; // Nothing verification-related about this order.
		}

		$order->update_meta_data( self::META_STATUS_AT_PURCHASE, $status );
		$session_id = User_Status::STATUS_VERIFIED === $status
			? (string) get_user_meta( $user_id, User_Status::META_VERIFIED_SESSION, true )
			: $this->user_status->get_session_id( $user_id );
		if ( '' !== $session_id ) {
			$order->update_meta_data( self::META_SESSION, $session_id );
		}
		$verified_at = $this->user_status->get_verified_at( $user_id );
		if ( $verified_at > 0 ) {
			$order->update_meta_data( self::META_VERIFIED_AT, $verified_at );
		}
	}

	/**
	 * Force review-state orders to on-hold when they try to enter a
	 * fulfillable status. The intended status is saved so the accept
	 * webhook can restore it; the released flag stops the re-hold cycle
	 * (release → processing → this hook again).
	 *
	 * @param int       $order_id Order id.
	 * @param string    $from     Previous status (unused).
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order.
	 */
	public function maybe_hold_for_review( $order_id, $from, $to, $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! in_array( $to, self::HOLDABLE_TARGETS, true ) ) {
			return;
		}
		if ( User_Status::STATUS_REVIEW !== $order->get_meta( self::META_STATUS_AT_PURCHASE ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META_HOLD_RELEASE_TO )
			|| '' !== (string) $order->get_meta( self::META_RELEASED ) ) {
			return;
		}

		$order->update_meta_data( self::META_HOLD_RELEASE_TO, $to );
		$order->save();
		$order->update_status(
			'on-hold',
			__( 'FaceVault: customer identity verification is pending review — order held.', 'facevault-identity-verification' )
		);
	}

	/**
	 * React to verification outcomes for a customer with held orders.
	 *
	 * @param int    $user_id    User id.
	 * @param string $new_status New plugin status.
	 * @param string $old_status Previous status (unused).
	 * @param string $source     webhook|poll|manual (unused).
	 */
	public function on_status_changed( $user_id, $new_status, $old_status = '', $source = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( User_Status::STATUS_VERIFIED === $new_status ) {
			foreach ( $this->held_orders( $user_id ) as $order ) {
				$release_to = (string) $order->get_meta( self::META_HOLD_RELEASE_TO );
				$order->update_meta_data( self::META_RELEASED, '1' );
				$order->delete_meta_data( self::META_HOLD_RELEASE_TO );
				$order->save();
				$order->update_status(
					'' !== $release_to ? $release_to : 'processing',
					__( 'FaceVault: identity verification approved — hold released.', 'facevault-identity-verification' )
				);
			}
		} elseif ( User_Status::STATUS_REJECTED === $new_status ) {
			// Merchant decides what to do with the order; we only annotate.
			foreach ( $this->held_orders( $user_id ) as $order ) {
				$order->add_order_note(
					__( 'FaceVault: identity verification was rejected. Review this order before fulfilling.', 'facevault-identity-verification' )
				);
			}
		}
	}

	/**
	 * The customer's orders we put on hold (bounded).
	 *
	 * @param int $user_id User id.
	 * @return \WC_Order[]
	 */
	private function held_orders( $user_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => 'on-hold',
				'limit'       => 10,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded lookup on our own hold marker.
					array(
						'key'     => self::META_HOLD_RELEASE_TO,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Admin badge: register the meta box on both the HPOS order screen and
	 * the legacy post-type screen.
	 */
	public function add_badge_meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		add_meta_box(
			'facevault-verification',
			__( 'Identity verification', 'facevault-identity-verification' ),
			array( $this, 'render_badge_meta_box' ),
			array_unique( array_filter( $screens ) ),
			'side'
		);
	}

	/**
	 * Meta box body.
	 *
	 * @param \WC_Order|\WP_Post $post_or_order Order (HPOS) or post (legacy).
	 */
	public function render_badge_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$status = (string) $order->get_meta( self::META_STATUS_AT_PURCHASE );
		if ( '' === $status ) {
			echo '<p>' . esc_html__( 'No verification data recorded for this order.', 'facevault-identity-verification' ) . '</p>';
			return;
		}

		$labels = array(
			User_Status::STATUS_VERIFIED => __( 'Verified ✓', 'facevault-identity-verification' ),
			User_Status::STATUS_REVIEW   => __( 'Pending review', 'facevault-identity-verification' ),
			User_Status::STATUS_PENDING  => __( 'Verification in progress', 'facevault-identity-verification' ),
			User_Status::STATUS_REJECTED => __( 'Rejected', 'facevault-identity-verification' ),
		);

		echo '<p><strong>' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</strong>';
		echo ' <span class="description">' . esc_html__( '(at purchase)', 'facevault-identity-verification' ) . '</span></p>';

		$session_id = (string) $order->get_meta( self::META_SESSION );
		if ( '' !== $session_id ) {
			echo '<p><code>' . esc_html( $session_id ) . '</code></p>';
		}
		$verified_at = (int) $order->get_meta( self::META_VERIFIED_AT );
		if ( $verified_at > 0 ) {
			/* translators: %s: date. */
			echo '<p>' . esc_html( sprintf( __( 'Verified on %s', 'facevault-identity-verification' ), wp_date( get_option( 'date_format', 'Y-m-d' ), $verified_at ) ) ) . '</p>';
		}
		if ( '' !== (string) $order->get_meta( self::META_HOLD_RELEASE_TO ) ) {
			echo '<p>' . esc_html__( 'Held pending verification review.', 'facevault-identity-verification' ) . '</p>';
		}
	}
}

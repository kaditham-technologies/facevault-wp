<?php
/**
 * Checkout enforcement, three layers:
 *
 * 1. UX — a notice with the verify button (or a login prompt for guests) on
 *    the checkout page, plus a redirect to the My Account verification tab
 *    for block-based checkouts (which have no notice hooks of their own).
 * 2. Classic hard block — woocommerce_checkout_process rejects order
 *    placement server-side.
 * 3. Store API hard block — covers the Checkout Block AND headless Store
 *    API clients, so removing the front-end notice never removes the gate.
 *
 * Enforcement reads user meta only; the FaceVault API is never called in
 * the order-placement path.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart→checkout gate.
 */
class Checkout_Gate {

	/**
	 * Gating rules.
	 *
	 * @var Gating_Rules
	 */
	private $rules;

	/**
	 * Status reader (for the render-time poll).
	 *
	 * @var User_Status
	 */
	private $user_status;

	/**
	 * Shared button renderer.
	 *
	 * @var Render
	 */
	private $render;

	/**
	 * Constructor.
	 *
	 * @param Gating_Rules $rules       Gating rules.
	 * @param User_Status  $user_status Status reader.
	 * @param Render       $render      Shared renderer.
	 */
	public function __construct( Gating_Rules $rules, User_Status $user_status, Render $render ) {
		$this->rules       = $rules;
		$this->user_status = $user_status;
		$this->render      = $render;
	}

	/**
	 * Hook everything.
	 */
	public function register() {
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_notice' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'enforce_classic' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_blocks_checkout' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'enforce_store_api' ), 10, 2 );
	}

	/**
	 * The gate decision for the current shopper + live cart.
	 *
	 * @param bool $fresh Also run the throttled status poll first (render
	 *                    paths only — never in the order-placement path).
	 * @return string|null Gating_Rules verdict, or null when the cart is not
	 *                     gated (nothing to enforce).
	 */
	private function verdict( $fresh = false ) {
		if ( ! $this->rules->cart_requires_verification() ) {
			return null;
		}
		$user_id = get_current_user_id();
		if ( $fresh && $user_id > 0 ) {
			$this->user_status->maybe_poll( $user_id );
		}
		return $this->rules->user_may_checkout( $user_id );
	}

	/**
	 * Whether a verdict means the order may proceed.
	 *
	 * @param string $verdict Gating_Rules verdict.
	 * @return bool
	 */
	private function verdict_allows_order( $verdict ) {
		if ( Gating_Rules::VERDICT_PASS === $verdict ) {
			return true;
		}
		return Gating_Rules::VERDICT_REVIEW === $verdict && 'hold' === Settings::get( 'review_policy' );
	}

	/**
	 * Human message for a blocking verdict.
	 *
	 * @param string $verdict Gating_Rules verdict.
	 * @return string
	 */
	private function block_message( $verdict ) {
		if ( Gating_Rules::VERDICT_GUEST === $verdict ) {
			return __( 'This order contains items that require identity verification. Please log in or create an account so you can verify your identity.', 'facevault-identity-verification' );
		}
		if ( Gating_Rules::VERDICT_REVIEW === $verdict ) {
			return __( 'Your identity verification is pending review. You can check out once it has been approved.', 'facevault-identity-verification' );
		}
		return __( 'This order contains items that require identity verification. Please verify your identity to continue.', 'facevault-identity-verification' );
	}

	/**
	 * Layer 1 (classic checkout): notice + inline verify button.
	 */
	public function render_checkout_notice() {
		$verdict = $this->verdict( true );
		if ( null === $verdict ) {
			return;
		}

		if ( $this->verdict_allows_order( $verdict ) ) {
			if ( Gating_Rules::VERDICT_REVIEW === $verdict ) {
				wc_print_notice(
					esc_html__( 'Your identity verification is pending review — your order will be placed on hold until it is approved.', 'facevault-identity-verification' ),
					'notice'
				);
			}
			return;
		}

		wc_print_notice( esc_html( $this->block_message( $verdict ) ), 'error' );

		if ( Gating_Rules::VERDICT_GUEST !== $verdict && Gating_Rules::VERDICT_REVIEW !== $verdict ) {
			// Renderer output is built from escaped parts.
			echo $this->render->render_button( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Layer 2: classic checkout server-side block.
	 */
	public function enforce_classic() {
		$verdict = $this->verdict();
		if ( null === $verdict || $this->verdict_allows_order( $verdict ) ) {
			return;
		}
		wc_add_notice( $this->block_message( $verdict ), 'error' );
	}

	/**
	 * Layer 3: Store API block (Checkout Block + headless clients).
	 *
	 * @param \WC_Order        $order   Order being placed (unused).
	 * @param \WP_REST_Request $request Request (unused).
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the gate fails.
	 */
	public function enforce_store_api( $order, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		$verdict = $this->verdict();
		if ( null === $verdict || $this->verdict_allows_order( $verdict ) ) {
			return;
		}
		if ( ! class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			return; // Very old Woo without the Store API — classic hook covers it.
		}
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'facevault_verification_required',
			esc_html( $this->block_message( $verdict ) ),
			403
		);
	}

	/**
	 * Layer 1 (Checkout Block): the block has no notice hook, so gate at the
	 * page boundary — send the shopper to the My Account verification tab
	 * (or login for guests). The Store API layer remains the hard stop.
	 */
	public function maybe_redirect_blocks_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );
		if ( $checkout_page_id <= 0 || ! has_block( 'woocommerce/checkout', $checkout_page_id ) ) {
			return; // Classic checkout: the inline notice handles UX.
		}

		$verdict = $this->verdict( true );
		if ( null === $verdict || $this->verdict_allows_order( $verdict ) ) {
			return;
		}

		wc_add_notice( $this->block_message( $verdict ), 'error' );

		if ( Gating_Rules::VERDICT_GUEST === $verdict ) {
			$target = wp_login_url( wc_get_checkout_url() );
		} else {
			$target = wc_get_account_endpoint_url( Account_Tab::ENDPOINT );
		}
		if ( ! $target ) {
			return; // No sane destination — the Store API layer still blocks.
		}
		wp_safe_redirect( $target );
		exit;
	}
}

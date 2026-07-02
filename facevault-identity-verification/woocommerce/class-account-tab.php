<?php
/**
 * WooCommerce My Account → Identity Verification tab. Loaded only when
 * WooCommerce is active.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the account endpoint and renders the shared button/badge.
 */
class Account_Tab {

	const ENDPOINT = 'identity-verification';

	/**
	 * Shared renderer.
	 *
	 * @var Render
	 */
	private $render;

	/**
	 * Constructor.
	 *
	 * @param Render $render Shared renderer.
	 */
	public function __construct( Render $render ) {
		$this->render = $render;
	}

	/**
	 * Hook everything.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
	}

	/**
	 * Register the rewrite endpoint. The versioned option makes the flush
	 * survive every ordering: plugin activated before/after WooCommerce,
	 * plugin updated in place.
	 */
	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		if ( FACEVAULT_VERSION !== get_option( 'facevault_rewrite_flushed' ) ) {
			flush_rewrite_rules();
			update_option( 'facevault_rewrite_flushed', FACEVAULT_VERSION );
		}
	}

	/**
	 * Let WooCommerce resolve the endpoint.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Insert the tab before Logout.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function add_menu_item( $items ) {
		$logout = null;
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}
		$items[ self::ENDPOINT ] = __( 'Identity Verification', 'facevault-identity-verification' );
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * Tab title.
	 *
	 * @return string
	 */
	public function endpoint_title() {
		return __( 'Identity Verification', 'facevault-identity-verification' );
	}

	/**
	 * Tab content: shared renderer + the privacy line merchants get asked
	 * about most.
	 */
	public function render_endpoint() {
		echo '<h3>' . esc_html__( 'Identity Verification', 'facevault-identity-verification' ) . '</h3>';
		echo $this->render->render_button( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output is built from escaped parts.
		echo '<p class="facevault-verify__privacy">' . esc_html__( 'Your documents are processed securely by FaceVault and are never stored on this store.', 'facevault-identity-verification' ) . '</p>';
	}
}

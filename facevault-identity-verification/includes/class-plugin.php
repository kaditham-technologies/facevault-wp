<?php
/**
 * Plugin bootstrap: instantiates and wires all components.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Component registry. Each component exposes a register() method that adds
 * its hooks; nothing hooks anything from a constructor.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings screen component.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * FaceVault API HTTP client.
	 *
	 * @var Api_Client
	 */
	public $api_client;

	/**
	 * User verification-status state machine.
	 *
	 * @var User_Status
	 */
	public $user_status;

	/**
	 * Shared button/badge renderer (shortcode + block + account tab).
	 *
	 * @var Render
	 */
	public $render;

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire and register every component. Runs on plugins_loaded.
	 */
	public function register() {
		$this->settings    = new Settings();
		$this->api_client  = new Api_Client();
		$this->user_status = new User_Status( $this->api_client );
		$this->render      = new Render( $this->user_status );

		$components = array(
			$this->settings,
			$this->render,
			new Token_Controller( $this->api_client, $this->user_status ),
			new Webhook_Controller( $this->user_status ),
			new Admin_Notices(),
		);

		foreach ( $components as $component ) {
			$component->register();
		}

		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		if ( class_exists( 'WooCommerce' ) ) {
			require FACEVAULT_PLUGIN_DIR . 'woocommerce/class-account-tab.php';
			( new Account_Tab( $this->render ) )->register();
		}
	}

	/**
	 * Declare HPOS (custom order tables) compatibility so the upcoming
	 * WooCommerce order-meta features have it from day one.
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				FACEVAULT_PLUGIN_FILE,
				true
			);
		}
	}

	/**
	 * Activation: seed defaults and force a rewrite flush on the next boot
	 * (the account-tab endpoint registers on init, so we can't flush here).
	 */
	public static function activate() {
		if ( false === get_option( 'facevault_settings' ) ) {
			add_option( 'facevault_settings', Settings::defaults() );
		}
		add_option( 'facevault_db_version', FACEVAULT_VERSION );
		delete_option( 'facevault_rewrite_flushed' );
	}
}

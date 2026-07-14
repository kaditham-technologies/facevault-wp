<?php
/**
 * Plugin Name:       FaceVault Identity Verification
 * Plugin URI:        https://github.com/kaditham-technologies/facevault-wp
 * Description:       Identity verification (ID document + selfie + liveness) for WordPress and WooCommerce via FaceVault. Documents never touch your server.
 * Version:           0.1.1
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            FaceVault
 * Author URI:        https://facevault.id
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       facevault-identity-verification
 *
 * @package FaceVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FACEVAULT_VERSION', '0.1.1' );
define( 'FACEVAULT_PLUGIN_FILE', __FILE__ );
define( 'FACEVAULT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FACEVAULT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require FACEVAULT_PLUGIN_DIR . 'includes/class-plugin.php';
require FACEVAULT_PLUGIN_DIR . 'includes/class-settings.php';
require FACEVAULT_PLUGIN_DIR . 'includes/class-api-client.php';
require FACEVAULT_PLUGIN_DIR . 'includes/class-user-status.php';
require FACEVAULT_PLUGIN_DIR . 'includes/class-token-controller.php';
require FACEVAULT_PLUGIN_DIR . 'includes/class-webhook-controller.php';
require FACEVAULT_PLUGIN_DIR . 'includes/class-render.php';
require FACEVAULT_PLUGIN_DIR . 'includes/class-admin-notices.php';
require FACEVAULT_PLUGIN_DIR . 'includes/class-admin-users.php';

register_activation_hook( __FILE__, array( 'FaceVault\\WP\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/**
 * Boot the plugin once all plugins are loaded (so WooCommerce detection works).
 */
function facevault_load() {
	\FaceVault\WP\Plugin::instance()->register();
}
add_action( 'plugins_loaded', 'facevault_load' );

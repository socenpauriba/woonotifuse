<?php
/**
 * Plugin Name:       WooNotifuse
 * Plugin URI:        https://github.com/socenpauriba/woonotifuse
 * Description:       Connect WooCommerce to Notifuse for transactional emails and newsletter automation.
 * Version:           0.4.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Pau Riba
 * Author URI:        https://nuvol.cat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woonotifuse
 * Domain Path:       /languages
 *
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 *
 * @package WooNotifuse
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants.
// ---------------------------------------------------------------------------

define( 'WOONOTIFUSE_VERSION', '0.4.1' );
define( 'WOONOTIFUSE_PLUGIN_FILE', __FILE__ );
define( 'WOONOTIFUSE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOONOTIFUSE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WOONOTIFUSE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Minimum supported WooCommerce version.
define( 'WOONOTIFUSE_MIN_WC_VERSION', '7.0' );

// ---------------------------------------------------------------------------
// Autoloader (PSR-4, namespace WooNotifuse\ -> includes/).
// ---------------------------------------------------------------------------

require_once WOONOTIFUSE_PLUGIN_DIR . 'includes/class-autoloader.php';
\WooNotifuse\Autoloader::register();

// ---------------------------------------------------------------------------
// Update checker — serves new versions from GitHub releases on the WordPress
// Plugins screen. To ship an update, publish a GitHub release whose source has
// a higher "Version" header. Set up independently of WooCommerce so updates
// work even when the dependency check fails.
// ---------------------------------------------------------------------------

/**
 * Wire the GitHub release-based update checker, if the bundled library exists.
 */
function woonotifuse_init_update_checker() {
	$loader = WOONOTIFUSE_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

	if ( ! is_readable( $loader ) ) {
		return;
	}

	require_once $loader;

	$factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';

	if ( ! class_exists( $factory ) ) {
		return;
	}

	// GitHub releases mode: PUC checks releases first, then tags. No branch is
	// set, so it tracks published versions rather than a moving branch.
	$factory::buildUpdateChecker(
		'https://github.com/socenpauriba/woonotifuse/',
		WOONOTIFUSE_PLUGIN_FILE,
		'woonotifuse'
	);
}

woonotifuse_init_update_checker();

// ---------------------------------------------------------------------------
// HPOS (High-Performance Order Storage) compatibility declaration.
// ---------------------------------------------------------------------------

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WOONOTIFUSE_PLUGIN_FILE,
				true
			);
		}
	}
);

// ---------------------------------------------------------------------------
// Activation / deactivation hooks.
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, array( '\WooNotifuse\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\WooNotifuse\Deactivator', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Bootstrap.
// ---------------------------------------------------------------------------

/**
 * Initialise the plugin once all plugins are loaded.
 *
 * WooCommerce presence is checked first; if it's missing or too old we bail
 * out with an admin notice instead of fatally erroring.
 */
function woonotifuse() {
	return \WooNotifuse\Plugin::instance();
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! \WooNotifuse\Dependencies::is_woocommerce_ready() ) {
			add_action( 'admin_notices', array( '\WooNotifuse\Dependencies', 'render_notice' ) );
			return;
		}

		woonotifuse()->init();
	},
	20
);

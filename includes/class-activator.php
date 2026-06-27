<?php
/**
 * Runs on plugin activation.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

defined( 'ABSPATH' ) || exit;

/**
 * Activation routine.
 */
class Activator {

	/**
	 * Activate the plugin.
	 *
	 * Refuses activation if WooCommerce isn't available, so the site never
	 * ends up in a half-configured state.
	 */
	public static function activate() {
		if ( ! Dependencies::is_woocommerce_ready() ) {
			deactivate_plugins( WOONOTIFUSE_PLUGIN_BASENAME );

			wp_die(
				esc_html__( 'WooNotifuse requires an active, supported version of WooCommerce.', 'woonotifuse' ),
				esc_html__( 'Plugin activation failed', 'woonotifuse' ),
				array( 'back_link' => true )
			);
		}

		// Record the installed version for future migrations.
		add_option( 'woonotifuse_version', WOONOTIFUSE_VERSION );

		flush_rewrite_rules();
	}
}

<?php
/**
 * Dependency checks (WooCommerce presence and version).
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies that the environment WooNotifuse needs is available.
 */
class Dependencies {

	/**
	 * Whether WooCommerce is active and meets the minimum version.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_ready() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WOONOTIFUSE_MIN_WC_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Render an admin notice explaining why the plugin is inactive.
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$message = sprintf(
				/* translators: %s: WooCommerce plugin name. */
				esc_html__( 'WooNotifuse requires %s to be installed and active.', 'woonotifuse' ),
				'<strong>WooCommerce</strong>'
			);
		} else {
			$message = sprintf(
				/* translators: 1: minimum WooCommerce version. */
				esc_html__( 'WooNotifuse requires WooCommerce version %1$s or higher.', 'woonotifuse' ),
				esc_html( WOONOTIFUSE_MIN_WC_VERSION )
			);
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses_post( $message )
		);
	}
}

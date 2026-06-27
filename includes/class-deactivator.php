<?php
/**
 * Runs on plugin deactivation.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation routine.
 */
class Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * Kept intentionally light — persistent data is removed in uninstall.php,
	 * not here, so deactivating doesn't destroy configuration.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}

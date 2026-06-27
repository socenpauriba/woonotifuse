<?php
/**
 * Uninstall handler — runs when the plugin is deleted from wp-admin.
 *
 * @package WooNotifuse
 */

// Exit if accessed directly or not invoked by WordPress uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove stored options.
delete_option( 'woonotifuse_version' );
delete_option( 'woonotifuse_settings' );

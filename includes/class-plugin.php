<?php
/**
 * Main plugin orchestrator.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires up the plugin's moving parts.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the shared instance.
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
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Boot the plugin. Safe to call once; subsequent calls are ignored.
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->load_textdomain();
		$this->register_admin();
		$this->register_order_sync();

		/**
		 * Fires once WooNotifuse has booted and WooCommerce is confirmed ready.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'woonotifuse_init', $this );
	}

	/**
	 * Wire up admin-only features (settings page, connection test).
	 */
	private function register_admin() {
		if ( is_admin() ) {
			( new Settings() )->init();
			( new Order_Actions() )->init();
		}
	}

	/**
	 * Wire up the order → Notifuse contact sync.
	 *
	 * Registered unconditionally (not admin-only): orders can become paid from
	 * the checkout, the admin, webhooks or cron.
	 */
	private function register_order_sync() {
		( new Order_Sync() )->init();
	}

	/**
	 * Load translations.
	 */
	private function load_textdomain() {
		load_plugin_textdomain(
			'woonotifuse',
			false,
			dirname( WOONOTIFUSE_PLUGIN_BASENAME ) . '/languages'
		);
	}
}

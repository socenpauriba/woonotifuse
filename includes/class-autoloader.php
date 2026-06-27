<?php
/**
 * PSR-4 style autoloader for the WooNotifuse namespace.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

defined( 'ABSPATH' ) || exit;

/**
 * Maps class names under the WooNotifuse\ namespace to files in includes/.
 *
 * Example: WooNotifuse\Api\Client -> includes/api/class-client.php
 */
class Autoloader {

	/**
	 * Register the autoloader with SPL.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolve and load a class file.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public static function autoload( $class ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', '/', $relative );

		$parts = explode( '/', $relative );
		$class_name = array_pop( $parts );

		// WordPress file naming: class-{lowercase-hyphenated}.php.
		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		$sub_path = empty( $parts ) ? '' : strtolower( implode( '/', $parts ) ) . '/';

		$path = WOONOTIFUSE_PLUGIN_DIR . 'includes/' . $sub_path . $file_name;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}

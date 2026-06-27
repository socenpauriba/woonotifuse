<?php
/**
 * Settings storage and admin settings page.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

use WooNotifuse\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the plugin's stored configuration and its admin UI.
 *
 * Config lives in a single option (woonotifuse_settings) holding the Notifuse
 * domain, API token and workspace ID. A "Test connection" button performs a
 * live, authenticated call to confirm the credentials work.
 */
class Settings {

	const OPTION_KEY     = 'woonotifuse_settings';
	const OPTION_GROUP   = 'woonotifuse_settings_group';
	const PAGE_SLUG      = 'woonotifuse-settings';
	const TEST_AJAX_HOOK = 'woonotifuse_test_connection';

	/**
	 * Custom-field mapping sub-controller.
	 *
	 * @var Field_Mappings
	 */
	private $mappings;

	/**
	 * Register admin hooks.
	 */
	public function init() {
		$this->mappings = new Field_Mappings();
		$this->mappings->init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_' . self::TEST_AJAX_HOOK, array( $this, 'ajax_test_connection' ) );
		add_filter(
			'plugin_action_links_' . WOONOTIFUSE_PLUGIN_BASENAME,
			array( $this, 'add_settings_link' )
		);
	}

	// -----------------------------------------------------------------------
	// Storage / accessors.
	// -----------------------------------------------------------------------

	/**
	 * Get all settings with defaults applied.
	 *
	 * @return array{domain:string,token:string,workspace_id:string,auto_sync:bool}
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'domain'       => '',
				'token'        => '',
				'workspace_id' => '',
				'auto_sync'    => false,
			)
		);
	}

	/**
	 * Whether orders should be synced automatically when they become paid.
	 *
	 * Manual bulk / order actions are unaffected by this setting.
	 *
	 * @return bool
	 */
	public static function auto_sync_enabled() {
		return (bool) self::get( 'auto_sync', false );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if unset.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Build a configured API client from the stored settings.
	 *
	 * @return Client
	 */
	public static function make_client() {
		$s = self::all();

		return new Client( $s['domain'], $s['token'], $s['workspace_id'] );
	}

	// -----------------------------------------------------------------------
	// Admin menu + Settings API.
	// -----------------------------------------------------------------------

	/**
	 * Add the settings page under the WooCommerce menu.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'WooNotifuse', 'woonotifuse' ),
			__( 'WooNotifuse', 'woonotifuse' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option and its fields with the Settings API.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'woonotifuse_connection',
			__( 'Notifuse connection', 'woonotifuse' ),
			function () {
				echo '<p>' . esc_html__( 'Enter your Notifuse credentials. You can find these in your Notifuse workspace settings.', 'woonotifuse' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$fields = array(
			'domain'       => array(
				'label'       => __( 'Notifuse domain', 'woonotifuse' ),
				'type'        => 'text',
				'placeholder' => 'your-workspace.notifuse.com',
				'description' => __( 'Your Notifuse domain, without https:// (e.g. demo.notifuse.com).', 'woonotifuse' ),
			),
			'workspace_id' => array(
				'label'       => __( 'Workspace ID', 'woonotifuse' ),
				'type'        => 'text',
				'placeholder' => 'ws_1234567890',
				'description' => __( 'The workspace these requests apply to.', 'woonotifuse' ),
			),
			'token'        => array(
				'label'       => __( 'API token', 'woonotifuse' ),
				'type'        => 'password',
				'placeholder' => '',
				'description' => __( 'Bearer token used to authenticate API requests. Stored in your database.', 'woonotifuse' ),
			),
		);

		foreach ( $fields as $key => $field ) {
			add_settings_field(
				'woonotifuse_' . $key,
				$field['label'],
				array( $this, 'render_field' ),
				self::PAGE_SLUG,
				'woonotifuse_connection',
				array_merge( $field, array( 'key' => $key, 'label_for' => 'woonotifuse_' . $key ) )
			);
		}

		add_settings_section(
			'woonotifuse_sync',
			__( 'Synchronization', 'woonotifuse' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'woonotifuse_auto_sync',
			__( 'Automatic sync', 'woonotifuse' ),
			array( $this, 'render_field' ),
			self::PAGE_SLUG,
			'woonotifuse_sync',
			array(
				'key'            => 'auto_sync',
				'type'           => 'checkbox',
				'checkbox_label' => __( 'Automatically sync a customer to Notifuse when their order is paid.', 'woonotifuse' ),
				'description'    => __( 'When off, orders are only synced via the manual bulk action or the order action.', 'woonotifuse' ),
				'label_for'      => 'woonotifuse_auto_sync',
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * Token is preserved if the field is submitted empty, so saving other
	 * fields doesn't wipe a previously-stored secret.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::all();

		$domain = isset( $input['domain'] ) ? sanitize_text_field( $input['domain'] ) : '';
		// Strip scheme/path defensively; the client also normalizes.
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = preg_replace( '#/.*$#', '', trim( $domain ) );

		$token = isset( $input['token'] ) ? trim( $input['token'] ) : '';
		if ( '' === $token ) {
			$token = $current['token'];
		}

		return array(
			'domain'       => $domain,
			'workspace_id' => isset( $input['workspace_id'] ) ? sanitize_text_field( $input['workspace_id'] ) : '',
			'token'        => sanitize_text_field( $token ),
			'auto_sync'    => ! empty( $input['auto_sync'] ),
		);
	}

	// -----------------------------------------------------------------------
	// Rendering.
	// -----------------------------------------------------------------------

	/**
	 * Render a single settings field.
	 *
	 * @param array $args Field args.
	 */
	public function render_field( $args ) {
		$key   = $args['key'];
		$value = self::get( $key );
		$id    = 'woonotifuse_' . $key;
		$name  = self::OPTION_KEY . '[' . $key . ']';

		// Checkboxes render as a labelled toggle storing "1" when enabled.
		if ( 'checkbox' === $args['type'] ) {
			printf(
				'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( (bool) $value, true, false ),
				esc_html( isset( $args['checkbox_label'] ) ? $args['checkbox_label'] : '' )
			);

			if ( ! empty( $args['description'] ) ) {
				printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
			}

			return;
		}

		// Never echo the stored token back; show a placeholder if one exists.
		$display = ( 'token' === $key ) ? '' : $value;
		$ph      = ( 'token' === $key && '' !== $value )
			? __( '•••••••• (leave blank to keep current)', 'woonotifuse' )
			: ( isset( $args['placeholder'] ) ? $args['placeholder'] : '' );

		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" placeholder="%5$s" class="regular-text" autocomplete="off" />',
			esc_attr( $args['type'] ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $display ),
			esc_attr( $ph )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render the settings page wrapper.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$nonce = wp_create_nonce( self::TEST_AJAX_HOOK );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WooNotifuse', 'woonotifuse' ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr />

			<?php $this->mappings->render_form(); ?>

			<hr />

			<h2><?php esc_html_e( 'Connection test', 'woonotifuse' ); ?></h2>
			<p><?php esc_html_e( 'Verify the saved credentials by making a live request to Notifuse.', 'woonotifuse' ); ?></p>
			<p>
				<button type="button" class="button button-secondary" id="woonotifuse-test-connection">
					<?php esc_html_e( 'Test connection', 'woonotifuse' ); ?>
				</button>
				<span id="woonotifuse-test-result" style="margin-left:8px;"></span>
			</p>

			<script>
			( function () {
				var btn = document.getElementById( 'woonotifuse-test-connection' );
				var out = document.getElementById( 'woonotifuse-test-result' );
				if ( ! btn ) { return; }

				btn.addEventListener( 'click', function () {
					btn.disabled = true;
					out.textContent = <?php echo wp_json_encode( __( 'Testing…', 'woonotifuse' ) ); ?>;
					out.style.color = '';

					var body = new URLSearchParams();
					body.append( 'action', <?php echo wp_json_encode( self::TEST_AJAX_HOOK ); ?> );
					body.append( 'nonce', <?php echo wp_json_encode( $nonce ); ?> );

					fetch( ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString()
					} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						out.textContent = res.data && res.data.message ? res.data.message : '';
						out.style.color = res.success ? '#1a7f37' : '#d63638';
					} )
					.catch( function () {
						out.textContent = <?php echo wp_json_encode( __( 'Request failed.', 'woonotifuse' ) ); ?>;
						out.style.color = '#d63638';
					} )
					.finally( function () { btn.disabled = false; } );
				} );
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'woonotifuse' ) . '</a>';
		array_unshift( $links, $link );

		return $links;
	}

	// -----------------------------------------------------------------------
	// AJAX: connection test.
	// -----------------------------------------------------------------------

	/**
	 * Handle the "Test connection" AJAX request.
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woonotifuse' ) ), 403 );
		}

		check_ajax_referer( self::TEST_AJAX_HOOK, 'nonce' );

		$client = self::make_client();

		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a domain and API token first.', 'woonotifuse' ) ) );
		}

		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connection successful.', 'woonotifuse' ) ) );
	}
}

<?php
/**
 * Optional custom-field mappings: configuration storage and admin UI.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the admin map up to four computed WooCommerce values onto custom fields
 * of their Notifuse install. Each mapping can be enabled independently and
 * pointed at any of Notifuse's custom_{number,datetime,string}_1..5 fields.
 *
 * Stored in its own option (woonotifuse_field_mappings). The actual value
 * computation lives in {@see Field_Resolver}; this class only owns the config.
 */
class Field_Mappings {

	const OPTION_KEY   = 'woonotifuse_field_mappings';
	const OPTION_GROUP = 'woonotifuse_field_mappings_group';

	/**
	 * Preferred-language source modes.
	 */
	const LANG_MODE_FIXED   = 'fixed';
	const LANG_MODE_WPML    = 'wpml';
	const LANG_MODE_ZIPCODE = 'zipcode';
	const LANG_MODE_STATE   = 'state';

	/**
	 * Canonical definition of the available mappings.
	 *
	 * `type` drives which Notifuse custom fields are offered as targets.
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		return array(
			'order_count'    => array(
				'label'       => __( 'Number of orders', 'woonotifuse' ),
				'type'        => 'number',
				'description' => __( "The customer's lifetime number of orders, read from WooCommerce.", 'woonotifuse' ),
			),
			'total_spent'    => array(
				'label'       => __( 'Total spent', 'woonotifuse' ),
				'type'        => 'number',
				'description' => __( "The customer's lifetime total spent, read from WooCommerce.", 'woonotifuse' ),
			),
			'last_purchase'  => array(
				'label'       => __( 'Last purchase date', 'woonotifuse' ),
				'type'        => 'datetime',
				'description' => __( 'The date of the order that triggered the sync (ISO 8601).', 'woonotifuse' ),
			),
			'first_order_date' => array(
				'label'       => __( 'First order date', 'woonotifuse' ),
				'type'        => 'datetime',
				'description' => __( "The date of the customer's earliest order, read from WooCommerce (ISO 8601).", 'woonotifuse' ),
			),
			'preferred_lang' => array(
				'label'       => __( 'Preferred language', 'woonotifuse' ),
				'type'        => 'string',
				'description' => __( "A language code, resolved from one of several sources (see below). Written to the contact's built-in Language field.", 'woonotifuse' ),
				'has_modes'   => true,
				// Writes to Notifuse's native contact field instead of a custom one.
				'native'      => 'language',
			),
		);
	}

	/**
	 * Register the setting with the Settings API.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the option and its sanitizer.
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
	}

	// -----------------------------------------------------------------------
	// Storage / accessors.
	// -----------------------------------------------------------------------

	/**
	 * All mapping config, with a defaulted entry for every defined field.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$out = array();
		foreach ( self::definitions() as $key => $def ) {
			$out[ $key ] = wp_parse_args(
				isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array(),
				self::field_defaults( $key, $def )
			);
		}

		return $out;
	}

	/**
	 * Config for a single mapping.
	 *
	 * @param string $key Field key.
	 * @return array
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : array();
	}

	/**
	 * Default config for one field.
	 *
	 * @param string $key Field key.
	 * @param array  $def Field definition.
	 * @return array
	 */
	private static function field_defaults( $key, $def ) {
		$defaults = array(
			'enabled' => false,
			'target'  => '',
		);

		if ( ! empty( $def['has_modes'] ) ) {
			$defaults['mode']             = self::LANG_MODE_FIXED;
			$defaults['fixed_value']      = '';
			$defaults['zipcode_rules']    = '';
			$defaults['zipcode_fallback'] = '';
			$defaults['state_rules']      = '';
			$defaults['state_fallback']   = '';
		}

		return $defaults;
	}

	/**
	 * Notifuse custom-field choices for a given value type.
	 *
	 * @param string $type One of number|datetime|string.
	 * @return array<string,string> field key => human label.
	 */
	public static function notifuse_field_choices( $type ) {
		$prefixes = array(
			'number'   => 'custom_number_',
			'datetime' => 'custom_datetime_',
			'string'   => 'custom_string_',
		);
		$prefix = isset( $prefixes[ $type ] ) ? $prefixes[ $type ] : 'custom_string_';

		$choices = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$choices[ $prefix . $i ] = $prefix . $i;
		}

		return $choices;
	}

	// -----------------------------------------------------------------------
	// Sanitization.
	// -----------------------------------------------------------------------

	/**
	 * Sanitize submitted mapping config.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		foreach ( self::definitions() as $key => $def ) {
			$raw   = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$entry = array(
				'enabled' => ! empty( $raw['enabled'] ),
				'target'  => '',
			);

			// Native-target mappings write to a built-in contact field, so there
			// is no custom-field target to validate. Otherwise, validate the
			// chosen target against the allowed set for this field type.
			if ( empty( $def['native'] ) ) {
				$allowed = self::notifuse_field_choices( $def['type'] );
				if ( isset( $raw['target'] ) && isset( $allowed[ $raw['target'] ] ) ) {
					$entry['target'] = $raw['target'];
				}
			}

			if ( ! empty( $def['has_modes'] ) ) {
				$mode               = isset( $raw['mode'] ) ? sanitize_key( $raw['mode'] ) : self::LANG_MODE_FIXED;
				$entry['mode']      = in_array( $mode, self::lang_modes(), true ) ? $mode : self::LANG_MODE_FIXED;
				$entry['fixed_value']      = isset( $raw['fixed_value'] ) ? sanitize_text_field( $raw['fixed_value'] ) : '';
				$entry['zipcode_fallback'] = isset( $raw['zipcode_fallback'] ) ? sanitize_text_field( $raw['zipcode_fallback'] ) : '';
				$entry['state_fallback']   = isset( $raw['state_fallback'] ) ? sanitize_text_field( $raw['state_fallback'] ) : '';
				// Keep newlines for the rules textareas.
				$entry['zipcode_rules'] = isset( $raw['zipcode_rules'] )
					? sanitize_textarea_field( $raw['zipcode_rules'] )
					: '';
				$entry['state_rules'] = isset( $raw['state_rules'] )
					? sanitize_textarea_field( $raw['state_rules'] )
					: '';
			}

			$clean[ $key ] = $entry;
		}

		return $clean;
	}

	/**
	 * Valid preferred-language modes.
	 *
	 * @return string[]
	 */
	public static function lang_modes() {
		return array( self::LANG_MODE_FIXED, self::LANG_MODE_WPML, self::LANG_MODE_ZIPCODE, self::LANG_MODE_STATE );
	}

	/**
	 * Whether a multilingual plugin that powers the WPML mode is available.
	 *
	 * Detects WPML (and the Polylang WPML-compatibility layer, which also
	 * populates the `wpml_language` order meta and the `wpml_current_language`
	 * filter the resolver relies on). When false, the WPML language mode has no
	 * data source and must not be used.
	 *
	 * @return bool
	 */
	public static function is_wpml_active() {
		return class_exists( 'SitePress' )
			|| defined( 'ICL_LANGUAGE_CODE' )
			|| function_exists( 'pll_current_language' );
	}

	// -----------------------------------------------------------------------
	// Rendering (called from the Settings page).
	// -----------------------------------------------------------------------

	/**
	 * Render the field-mapping form.
	 */
	public function render_form() {
		$config = self::all();
		?>
		<h2><?php esc_html_e( 'Custom field sync', 'woonotifuse' ); ?></h2>
		<p>
			<?php esc_html_e( 'Optionally sync computed WooCommerce values onto custom fields of your Notifuse contacts. Enable only what you need and pick the Notifuse field each one writes to.', 'woonotifuse' ); ?>
		</p>

		<form action="options.php" method="post">
			<?php settings_fields( self::OPTION_GROUP ); ?>

			<?php foreach ( self::definitions() as $key => $def ) : ?>
				<?php $entry = $config[ $key ]; ?>
				<h3><?php echo esc_html( $def['label'] ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Sync', 'woonotifuse' ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									name="<?php echo esc_attr( self::field_name( $key, 'enabled' ) ); ?>"
									value="1" <?php checked( ! empty( $entry['enabled'] ) ); ?> />
								<?php esc_html_e( 'Enable this mapping', 'woonotifuse' ); ?>
							</label>
							<p class="description"><?php echo esc_html( $def['description'] ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notifuse field', 'woonotifuse' ); ?></th>
						<td>
							<?php if ( ! empty( $def['native'] ) ) : ?>
								<code><?php echo esc_html( $def['native'] ); ?></code>
								<p class="description">
									<?php esc_html_e( "Written to the contact's built-in field; no custom field needed.", 'woonotifuse' ); ?>
								</p>
							<?php else : ?>
								<select id="<?php echo esc_attr( 'wn-target-' . $key ); ?>"
									name="<?php echo esc_attr( self::field_name( $key, 'target' ) ); ?>">
									<option value=""><?php esc_html_e( '— Select a field —', 'woonotifuse' ); ?></option>
									<?php foreach ( self::notifuse_field_choices( $def['type'] ) as $fkey => $flabel ) : ?>
										<option value="<?php echo esc_attr( $fkey ); ?>" <?php selected( $entry['target'], $fkey ); ?>>
											<?php echo esc_html( $flabel ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( ! empty( $def['has_modes'] ) ) : ?>
						<?php $this->render_lang_rows( $key, $entry ); ?>
					<?php endif; ?>
				</table>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save field mappings', 'woonotifuse' ) ); ?>
		</form>
		<?php
		$this->render_lang_toggle_script();
	}

	/**
	 * Render the preferred-language source rows.
	 *
	 * @param string $key   Field key.
	 * @param array  $entry Current config.
	 */
	private function render_lang_rows( $key, $entry ) {
		$mode = isset( $entry['mode'] ) ? $entry['mode'] : self::LANG_MODE_FIXED;
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( 'wn-mode-' . $key ); ?>"><?php esc_html_e( 'Language source', 'woonotifuse' ); ?></label>
			</th>
			<td>
				<select id="<?php echo esc_attr( 'wn-mode-' . $key ); ?>"
					class="wn-lang-mode"
					data-field="<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( self::field_name( $key, 'mode' ) ); ?>">
					<option value="<?php echo esc_attr( self::LANG_MODE_FIXED ); ?>" <?php selected( $mode, self::LANG_MODE_FIXED ); ?>>
						<?php esc_html_e( 'Fixed value', 'woonotifuse' ); ?>
					</option>
					<?php
					$wpml_active = self::is_wpml_active();
					// Disable the WPML option when WPML is absent — unless it is the
					// already-saved mode, so an existing selection stays visible.
					$wpml_disabled = ( ! $wpml_active && self::LANG_MODE_WPML !== $mode );
					$wpml_label    = $wpml_active
						? __( 'WPML order language', 'woonotifuse' )
						: __( 'WPML order language (requires WPML)', 'woonotifuse' );
					?>
					<option value="<?php echo esc_attr( self::LANG_MODE_WPML ); ?>"
						<?php selected( $mode, self::LANG_MODE_WPML ); ?>
						<?php disabled( $wpml_disabled ); ?>>
						<?php echo esc_html( $wpml_label ); ?>
					</option>
					<option value="<?php echo esc_attr( self::LANG_MODE_ZIPCODE ); ?>" <?php selected( $mode, self::LANG_MODE_ZIPCODE ); ?>>
						<?php esc_html_e( 'Zip code mapping', 'woonotifuse' ); ?>
					</option>
					<option value="<?php echo esc_attr( self::LANG_MODE_STATE ); ?>" <?php selected( $mode, self::LANG_MODE_STATE ); ?>>
						<?php esc_html_e( 'Province / state mapping', 'woonotifuse' ); ?>
					</option>
				</select>
			</td>
		</tr>

		<tr class="wn-lang-row wn-lang-row-<?php echo esc_attr( $key ); ?>" data-mode="<?php echo esc_attr( self::LANG_MODE_FIXED ); ?>">
			<th scope="row"><?php esc_html_e( 'Fixed value', 'woonotifuse' ); ?></th>
			<td>
				<input type="text" class="regular-text"
					name="<?php echo esc_attr( self::field_name( $key, 'fixed_value' ) ); ?>"
					value="<?php echo esc_attr( isset( $entry['fixed_value'] ) ? $entry['fixed_value'] : '' ); ?>"
					placeholder="es" />
				<p class="description"><?php esc_html_e( 'Sent verbatim for every contact, e.g. "es" or "en-US".', 'woonotifuse' ); ?></p>
			</td>
		</tr>

		<tr class="wn-lang-row wn-lang-row-<?php echo esc_attr( $key ); ?>" data-mode="<?php echo esc_attr( self::LANG_MODE_ZIPCODE ); ?>">
			<th scope="row"><?php esc_html_e( 'Zip code rules', 'woonotifuse' ); ?></th>
			<td>
				<textarea class="large-text code" rows="5"
					name="<?php echo esc_attr( self::field_name( $key, 'zipcode_rules' ) ); ?>"
					placeholder="ca = 08001, 08002, 17001&#10;eu = 48001, 20001"><?php echo esc_textarea( isset( $entry['zipcode_rules'] ) ? $entry['zipcode_rules'] : '' ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'One rule per line, in the form: value = comma,separated,zipcodes. The billing postcode is matched against each rule top to bottom; the first match wins.', 'woonotifuse' ); ?>
				</p>
			</td>
		</tr>

		<tr class="wn-lang-row wn-lang-row-<?php echo esc_attr( $key ); ?>" data-mode="<?php echo esc_attr( self::LANG_MODE_ZIPCODE ); ?>">
			<th scope="row"><?php esc_html_e( 'Fallback value', 'woonotifuse' ); ?></th>
			<td>
				<input type="text" class="regular-text"
					name="<?php echo esc_attr( self::field_name( $key, 'zipcode_fallback' ) ); ?>"
					value="<?php echo esc_attr( isset( $entry['zipcode_fallback'] ) ? $entry['zipcode_fallback'] : '' ); ?>"
					placeholder="es" />
				<p class="description"><?php esc_html_e( 'Used when no zip code rule matches. Leave blank to send nothing.', 'woonotifuse' ); ?></p>
			</td>
		</tr>

		<tr class="wn-lang-row wn-lang-row-<?php echo esc_attr( $key ); ?>" data-mode="<?php echo esc_attr( self::LANG_MODE_STATE ); ?>">
			<th scope="row"><?php esc_html_e( 'Province / state rules', 'woonotifuse' ); ?></th>
			<td>
				<textarea class="large-text code" rows="5"
					name="<?php echo esc_attr( self::field_name( $key, 'state_rules' ) ); ?>"
					placeholder="ca = B, GI, L, T, PM&#10;eu = BI, SS, VI, NA"><?php echo esc_textarea( isset( $entry['state_rules'] ) ? $entry['state_rules'] : '' ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'One rule per line, in the form: value = comma,separated,state codes. The billing province/state code is matched against each rule top to bottom; the first match wins. Use WooCommerce state codes (e.g. for Spain: B = Barcelona, GI = Girona, PM = Baleares).', 'woonotifuse' ); ?>
				</p>
			</td>
		</tr>

		<tr class="wn-lang-row wn-lang-row-<?php echo esc_attr( $key ); ?>" data-mode="<?php echo esc_attr( self::LANG_MODE_STATE ); ?>">
			<th scope="row"><?php esc_html_e( 'Fallback value', 'woonotifuse' ); ?></th>
			<td>
				<input type="text" class="regular-text"
					name="<?php echo esc_attr( self::field_name( $key, 'state_fallback' ) ); ?>"
					value="<?php echo esc_attr( isset( $entry['state_fallback'] ) ? $entry['state_fallback'] : '' ); ?>"
					placeholder="es" />
				<p class="description"><?php esc_html_e( 'Used when no province/state rule matches. Leave blank to send nothing.', 'woonotifuse' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Inline JS that shows only the rows relevant to the chosen language mode.
	 */
	private function render_lang_toggle_script() {
		?>
		<script>
		( function () {
			function sync( select ) {
				var field = select.getAttribute( 'data-field' );
				var mode  = select.value;
				var rows  = document.querySelectorAll( '.wn-lang-row-' + field );
				rows.forEach( function ( row ) {
					row.style.display = ( row.getAttribute( 'data-mode' ) === mode ) ? '' : 'none';
				} );
			}
			document.querySelectorAll( '.wn-lang-mode' ).forEach( function ( select ) {
				sync( select );
				select.addEventListener( 'change', function () { sync( select ); } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Build a nested option field name.
	 *
	 * @param string $key     Field key.
	 * @param string $sub_key Sub-key.
	 * @return string
	 */
	private static function field_name( $key, $sub_key ) {
		return self::OPTION_KEY . '[' . $key . '][' . $sub_key . ']';
	}
}

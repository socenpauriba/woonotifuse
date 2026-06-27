<?php
/**
 * Optional newsletter consent checkbox at checkout.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an optional, configurable consent checkbox on the checkout (classic
 * and block) and records the customer's choice on the order. {@see Order_Sync}
 * consults {@see Checkout_Consent::has_consent()} when the order is paid to
 * decide whether to subscribe.
 *
 * Fully modular: the whole feature only activates when the consent checkbox is
 * enabled and at least one list ID is configured ({@see Checkout_Consent::is_active()}).
 * Turn it off to subscribe every synced order with no checkbox, or clear the
 * list IDs to only sync contact data.
 */
class Checkout_Consent {

	/**
	 * Block checkout additional field ID (namespaced).
	 */
	const BLOCK_FIELD_ID = 'woonotifuse/marketing-opt-in';

	/**
	 * Order meta key WooCommerce stores the block field value under.
	 */
	const BLOCK_FIELD_META = '_wc_other/woonotifuse/marketing-opt-in';

	/**
	 * Classic checkout field name / posted key.
	 */
	const CLASSIC_FIELD = 'woonotifuse_consent';

	/**
	 * Order meta key recording consent given on the classic checkout.
	 */
	const CONSENT_META = '_woonotifuse_consent';

	/**
	 * Whether the consent checkbox feature is fully configured and active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return Settings::checkout_consent_enabled()
			&& ! empty( Settings::subscribe_list_ids() );
	}

	/**
	 * Has the customer consented for this order? Checks both the classic-checkout
	 * meta and the block-checkout additional field.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function has_consent( WC_Order $order ) {
		if ( 'yes' === $order->get_meta( self::CONSENT_META ) ) {
			return true;
		}

		// Block checkout stores the checkbox value (cast to bool by WooCommerce).
		return ! empty( $order->get_meta( self::BLOCK_FIELD_META ) );
	}

	/**
	 * Register hooks. No-op unless the feature is active.
	 */
	public function init() {
		if ( ! self::is_active() ) {
			return;
		}

		// Block / Store API checkout: register a checkbox additional field.
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );

		// Classic checkout: render the checkbox and capture the posted value.
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_classic_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_classic_consent' ), 10, 2 );
	}

	/**
	 * Register the block-checkout additional field (order-scoped, so it is not
	 * persisted to the customer profile or pre-filled on future orders).
	 */
	public function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::BLOCK_FIELD_ID,
				'label'    => Settings::consent_text(),
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => false,
			)
		);
	}

	/**
	 * Render the consent checkbox on the classic checkout.
	 */
	public function render_classic_field() {
		woocommerce_form_field(
			self::CLASSIC_FIELD,
			array(
				'type'     => 'checkbox',
				'class'    => array( 'form-row-wide', 'woonotifuse-consent' ),
				'label'    => Settings::consent_text(),
				'required' => false,
			),
			''
		);
	}

	/**
	 * Persist consent from the classic checkout onto the order.
	 *
	 * Only records the meta when the box is ticked; the checkout nonce has
	 * already been verified by WooCommerce before this runs.
	 *
	 * @param WC_Order $order The order being created.
	 * @param array    $data  Posted checkout data (unused).
	 */
	public function capture_classic_consent( $order, $data ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce upstream.
		$checked = isset( $_POST[ self::CLASSIC_FIELD ] ) && wc_string_to_bool( wc_clean( wp_unslash( $_POST[ self::CLASSIC_FIELD ] ) ) );

		if ( $checked ) {
			$order->update_meta_data( self::CONSENT_META, 'yes' );
		}
	}
}

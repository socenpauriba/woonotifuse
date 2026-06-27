<?php
/**
 * Syncs WooCommerce customers to Notifuse contacts when an order is paid.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for orders entering a paid status and upserts the customer into
 * Notifuse via `contacts.upsert`.
 *
 * Why on a *paid* status: WooCommerce's lifetime totals (order count, total
 * spent) already include the triggering order by then, so the values computed
 * by {@see Field_Resolver} are correct without any read-before-write. The
 * upsert is idempotent — re-running it simply re-sets the same computed values.
 */
class Order_Sync {

	/**
	 * Order meta key recording the last successful sync timestamp.
	 */
	const SYNCED_META = '_woonotifuse_contact_synced_at';

	/**
	 * Register hooks. Runs on both front-end and admin, since orders can become
	 * paid from the checkout, the admin, webhooks or cron.
	 */
	public function init() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * Fire the sync the first time an order transitions into a paid status.
	 *
	 * Guards against re-syncing on paid→paid moves (e.g. processing→completed):
	 * the contact is only upserted on the transition *into* the paid state.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from     Previous status (no `wc-` prefix).
	 * @param string   $to       New status (no `wc-` prefix).
	 * @param WC_Order $order    Order object.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		// Respect the global toggle. Manual bulk / order actions call
		// sync_order() directly and are intentionally unaffected by this.
		if ( ! Settings::auto_sync_enabled() ) {
			return;
		}

		$paid_statuses = wc_get_is_paid_statuses();

		// Only act when entering a paid status from a non-paid one.
		if ( ! in_array( $to, $paid_statuses, true ) ) {
			return;
		}
		if ( in_array( $from, $paid_statuses, true ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->sync_order( $order );
	}

	/**
	 * Build the contact payload and upsert it into Notifuse.
	 *
	 * @param WC_Order $order Order that triggered the sync.
	 * @return array|WP_Error|null Decoded response, a WP_Error on failure, or
	 *                             null when the sync was skipped.
	 */
	public function sync_order( WC_Order $order ) {
		$email = sanitize_email( $order->get_billing_email() );

		// No email means no contact to key on — nothing to do.
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

		$client = Settings::make_client();

		if ( ! $client->is_configured() ) {
			// Not configured yet — stay silent, this is an expected no-op.
			return null;
		}

		$contact = $this->build_contact( $order, $email );

		$response = $client->post( 'api/contacts.upsert', array( 'contact' => $contact ) );

		if ( is_wp_error( $response ) ) {
			$this->log_failure( $order, $response );
			return $response;
		}

		$order->update_meta_data( self::SYNCED_META, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$order->save_meta_data();

		$this->log( sprintf( 'Contact %s synced for order #%d.', $email, $order->get_id() ), 'info' );

		/**
		 * Fires after a contact has been successfully upserted to Notifuse.
		 *
		 * @param array    $response The decoded Notifuse response.
		 * @param array    $contact  The contact payload that was sent.
		 * @param WC_Order $order    The order that triggered the sync.
		 */
		do_action( 'woonotifuse_contact_synced', $response, $contact, $order );

		return $response;
	}

	/**
	 * Assemble the Notifuse contact payload from an order.
	 *
	 * Standard billing fields plus the enabled custom-field mappings from
	 * {@see Field_Resolver}. Only non-empty values are included.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $email Sanitized billing email.
	 * @return array<string,mixed>
	 */
	private function build_contact( WC_Order $order, $email ) {
		$contact = array( 'email' => $email );

		// A registered customer's WP user ID makes a stable external identifier.
		$user_id = $order->get_customer_id();
		if ( $user_id ) {
			$contact['external_id'] = (string) $user_id;
		}

		$fields = array(
			'first_name'     => $order->get_billing_first_name(),
			'last_name'      => $order->get_billing_last_name(),
			'phone'          => $order->get_billing_phone(),
			'address_line_1' => $order->get_billing_address_1(),
			'address_line_2' => $order->get_billing_address_2(),
			'postcode'       => $order->get_billing_postcode(),
			'state'          => $order->get_billing_state(),
			'country'        => $order->get_billing_country(),
		);

		foreach ( $fields as $key => $value ) {
			$value = is_string( $value ) ? trim( $value ) : $value;
			if ( '' !== $value && null !== $value ) {
				$contact[ $key ] = $value;
			}
		}

		// Merge the configured custom-field mappings (custom_number_*, etc.).
		$contact = array_merge( $contact, Field_Resolver::for_order( $order ) );

		/**
		 * Filter the Notifuse contact payload before it is upserted.
		 *
		 * @param array    $contact The contact payload (email is always present).
		 * @param WC_Order $order   The order that triggered the sync.
		 */
		return apply_filters( 'woonotifuse_contact_payload', $contact, $order );
	}

	/**
	 * Record a sync failure: a logger line plus a private order note so the
	 * problem is visible to shop managers, never silently dropped.
	 *
	 * @param WC_Order $order Order.
	 * @param WP_Error $error The failure.
	 */
	private function log_failure( WC_Order $order, WP_Error $error ) {
		$message = $error->get_error_message();

		$this->log(
			sprintf( 'Contact sync failed for order #%d: %s', $order->get_id(), $message ),
			'error'
		);

		$order->add_order_note(
			sprintf(
				/* translators: %s: error message returned by Notifuse. */
				__( 'WooNotifuse: contact sync to Notifuse failed — %s', 'woonotifuse' ),
				$message
			)
		);
	}

	/**
	 * Write to the WooCommerce logger under the "woonotifuse" source.
	 *
	 * @param string $message Message.
	 * @param string $level   Log level (info|error|…).
	 */
	private function log( $message, $level = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => 'woonotifuse' ) );
	}
}

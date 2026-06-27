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
 * Listens for orders entering a paid status and syncs the customer to Notifuse.
 *
 * One trigger, one call. When mailing lists are configured and the consent
 * requirement (if enabled) is satisfied, it calls `lists.subscribe` — which
 * upserts the contact *and* subscribes it in a single request. Otherwise it
 * calls `contacts.upsert` to keep the contact's data fresh without touching
 * list membership.
 *
 * Why on a *paid* status: WooCommerce's lifetime totals (order count, total
 * spent) already include the triggering order by then, so the values computed
 * by {@see Field_Resolver} are correct without any read-before-write. Both
 * calls are idempotent — re-running simply re-sets the same values.
 */
class Order_Sync {

	/**
	 * Order meta key recording the last successful sync timestamp.
	 */
	const SYNCED_META = '_woonotifuse_contact_synced_at';

	/**
	 * Order meta key recording the last successful list-subscription timestamp.
	 */
	const SUBSCRIBED_META = '_woonotifuse_lists_subscribed_at';

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
	 * Sync an order's customer to Notifuse: subscribe to the configured lists
	 * when allowed, otherwise just upsert the contact's data.
	 *
	 * @param WC_Order $order Order that triggered the sync.
	 * @return array|WP_Error|null Decoded response, a WP_Error on failure, or
	 *                             null when the sync was skipped.
	 */
	public function sync_order( WC_Order $order ) {
		$contact = self::contact_for( $order );

		// No usable email means no contact to key on — nothing to do.
		if ( null === $contact ) {
			return null;
		}

		$client = Settings::make_client();

		if ( ! $client->is_configured() ) {
			// Not configured yet — stay silent, this is an expected no-op.
			return null;
		}

		// Subscribe (which also upserts) when lists are configured and the
		// consent requirement, if enabled, is met. Otherwise just upsert data.
		$list_ids  = Settings::subscribe_list_ids();
		$subscribe = ! empty( $list_ids )
			&& ( ! Settings::checkout_consent_enabled() || Checkout_Consent::has_consent( $order ) );

		if ( $subscribe ) {
			$response = $client->post(
				'api/lists.subscribe',
				array(
					'contact'  => $contact,
					'list_ids' => $list_ids,
				)
			);
		} else {
			$response = $client->post( 'api/contacts.upsert', array( 'contact' => $contact ) );
		}

		if ( is_wp_error( $response ) ) {
			$this->log_failure( $order, $response );
			return $response;
		}

		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		$order->update_meta_data( self::SYNCED_META, $now );
		if ( $subscribe ) {
			$order->update_meta_data( self::SUBSCRIBED_META, $now );
		}
		$order->save_meta_data();

		$this->log(
			$subscribe
				? sprintf( 'Contact %s synced + subscribed to [%s] for order #%d.', $contact['email'], implode( ', ', $list_ids ), $order->get_id() )
				: sprintf( 'Contact %s synced for order #%d.', $contact['email'], $order->get_id() ),
			'info'
		);

		/**
		 * Fires after a contact has been successfully synced to Notifuse.
		 *
		 * @param array    $response The decoded Notifuse response.
		 * @param array    $contact  The contact payload that was sent.
		 * @param WC_Order $order    The order that triggered the sync.
		 */
		do_action( 'woonotifuse_contact_synced', $response, $contact, $order );

		if ( $subscribe ) {
			/**
			 * Fires after a customer has been subscribed to Notifuse lists.
			 *
			 * @param array    $response The decoded Notifuse response.
			 * @param string[] $list_ids The list IDs subscribed to.
			 * @param WC_Order $order    The order that triggered the subscription.
			 */
			do_action( 'woonotifuse_lists_subscribed', $response, $list_ids, $order );
		}

		return $response;
	}

	/**
	 * Assemble the Notifuse contact payload from an order.
	 *
	 * Standard billing fields plus the enabled custom-field mappings from
	 * {@see Field_Resolver}. Only non-empty values are included. Shared by the
	 * contact upsert and the list subscription, since Notifuse's Contact and
	 * SubscriptionContact share the same shape (email required).
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>|null The contact payload, or null when the
	 *                                  order has no usable billing email.
	 */
	public static function contact_for( WC_Order $order ) {
		$email = sanitize_email( $order->get_billing_email() );

		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

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
				__( 'WooNotifuse: sync to Notifuse failed — %s', 'woonotifuse' ),
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

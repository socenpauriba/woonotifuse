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
	 * Action Scheduler hook a queued batch of orders is processed under.
	 */
	const BATCH_HOOK = 'woonotifuse_sync_orders_batch';

	/**
	 * Action Scheduler group, so queued jobs are easy to find and bulk-cancel.
	 */
	const AS_GROUP = 'woonotifuse';

	/**
	 * Orders per queued batch. Also caps the number of contacts in a single
	 * `contacts.import` call (one chunk → at most this many contacts, before
	 * per-customer de-duplication shrinks it further).
	 */
	const BATCH_SIZE = 50;

	/**
	 * Register hooks. Runs on both front-end and admin, since orders can become
	 * paid from the checkout, the admin, webhooks or cron.
	 */
	public function init() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );

		// Background processor for manual bulk syncs. Registered unconditionally
		// because Action Scheduler runs the queue in a non-admin loopback request.
		add_action( self::BATCH_HOOK, array( $this, 'run_batch' ), 10, 1 );
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
	 * Action Scheduler entry point: sync one queued batch of orders.
	 *
	 * @param int[] $order_ids Order IDs in this batch.
	 */
	public function run_batch( $order_ids = array() ) {
		$this->sync_orders_batch( (array) $order_ids );
	}

	/**
	 * Sync many orders with as few API calls as possible via `contacts.import`.
	 *
	 * Builds one contact per order, de-duplicates by email (a customer with
	 * several selected orders is imported once — the computed values are
	 * identical, and a single order opting in is enough to subscribe), then
	 * splits the contacts into a "subscribe" bucket (lists configured + consent
	 * satisfied) and an "upsert-only" bucket, importing each in chunks.
	 *
	 * Unlike {@see sync_order()}, this never calls `lists.subscribe`/`upsert`
	 * per order: the subscribe bucket uses `contacts.import` with
	 * `subscribe_to_lists` (its batch equivalent), the other a plain import.
	 *
	 * @param int[] $order_ids Order IDs to sync.
	 * @return array{synced:int,failed:int,skipped:int} Per-order tally.
	 */
	public function sync_orders_batch( array $order_ids ) {
		$tally = array(
			'synced'  => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		$client = Settings::make_client();
		if ( ! $client->is_configured() ) {
			// Not configured — nothing to do. Count every order as skipped.
			$tally['skipped'] = count( $order_ids );
			return $tally;
		}

		$list_ids = Settings::subscribe_list_ids();

		// Group orders by email. Each entry collects the contact payload, the
		// effective subscribe decision, and every order it covers (so meta and
		// notes land on all of them once the batch resolves).
		$by_email = array();

		foreach ( $order_ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				++$tally['skipped'];
				continue;
			}

			$contact = self::contact_for( $order );
			if ( null === $contact ) {
				++$tally['skipped'];
				continue;
			}

			$email     = $contact['email'];
			$key       = strtolower( $email );
			$subscribe = ! empty( $list_ids )
				&& ( ! Settings::checkout_consent_enabled() || Checkout_Consent::has_consent( $order ) );

			if ( ! isset( $by_email[ $key ] ) ) {
				$by_email[ $key ] = array(
					'contact'   => $contact,
					'subscribe' => $subscribe,
					'orders'    => array(),
				);
			} else {
				// Same customer, another order: refresh the payload (values are
				// computed identically) and OR the opt-in.
				$by_email[ $key ]['contact']    = $contact;
				$by_email[ $key ]['subscribe'] = $by_email[ $key ]['subscribe'] || $subscribe;
			}

			$by_email[ $key ]['orders'][] = $order;
		}

		if ( empty( $by_email ) ) {
			return $tally;
		}

		// Split into the two API shapes.
		$subscribe_bucket = array();
		$upsert_bucket    = array();
		foreach ( $by_email as $entry ) {
			if ( $entry['subscribe'] ) {
				$subscribe_bucket[] = $entry;
			} else {
				$upsert_bucket[] = $entry;
			}
		}

		$this->import_bucket( $client, $upsert_bucket, array(), $tally );
		$this->import_bucket( $client, $subscribe_bucket, $list_ids, $tally );

		return $tally;
	}

	/**
	 * Import one bucket of grouped contacts via `contacts.import`, in chunks.
	 *
	 * A transport/HTTP error fails the whole chunk; otherwise the per-contact
	 * `operations` decide each customer's fate. Side effects (meta, notes,
	 * hooks) mirror {@see sync_order()} and are applied to every order behind a
	 * given email. The tally counts orders, not unique contacts.
	 *
	 * @param \WooNotifuse\Api\Client $client   Configured client.
	 * @param array                   $bucket   Entries: { contact, subscribe, orders[] }.
	 * @param string[]                $list_ids Lists to subscribe to (empty = upsert only).
	 * @param array                   $tally    Running tally, modified by reference.
	 */
	private function import_bucket( $client, array $bucket, array $list_ids, array &$tally ) {
		if ( empty( $bucket ) ) {
			return;
		}

		$subscribed = ! empty( $list_ids );

		foreach ( array_chunk( $bucket, self::BATCH_SIZE ) as $chunk ) {
			$body = array( 'contacts' => array_map(
				static function ( $entry ) {
					return $entry['contact'];
				},
				$chunk
			) );

			if ( $subscribed ) {
				$body['subscribe_to_lists'] = $list_ids;
			}

			$response = $client->post( 'api/contacts.import', $body );

			// Transport error, non-2xx, or a global API error fails the chunk.
			$global_error = is_wp_error( $response )
				? $response->get_error_message()
				: ( is_array( $response ) && ! empty( $response['error'] ) ? (string) $response['error'] : '' );

			if ( '' !== $global_error ) {
				foreach ( $chunk as $entry ) {
					$this->fail_entry( $entry, $global_error, $tally );
				}
				continue;
			}

			// Map per-contact operation results back to emails (case-insensitive).
			$ops = array();
			if ( is_array( $response ) && ! empty( $response['operations'] ) && is_array( $response['operations'] ) ) {
				foreach ( $response['operations'] as $op ) {
					if ( is_array( $op ) && isset( $op['email'] ) ) {
						$ops[ strtolower( (string) $op['email'] ) ] = $op;
					}
				}
			}

			foreach ( $chunk as $entry ) {
				$key = strtolower( $entry['contact']['email'] );
				$op  = isset( $ops[ $key ] ) ? $ops[ $key ] : null;

				if ( $op && isset( $op['action'] ) && 'error' === $op['action'] ) {
					$message = ! empty( $op['error'] ) ? (string) $op['error'] : __( 'Notifuse reported an error for this contact.', 'woonotifuse' );
					$this->fail_entry( $entry, $message, $tally );
					continue;
				}

				// Success (create/update) — or a 2xx with no operations echoed,
				// which we treat as success since the import returned no error.
				$this->succeed_entry( $entry, $op, $subscribed, $tally );
			}
		}
	}

	/**
	 * Mark every order behind an entry as synced: stamp meta, fire hooks, tally.
	 *
	 * @param array     $entry      Grouped entry { contact, orders[] }.
	 * @param array|null $op         The contact's import operation result, if any.
	 * @param bool      $subscribed Whether this bucket subscribed to lists.
	 * @param array     $tally      Tally, modified by reference.
	 */
	private function succeed_entry( array $entry, $op, $subscribed, array &$tally ) {
		$now      = gmdate( 'Y-m-d\TH:i:s\Z' );
		$contact  = $entry['contact'];
		$response = is_array( $op ) ? $op : array();

		foreach ( $entry['orders'] as $order ) {
			$order->update_meta_data( self::SYNCED_META, $now );
			if ( $subscribed ) {
				$order->update_meta_data( self::SUBSCRIBED_META, $now );
			}
			$order->save_meta_data();

			/** This action is documented in includes/class-order-sync.php. */
			do_action( 'woonotifuse_contact_synced', $response, $contact, $order );

			if ( $subscribed ) {
				/** This action is documented in includes/class-order-sync.php. */
				do_action( 'woonotifuse_lists_subscribed', $response, Settings::subscribe_list_ids(), $order );
			}

			++$tally['synced'];
		}

		$this->log(
			sprintf(
				$subscribed
					? 'Batch: contact %1$s synced + subscribed (%2$d order(s)).'
					: 'Batch: contact %1$s synced (%2$d order(s)).',
				$contact['email'],
				count( $entry['orders'] )
			),
			'info'
		);
	}

	/**
	 * Mark every order behind an entry as failed: note, log, tally.
	 *
	 * @param array  $entry   Grouped entry { contact, orders[] }.
	 * @param string $message Failure detail.
	 * @param array  $tally   Tally, modified by reference.
	 */
	private function fail_entry( array $entry, $message, array &$tally ) {
		foreach ( $entry['orders'] as $order ) {
			$this->record_failure( $order, $message );
			++$tally['failed'];
		}
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

		// Notifuse has no dedicated city field, so fold the billing city into
		// address line 2 (after any existing line 2 such as a flat/floor).
		$line2 = implode(
			', ',
			array_filter(
				array(
					trim( (string) $order->get_billing_address_2() ),
					trim( (string) $order->get_billing_city() ),
				)
			)
		);

		$fields = array(
			'first_name'     => $order->get_billing_first_name(),
			'last_name'      => $order->get_billing_last_name(),
			'phone'          => $order->get_billing_phone(),
			'address_line_1' => $order->get_billing_address_1(),
			'address_line_2' => $line2,
			'postcode'       => $order->get_billing_postcode(),
			// Send the full province/state name ("Barcelona"), not the code ("B").
			'state'          => self::state_name( $order->get_billing_country(), $order->get_billing_state() ),
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
	 * Resolve a province/state code to its full name for the given country.
	 *
	 * WooCommerce stores the state as a code ("B") for countries with a defined
	 * states list (e.g. Spain); this returns the human name ("Barcelona"). For
	 * countries with no list (free-text state) the value is returned unchanged.
	 *
	 * @param string $country Billing country code.
	 * @param string $state   Billing state code or value.
	 * @return string
	 */
	private static function state_name( $country, $state ) {
		$state = trim( (string) $state );

		if ( '' === $state || '' === trim( (string) $country ) || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return $state;
		}

		$states = WC()->countries->get_states( $country );

		if ( is_array( $states ) && isset( $states[ $state ] ) ) {
			return html_entity_decode( $states[ $state ], ENT_QUOTES, 'UTF-8' );
		}

		return $state;
	}

	/**
	 * Record a sync failure: a logger line plus a private order note so the
	 * problem is visible to shop managers, never silently dropped.
	 *
	 * @param WC_Order $order Order.
	 * @param WP_Error $error The failure.
	 */
	private function log_failure( WC_Order $order, WP_Error $error ) {
		$this->record_failure( $order, $error->get_error_message() );
	}

	/**
	 * Record a sync failure for one order from a plain message: a logger line
	 * plus a private order note so the problem is visible, never silently
	 * dropped. Shared by the single-order and batch paths.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $message Human-readable failure detail.
	 */
	private function record_failure( WC_Order $order, $message ) {
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

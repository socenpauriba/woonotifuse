<?php
/**
 * Admin-triggered "Sync with Notifuse" actions for orders.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the contact sync as a manual admin action, in two places:
 *
 * - a bulk action on the orders list (both the legacy posts table and the
 *   HPOS orders table), and
 * - a single-order action in the "Order actions" box on the order edit screen.
 *
 * The single-order action runs {@see Order_Sync::sync_order()} inline. The bulk
 * action instead *queues* the selection for background processing via Action
 * Scheduler ({@see Order_Sync::run_batch()} → `contacts.import`), so a large
 * selection never blocks the admin request — the original synchronous loop
 * timed out at ~50 orders. Either way a manual sync behaves like the automatic
 * paid-status one (same payload, same "synced at" meta) and is unaffected by
 * the `auto_sync` toggle.
 */
class Order_Actions {

	/**
	 * Shared action identifier (bulk action value + order-action key).
	 */
	const ACTION = 'woonotifuse_sync';

	/**
	 * Orders-list screen IDs we attach the bulk action to.
	 */
	const SCREENS = array( 'edit-shop_order', 'woocommerce_page_wc-orders' );

	/**
	 * The sync worker.
	 *
	 * @var Order_Sync
	 */
	private $sync;

	/**
	 * Constructor.
	 *
	 * @param Order_Sync|null $sync Sync worker (injectable for tests).
	 */
	public function __construct( ?Order_Sync $sync = null ) {
		$this->sync = $sync ? $sync : new Order_Sync();
	}

	/**
	 * Register admin hooks. Admin-only — these are admin UI surfaces.
	 */
	public function init() {
		// Single-order action in the "Order actions" metabox.
		add_filter( 'woocommerce_order_actions', array( $this, 'register_order_action' ) );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( $this, 'handle_order_action' ) );

		// Bulk action on the orders list, for both storage backends.
		foreach ( self::SCREENS as $screen ) {
			add_filter( "bulk_actions-{$screen}", array( $this, 'register_bulk_action' ) );
			add_filter( "handle_bulk_actions-{$screen}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}

		add_action( 'admin_notices', array( $this, 'render_bulk_notice' ) );
	}

	// -----------------------------------------------------------------------
	// Single order action.
	// -----------------------------------------------------------------------

	/**
	 * Add the action to the order-edit "Order actions" dropdown.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @return array<string,string>
	 */
	public function register_order_action( $actions ) {
		$actions[ self::ACTION ] = __( 'Sync with Notifuse', 'woonotifuse' );

		return $actions;
	}

	/**
	 * Run the sync when the order action is selected, leaving an order note so
	 * the result is visible in the order history.
	 *
	 * @param WC_Order $order The order being edited.
	 */
	public function handle_order_action( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$result = $this->sync->sync_order( $order );

		if ( is_wp_error( $result ) ) {
			// Order_Sync already added a failure note with the error detail.
			return;
		}

		if ( null === $result ) {
			$order->add_order_note(
				__( 'WooNotifuse: sync skipped — check the plugin is configured and the order has a billing email.', 'woonotifuse' )
			);
			return;
		}

		$order->add_order_note( __( 'WooNotifuse: contact synced to Notifuse.', 'woonotifuse' ) );
	}

	// -----------------------------------------------------------------------
	// Bulk action.
	// -----------------------------------------------------------------------

	/**
	 * Add the bulk action to the orders-list dropdown.
	 *
	 * @param array<string,string> $actions Existing bulk actions.
	 * @return array<string,string>
	 */
	public function register_bulk_action( $actions ) {
		$actions[ self::ACTION ] = __( 'Sync with Notifuse', 'woonotifuse' );

		return $actions;
	}

	/**
	 * Queue the selected orders for background syncing, then carry a count in
	 * the redirect URL so the notice can confirm what was enqueued.
	 *
	 * Syncing runs out-of-band via Action Scheduler (in {@see Order_Sync}) so a
	 * large selection never blocks the admin request or hits its time limit. If
	 * Action Scheduler is somehow unavailable, fall back to processing inline in
	 * batches — still a handful of `contacts.import` calls, not one per order.
	 *
	 * @param string $redirect_to Destination URL WordPress will redirect to.
	 * @param string $action      The chosen bulk action.
	 * @param int[]  $ids         Selected order IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( self::ACTION !== $action ) {
			return $redirect_to;
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return $redirect_to;
		}

		$chunks = array_chunk( $ids, Order_Sync::BATCH_SIZE );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			foreach ( $chunks as $chunk ) {
				as_enqueue_async_action(
					Order_Sync::BATCH_HOOK,
					array( $chunk ),
					Order_Sync::AS_GROUP
				);
			}

			return add_query_arg( array( 'woonotifuse_queued' => count( $ids ) ), $redirect_to );
		}

		// Fallback: no Action Scheduler — process inline, still batched.
		$tally = array(
			'synced'  => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		foreach ( $chunks as $chunk ) {
			$result = $this->sync->sync_orders_batch( $chunk );
			foreach ( $tally as $key => $value ) {
				$tally[ $key ] = $value + ( isset( $result[ $key ] ) ? (int) $result[ $key ] : 0 );
			}
		}

		return add_query_arg(
			array(
				'woonotifuse_synced'  => $tally['synced'],
				'woonotifuse_failed'  => $tally['failed'],
				'woonotifuse_skipped' => $tally['skipped'],
			),
			$redirect_to
		);
	}

	/**
	 * Show the bulk-action result as an admin notice on the orders screen.
	 *
	 * Reads only display counts from the redirect WordPress itself built after
	 * the (capability-checked) bulk action; it performs no action of its own.
	 */
	public function render_bulk_notice() {
		if (
			! isset( $_GET['woonotifuse_queued'] )
			&& ! isset( $_GET['woonotifuse_synced'] )
			&& ! isset( $_GET['woonotifuse_failed'] )
			&& ! isset( $_GET['woonotifuse_skipped'] )
		) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}

		// Normal path: orders were queued for background syncing.
		if ( isset( $_GET['woonotifuse_queued'] ) ) {
			$queued = absint( wp_unslash( $_GET['woonotifuse_queued'] ) );

			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of orders. */
						_n(
							'%d order queued for syncing to Notifuse. It runs in the background; results appear in each order\'s notes and in WooCommerce → Status → Logs.',
							'%d orders queued for syncing to Notifuse. They run in the background; results appear in each order\'s notes and in WooCommerce → Status → Logs.',
							$queued,
							'woonotifuse'
						),
						$queued
					)
				)
			);

			return;
		}

		$synced  = isset( $_GET['woonotifuse_synced'] ) ? absint( wp_unslash( $_GET['woonotifuse_synced'] ) ) : 0;
		$failed  = isset( $_GET['woonotifuse_failed'] ) ? absint( wp_unslash( $_GET['woonotifuse_failed'] ) ) : 0;
		$skipped = isset( $_GET['woonotifuse_skipped'] ) ? absint( wp_unslash( $_GET['woonotifuse_skipped'] ) ) : 0;

		$parts = array(
			sprintf(
				/* translators: %d: number of orders. */
				_n( '%d order synced to Notifuse.', '%d orders synced to Notifuse.', $synced, 'woonotifuse' ),
				$synced
			),
		);

		if ( $failed > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of orders. */
				_n( '%d failed (see the order notes).', '%d failed (see the order notes).', $failed, 'woonotifuse' ),
				$failed
			);
		}

		if ( $skipped > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of orders. */
				_n( '%d skipped.', '%d skipped.', $skipped, 'woonotifuse' ),
				$skipped
			);
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $failed > 0 ? 'notice-warning' : 'notice-success' ),
			esc_html( implode( ' ', $parts ) )
		);
	}
}

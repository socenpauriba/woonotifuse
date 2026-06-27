<?php
/**
 * Computes Notifuse custom-field values from a WooCommerce order.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the {@see Field_Mappings} configuration into a concrete payload of
 * Notifuse custom fields for a given order, e.g. [ 'custom_number_1' => 4 ].
 *
 * All values are computed from WooCommerce at resolve time (idempotent — no
 * running counters, no read-before-write against Notifuse), per the agreed
 * design. The returned array is meant to be merged into a contacts.upsert
 * payload by the order-sync step.
 */
class Field_Resolver {

	/**
	 * Resolve enabled mappings for an order into a custom-field payload.
	 *
	 * @param WC_Order $order The order that triggered the sync.
	 * @return array<string,mixed> Notifuse field key => value (enabled, targeted, non-null only).
	 */
	public static function for_order( WC_Order $order ) {
		$payload     = array();
		$definitions = Field_Mappings::definitions();

		foreach ( Field_Mappings::all() as $key => $config ) {
			if ( empty( $config['enabled'] ) ) {
				continue;
			}

			// Some mappings write to a built-in Notifuse contact field (e.g.
			// "language"); the rest write to the admin-chosen custom field.
			$def    = isset( $definitions[ $key ] ) ? $definitions[ $key ] : array();
			$target = ! empty( $def['native'] ) ? $def['native'] : ( ! empty( $config['target'] ) ? $config['target'] : '' );

			if ( '' === $target ) {
				continue;
			}

			$value = self::compute( $key, $order, $config );

			if ( null !== $value && '' !== $value ) {
				$payload[ $target ] = $value;
			}
		}

		return $payload;
	}

	/**
	 * Compute one field's value.
	 *
	 * @param string   $key    Field key.
	 * @param WC_Order $order  Order.
	 * @param array    $config Field config.
	 * @return mixed|null
	 */
	private static function compute( $key, WC_Order $order, array $config ) {
		switch ( $key ) {
			case 'order_count':
				return self::order_count( $order );

			case 'total_spent':
				return self::total_spent( $order );

			case 'last_purchase':
				return self::last_purchase( $order );

			case 'first_order_date':
				return self::first_order_date( $order );

			case 'preferred_lang':
				return self::preferred_lang( $order, $config );
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Individual value computations.
	// -----------------------------------------------------------------------

	/**
	 * Customer's number of **paid** orders (processing + completed).
	 *
	 * Counts only paid orders — never drafts, pending, failed or cancelled — so
	 * abandoned checkouts don't inflate the figure. Registered customers are
	 * matched by their account (customer ID); guests by billing email. This
	 * deliberately avoids `wc_get_customer_order_count()`, which counts almost
	 * every status (including `checkout-draft`) and caches the result.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	private static function order_count( WC_Order $order ) {
		return count( self::paid_order_ids( $order ) );
	}

	/**
	 * Customer's lifetime total spent.
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	private static function total_spent( WC_Order $order ) {
		$user_id = $order->get_customer_id();

		if ( $user_id ) {
			return (float) wc_get_customer_total_spent( $user_id );
		}

		return self::total_spent_by_email( $order->get_billing_email() );
	}

	/**
	 * Date of the triggering order, as an ISO 8601 UTC string.
	 *
	 * Uses the paid date when available, otherwise the created date.
	 *
	 * @param WC_Order $order Order.
	 * @return string|null
	 */
	private static function last_purchase( WC_Order $order ) {
		return self::order_date( $order );
	}

	/**
	 * Date of the customer's earliest order, as an ISO 8601 UTC string.
	 *
	 * Looks up the oldest order for the customer (by user ID, or by billing
	 * email for guests). Returns null when none can be found.
	 *
	 * @param WC_Order $order Triggering order.
	 * @return string|null
	 */
	private static function first_order_date( WC_Order $order ) {
		$args = array(
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'ASC',
		);

		$user_id = $order->get_customer_id();
		if ( $user_id ) {
			$args['customer_id'] = $user_id;
		} else {
			$email = $order->get_billing_email();
			if ( empty( $email ) ) {
				return null;
			}
			$args['billing_email'] = $email;
		}

		$orders = wc_get_orders( $args );
		$first  = ( is_array( $orders ) && ! empty( $orders ) ) ? $orders[0] : null;

		if ( ! $first instanceof WC_Order ) {
			return null;
		}

		return self::order_date( $first );
	}

	/**
	 * Format an order's date as an ISO 8601 UTC string.
	 *
	 * Uses the paid date when available, otherwise the created date.
	 *
	 * @param WC_Order $order Order.
	 * @return string|null
	 */
	private static function order_date( WC_Order $order ) {
		$date = $order->get_date_paid();
		if ( ! $date ) {
			$date = $order->get_date_created();
		}

		if ( ! $date ) {
			return null;
		}

		// Notifuse expects an RFC3339/ISO 8601 date-time; normalise to UTC.
		return gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() );
	}

	/**
	 * Resolve the preferred-language value per the configured mode.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $config Field config.
	 * @return string|null
	 */
	private static function preferred_lang( WC_Order $order, array $config ) {
		$mode = isset( $config['mode'] ) ? $config['mode'] : Field_Mappings::LANG_MODE_FIXED;

		switch ( $mode ) {
			case Field_Mappings::LANG_MODE_FIXED:
				$value = isset( $config['fixed_value'] ) ? trim( $config['fixed_value'] ) : '';
				return '' !== $value ? $value : null;

			case Field_Mappings::LANG_MODE_WPML:
				return self::wpml_language( $order );

			case Field_Mappings::LANG_MODE_ZIPCODE:
				$matched = self::match_rules(
					$order->get_billing_postcode(),
					isset( $config['zipcode_rules'] ) ? $config['zipcode_rules'] : ''
				);

				if ( null !== $matched ) {
					return $matched;
				}

				$fallback = isset( $config['zipcode_fallback'] ) ? trim( $config['zipcode_fallback'] ) : '';
				return '' !== $fallback ? $fallback : null;

			case Field_Mappings::LANG_MODE_STATE:
				$matched = self::match_rules(
					$order->get_billing_state(),
					isset( $config['state_rules'] ) ? $config['state_rules'] : ''
				);

				if ( null !== $matched ) {
					return $matched;
				}

				$fallback = isset( $config['state_fallback'] ) ? trim( $config['state_fallback'] ) : '';
				return '' !== $fallback ? $fallback : null;
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * IDs of the customer's paid orders (processing + completed).
	 *
	 * Registered customers are matched by account (customer ID); guests by
	 * billing email. Returns an empty array for guests without an email.
	 *
	 * @param WC_Order $order Order.
	 * @return int[]
	 */
	private static function paid_order_ids( WC_Order $order ) {
		$args = array(
			'limit'  => -1,
			'return' => 'ids',
			'status' => wc_get_is_paid_statuses(),
		);

		$user_id = $order->get_customer_id();
		if ( $user_id ) {
			$args['customer_id'] = $user_id;
		} else {
			$email = $order->get_billing_email();
			if ( empty( $email ) ) {
				return array();
			}
			$args['billing_email'] = $email;
		}

		$ids = wc_get_orders( $args );

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Sum a guest's spend across paid orders by billing email.
	 *
	 * @param string $email Billing email.
	 * @return float
	 */
	private static function total_spent_by_email( $email ) {
		if ( empty( $email ) ) {
			return 0.0;
		}

		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'status'        => array( 'wc-completed', 'wc-processing' ),
				'limit'         => -1,
			)
		);

		$total = 0.0;
		foreach ( (array) $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$total += (float) $order->get_total();
			}
		}

		return $total;
	}

	/**
	 * Read the order's WPML language, falling back to the site's current locale.
	 *
	 * WPML (and Polylang) store the order language in the `wpml_language` meta.
	 *
	 * @param WC_Order $order Order.
	 * @return string|null
	 */
	private static function wpml_language( WC_Order $order ) {
		// The WPML mode requires a multilingual plugin; without one there is no
		// language source, so resolve to nothing rather than guessing.
		if ( ! Field_Mappings::is_wpml_active() ) {
			return null;
		}

		$lang = $order->get_meta( 'wpml_language' );

		if ( empty( $lang ) ) {
			$lang = apply_filters( 'wpml_current_language', null );
		}

		if ( empty( $lang ) && defined( 'ICL_LANGUAGE_CODE' ) ) {
			$lang = ICL_LANGUAGE_CODE;
		}

		return ! empty( $lang ) ? (string) $lang : null;
	}

	/**
	 * Match a value against the configured rules.
	 *
	 * Rules are one-per-line "value = token, token, token". The first rule
	 * containing the (normalised) needle wins. Used for both postcode and
	 * province/state matching — the tokens are normalised the same way
	 * (upper-cased, whitespace stripped), which suits zip codes (08001) and
	 * WooCommerce state codes (B, GI) alike.
	 *
	 * @param string $needle Raw value to look up (postcode or state code).
	 * @param string $rules  Raw rules text.
	 * @return string|null Matched value, or null when nothing matches.
	 */
	private static function match_rules( $needle, $rules ) {
		$needle = self::normalize_token( $needle );

		if ( '' === $needle || '' === trim( (string) $rules ) ) {
			return null;
		}

		$lines = preg_split( '/\r\n|\r|\n/', (string) $rules );

		foreach ( $lines as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $value, $tokens ) = array_map( 'trim', explode( '=', $line, 2 ) );

			if ( '' === $value || '' === $tokens ) {
				continue;
			}

			foreach ( explode( ',', $tokens ) as $token ) {
				if ( self::normalize_token( $token ) === $needle ) {
					return $value;
				}
			}
		}

		return null;
	}

	/**
	 * Normalise a token for comparison (upper-case, no whitespace).
	 *
	 * @param string $token Postcode or state code.
	 * @return string
	 */
	private static function normalize_token( $token ) {
		return strtoupper( preg_replace( '/\s+/', '', (string) $token ) );
	}
}

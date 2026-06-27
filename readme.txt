=== WooNotifuse ===
Tags: woocommerce, notifuse, email, notifications, transactional
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to Notifuse for transactional emails and newsletter automation.

== Description ==

WooNotifuse bridges your WooCommerce store with the Notifuse transactional
email and newsletter platform. This release ships the plugin foundation:
WooCommerce dependency handling, HPOS compatibility, and a bootable core.

Notifuse integration (sending order notifications, syncing contacts) is built
on top of this base in subsequent releases.

== Requirements ==

* WooCommerce 7.0 or higher, installed and active.
* PHP 7.4 or higher.

== Changelog ==

= 0.4.0 =
* Mailing lists: synced customers can be subscribed to Notifuse lists. A single
  paid-order sync now calls lists.subscribe (which also upserts the contact)
  when list IDs are configured, or contacts.upsert otherwise.
* Optional GDPR consent checkbox at checkout (classic and block), with
  configurable label; when enabled, only customers who tick it are subscribed.
* Settings reorganised into "Synchronization" and "Mailing lists" sections.

= 0.3.0 =
* Self-updating: bundles the Plugin Update Checker library to serve new
  versions from the plugin's GitHub releases on the WordPress Plugins screen.

= 0.2.0 =
* Order sync: upserts the customer to a Notifuse contact when an order first
  enters a paid status (contacts.upsert), reusing the custom-field mappings.
* New "Province / state mapping" source for the preferred-language field.
* The WPML language mode now requires an active WPML/Polylang install and
  degrades gracefully (no value) when none is present.

= 0.1.0 =
* Initial plugin base: WooCommerce dependency guard, HPOS compatibility,
  PSR-4 autoloader, activation/deactivation lifecycle.

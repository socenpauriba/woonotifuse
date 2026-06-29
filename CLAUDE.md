# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**WooNotifuse** is a WordPress plugin that bridges **WooCommerce** → **Notifuse** (a transactional-email + newsletter platform). It hard-depends on an active, supported WooCommerce install. The Notifuse API is documented in `docs/notifuse_openapi.json` (the authoritative spec) — it is RPC-over-HTTP (`POST /api/<resource>.<verb>`), Bearer-authenticated, and nearly every call requires a `workspace_id`.

## Commands

There is no build step, package manager, or test suite — this is a plain PHP WordPress plugin loaded by a WordPress install.

- **Lint all PHP** (the de-facto check before committing):
  ```bash
  find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
  ```
- **Run it**: symlink/copy the repo into a WordPress `wp-content/plugins/` directory with WooCommerce active, then activate WooNotifuse. Settings live under **WooCommerce → WooNotifuse**.

## Architecture

Bootstrap flow (`woonotifuse.php`): define constants → register the autoloader → wire the GitHub update checker (`woonotifuse_init_update_checker()`, independent of WooCommerce so updates work even when the dependency check fails) → declare HPOS compatibility (`before_woocommerce_init`) → on `plugins_loaded` (priority 20) check `Dependencies::is_woocommerce_ready()`; if WC is missing/too old, show an admin notice and bail, otherwise call `Plugin::instance()->init()`.

**Updates** are served from GitHub releases via the bundled [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) library (`plugin-update-checker/`, v5.7, vendored). It is configured in GitHub-releases mode (no branch set → it tracks published releases, then tags, never a moving branch). To ship an update: bump the `Version` header + `WOONOTIFUSE_VERSION` + readme `Stable tag`, then publish a GitHub release tagged `vX.Y.Z`. PUC reads the version from the plugin header in the release source, so keep tag and header in sync; pre-releases are ignored.

**Autoloading** (`includes/class-autoloader.php`): PSR-4-ish. `WooNotifuse\Foo_Bar` → `includes/class-foo-bar.php`; sub-namespaces map to subdirectories (`WooNotifuse\Api\Client` → `includes/api/class-client.php`). New classes must follow this `class-{kebab-case}.php` convention or they won't load.

**`Plugin`** (`includes/class-plugin.php`) is a singleton wired up only after WooCommerce is confirmed present. Its `init()` is idempotent, loads the textdomain, registers admin-only features (`Settings`, `Order_Actions` — `is_admin()`, which also covers `admin-ajax.php`), registers the **order sync unconditionally** (`Order_Sync` — orders can become paid on the front end, in the admin, via webhooks or cron), and fires the `woonotifuse_init` action that future features should hook.

**Config & admin** is split across two WordPress options, each its own Settings-API group, both rendered as separate `<form>`s on the one settings page:
- `Settings` (`woonotifuse_settings`) — Notifuse connection (`domain`, `token`, `workspace_id`), the `auto_sync` trigger (bool, **off by default**), and the optional mailing-list config (`subscribe_lists` comma-separated IDs, `checkout_consent` bool, `consent_text`). `Settings::make_client()` builds a ready `Api\Client`; accessors: `auto_sync_enabled()`, `subscribe_list_ids()` (parsed array), `checkout_consent_enabled()`, `consent_text()` (with default fallback).
- `Field_Mappings` (`woonotifuse_field_mappings`) — the optional custom-field sync config (see below).

**`Api\Client`** (`includes/api/class-client.php`) is the only thing that talks to Notifuse. It normalizes the domain, injects the Bearer header and `workspace_id`, and **collapses every failure mode** (transport error, non-2xx, Notifuse `{ "error": ... }` body) into a `WP_Error` — callers check `is_wp_error()` and otherwise get the decoded array.

**Custom-field sync** has a clean config→compute split:
- `Field_Mappings` owns the *definitions* (`Field_Mappings::definitions()` is the single source of truth) and admin UI. Each mapping has a `type` (`number|datetime|string`) that scopes which Notifuse `custom_*_1..5` fields are offered as targets — **unless** the definition declares a `native` target (e.g. `preferred_lang` → `'native' => 'language'`), in which case it writes to that built-in contact field and the UI hides the custom-field selector.
- `preferred_lang` is a `has_modes` mapping: its value comes from one of four sources (`fixed`, `wpml`, `zipcode`, `state`). `zipcode`/`state` share one rule engine (`Field_Resolver::match_rules()` over `value = a, b, c` lines); the WPML mode requires an active WPML/Polylang install (`Field_Mappings::is_wpml_active()`) and resolves to nothing otherwise — its dropdown option is disabled when absent.
- `Field_Resolver::for_order( WC_Order )` turns the enabled mappings into a Notifuse contact payload (e.g. `[ 'custom_number_1' => 4, 'language' => 'ca' ]`). It **is** called — by `Order_Sync` when building the contact.

**Order sync** (`includes/class-order-sync.php`, `Order_Sync`) — the single sync engine, one trigger and one API call:
- Hooks `woocommerce_order_status_changed` and fires `sync_order()` the first time an order **enters** a paid status (`wc_get_is_paid_statuses()`), guarding against paid→paid re-fires. Gated by `Settings::auto_sync_enabled()`.
- `sync_order( WC_Order )` builds the contact (billing fields + `external_id` for registered users + `Field_Resolver::for_order()`) via the shared static `Order_Sync::contact_for()`, then **branches**: when mailing lists are configured **and** the consent requirement (if enabled) is met, it POSTs `api/lists.subscribe` (which upserts the contact *and* subscribes it in one call); otherwise it POSTs `api/contacts.upsert` (data only). Returns the decoded array / `WP_Error` / `null` (skipped — not configured or no email). On success it stamps `_woonotifuse_contact_synced_at` (`SYNCED_META`) always and `_woonotifuse_lists_subscribed_at` (`SUBSCRIBED_META`) when subscribed; fires `woonotifuse_contact_synced` and, when subscribed, `woonotifuse_lists_subscribed`. On failure it logs (WC logger, source `woonotifuse`) and adds an order note. Payload is filterable via `woonotifuse_contact_payload`.

**Checkout consent** (`includes/class-checkout-consent.php`, `Checkout_Consent`): optional, modular opt-in. `is_active()` (consent enabled + list IDs set) gates everything. When active it renders a configurable checkbox on **both** checkouts — classic (`woocommerce_review_order_before_submit` + capture on `woocommerce_checkout_create_order` into `_woonotifuse_consent`) and block/Store API (`woocommerce_register_additional_checkout_field`, order-scoped, stored at `_wc_other/woonotifuse/marketing-opt-in`). `Checkout_Consent::has_consent( $order )` reads either source and is consulted by `Order_Sync` at paid time. The checkbox is unticked by default.

**Manual sync** (`includes/class-order-actions.php`, `Order_Actions`, admin-only): a "Sync with Notifuse" **bulk action** (registered on both the legacy `edit-shop_order` and HPOS `woocommerce_page_wc-orders` screens) and a single-order **"Order actions"** entry. The single-order action calls `Order_Sync::sync_order()` inline. The **bulk action queues** the selection for background processing instead of looping `sync_order()` synchronously — the old loop did one HTTP call per order (each up to the client's 15s timeout) inside the redirect request and **timed out at ~50 orders**. It now chunks the IDs by `Order_Sync::BATCH_SIZE` (50) and enqueues an Action Scheduler job (`Order_Sync::BATCH_HOOK`, group `woonotifuse`) per chunk; the notice reports how many were *queued*. **Manual actions are intentionally unaffected by the `auto_sync` toggle.**

**Batch sync engine** (`Order_Sync::run_batch()` → `sync_orders_batch()`): the Action Scheduler worker (registered unconditionally in `init()`, since the queue runs in a non-admin loopback request). It builds one contact per order via `contact_for()`, **de-duplicates by email** (a customer with several selected orders is imported once; the subscribe decision is OR-ed across their orders), splits the contacts into a *subscribe* bucket and an *upsert-only* bucket, and imports each in chunks via **`api/contacts.import`** — its `subscribe_to_lists` field is the batch equivalent of `lists.subscribe`. `contacts.import` returns **200 even on partial failure** (`operations[]`, one `{email, action: create|update|error, error}` per contact, plus a global `error`); the worker maps operations back to emails to stamp `SYNCED_META`/`SUBSCRIBED_META`, fire `woonotifuse_contact_synced`/`woonotifuse_lists_subscribed`, and add failure notes per order — same side effects as `sync_order()`, just batched. If Action Scheduler is unavailable, `handle_bulk_action()` falls back to running `sync_orders_batch()` inline (still batched, not one call per order).

## Conventions & decisions that aren't obvious from the code

- **Derived contact values are always computed from WooCommerce at sync time, never accumulated/incremented against Notifuse.** Notifuse's `contacts.upsert` *sets* a field, so incrementing the remote value would drift/double-count on retries; computing is idempotent and self-healing. Keep this property when adding fields.
- **Contact address mapping** (`Order_Sync::contact_for()`): `state` is sent as the full province name ("Barcelona"), not WooCommerce's code ("B") — resolved via `WC()->countries->get_states()`, falling back to the raw value for free-text-state countries. Notifuse has no city field, so the billing city is folded into `address_line_2` (after any existing line 2).
- **Order count = paid orders only** (`processing` + `completed`, via `wc_get_is_paid_statuses()`). `Field_Resolver` counts them with `wc_get_orders()` rather than `wc_get_customer_order_count()`, which counts almost every status (including `checkout-draft`, inflated by abandoned block-checkout carts) and caches the result. Total spent uses `wc_get_customer_total_spent` (already paid-only). Both match by **customer ID** for registered users, **billing email** for guests.
- **Sync fires on a paid order status** so WooCommerce's totals already include the triggering order.
- **Automatic sync is off by default** (`auto_sync`); a store opts in explicitly. Manual bulk / order actions bypass this toggle, so an admin can always sync on demand.
- **`Order_Sync` is the one chokepoint** for talking to Notifuse about orders. Two methods: `sync_order()` (single order — automatic paid trigger + single-order action; `contacts.upsert`/`lists.subscribe`) and `sync_orders_batch()` (the queued bulk action; `contacts.import`). Both share `contact_for()` for the payload and apply identical side effects (meta, hooks, failure notes). Add sync behaviour here, not in the callers — and keep the two paths' side effects in lockstep.
- **One trigger, one call.** `lists.subscribe` is a superset of `contacts.upsert` (it upserts too), so list subscription is layered onto the same paid-order sync rather than being a second trigger. Consent is captured at checkout and read back at paid time.
- **Notifuse field formats** (verified against the Notifuse Go source `internal/domain/nullables.go`): `custom_number_*` accepts a bare JSON number (float64); `custom_datetime_*` requires an RFC 3339 string — emit `gmdate( 'Y-m-d\TH:i:s\Z', ... )`. The same `Contact` struct backs both `contacts.upsert` and `transactional.send`. A malformed datetime returns a `400 {error}` — treat upsert 400s as hard failures to surface, not silently drop.
- **The API token is a secret**: never echo a stored token back into the settings form, and preserve the existing value when the field is submitted blank (see `Settings::sanitize()`).
- Persistent data is removed in `uninstall.php`, not on deactivation — deactivating must not destroy configuration.

## Build roadmap

Agreed sequence:
1. Settings page ✅
2. API client ✅
3. Order trigger calling `Field_Resolver::for_order()` — **`contacts.upsert` done** (automatic on paid status + manual bulk/order actions). The `transactional.send` half was **deliberately deferred** (scoped to upsert only).
4. Checkout newsletter opt-in (`lists.subscribe`) — **done**, unified into the paid-order sync with an optional checkout consent checkbox.
5. Inbound webhook receiver with Standard Webhooks HMAC verification — **not started** (the remaining roadmap item).

Shipped alongside the above (not in the original sequence): GitHub-release self-updates (plugin-update-checker), the `auto_sync` toggle, the native `language` mapping target, and the `province/state` language-source mode.

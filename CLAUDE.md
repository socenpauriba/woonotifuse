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

Bootstrap flow (`woonotifuse.php`): define constants → register the autoloader → declare HPOS compatibility (`before_woocommerce_init`) → on `plugins_loaded` (priority 20) check `Dependencies::is_woocommerce_ready()`; if WC is missing/too old, show an admin notice and bail, otherwise call `Plugin::instance()->init()`.

**Autoloading** (`includes/class-autoloader.php`): PSR-4-ish. `WooNotifuse\Foo_Bar` → `includes/class-foo-bar.php`; sub-namespaces map to subdirectories (`WooNotifuse\Api\Client` → `includes/api/class-client.php`). New classes must follow this `class-{kebab-case}.php` convention or they won't load.

**`Plugin`** (`includes/class-plugin.php`) is a singleton wired up only after WooCommerce is confirmed present. Its `init()` is idempotent, loads the textdomain, registers admin features (`is_admin()` only — which also covers `admin-ajax.php`), and fires the `woonotifuse_init` action that future features should hook.

**Config & admin** is split across two WordPress options, each its own Settings-API group, both rendered as separate `<form>`s on the one settings page:
- `Settings` (`woonotifuse_settings`) — Notifuse connection: `domain`, `token`, `workspace_id`. `Settings::make_client()` builds a ready `Api\Client` from these; `Settings::all()/get()` read them.
- `Field_Mappings` (`woonotifuse_field_mappings`) — the optional custom-field sync config (see below).

**`Api\Client`** (`includes/api/class-client.php`) is the only thing that talks to Notifuse. It normalizes the domain, injects the Bearer header and `workspace_id`, and **collapses every failure mode** (transport error, non-2xx, Notifuse `{ "error": ... }` body) into a `WP_Error` — callers check `is_wp_error()` and otherwise get the decoded array.

**Custom-field sync** is the current focus and has a clean config→compute split:
- `Field_Mappings` owns the *definitions* (`Field_Mappings::definitions()` is the single source of truth) and admin UI. Each mapping has a `type` (`number|datetime|string`) that scopes which Notifuse `custom_*_1..5` fields are offered as targets.
- `Field_Resolver::for_order( WC_Order )` turns the enabled mappings into a Notifuse contact payload, e.g. `[ 'custom_number_1' => 4 ]`. It is **not yet called by anything** — it's built ahead of the order-sync trigger.

## Conventions & decisions that aren't obvious from the code

- **Derived contact values are always computed from WooCommerce at sync time, never accumulated/incremented against Notifuse.** Order count and total spent use `wc_get_customer_order_count` / `wc_get_customer_total_spent` (guests: by `billing_email`). Notifuse's `contacts.upsert` *sets* a field, so incrementing the remote value would drift/double-count on retries; computing is idempotent and self-healing. Keep this property when adding fields.
- **Sync is intended to fire on a paid order status** so WooCommerce's totals already include the triggering order.
- **Notifuse field formats** (verified against the Notifuse Go source `internal/domain/nullables.go`): `custom_number_*` accepts a bare JSON number (float64); `custom_datetime_*` requires an RFC 3339 string — emit `gmdate( 'Y-m-d\TH:i:s\Z', ... )`. The same `Contact` struct backs both `contacts.upsert` and `transactional.send`. A malformed datetime returns a `400 {error}` — treat upsert 400s as hard failures to surface, not silently drop.
- **The API token is a secret**: never echo a stored token back into the settings form, and preserve the existing value when the field is submitted blank (see `Settings::sanitize()`).
- Persistent data is removed in `uninstall.php`, not on deactivation — deactivating must not destroy configuration.

## Build roadmap

Agreed sequence: (1) settings page ✅ · (2) API client ✅ · (3) order trigger → `contacts.upsert` / `transactional.send` calling `Field_Resolver::for_order()` — **next, not started** · (4) checkout newsletter opt-in (`lists.subscribe`) · (5) inbound webhook receiver with Standard Webhooks HMAC verification.

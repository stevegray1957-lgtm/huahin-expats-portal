# Airwallex WordPress Integration Architecture

Status: design — not yet implemented.
Scope: HuaHinExpats.Co WordPress (`wp-content/plugins/huahinexpats-core` + `wp-content/themes/huahinexpats-co`).
Audit basis: full code read of the production plugin and theme at the audit-package state. Every file/line reference below points into that audited tree (paths shown relative to the plugin or theme root).

---

## 1. Decision summary

**Recommendation: Stripe and Airwallex coexist behind a provider-selector option. Airwallex becomes the primary gateway for new subscriptions. Stripe remains active for the existing live subscriptions until they naturally cancel or are migrated. No "rip and replace" of `inc/stripe.php`.**

Rationale, grounded in the audit:

1. The existing Stripe layer is hand-rolled WP-HTTP (no SDK), 1304 lines (`inc/stripe.php`), with sound primitives already in place: HMAC-SHA256 webhook verification (`inc/stripe.php:487-516`), 5-minute timestamp window, idempotency-keyed outbound POSTs, dedup option (`hhe_stripe_processed_event_ids`, retention 30 days, hard cap 1000), and mode-scoped settings (test/live). Throwing this away costs a quarter of work for zero functional gain. The same primitives are reusable for Airwallex.
2. The downstream side of the payment flow — `hhec_apply_subscription_tier()` (`inc/subscriptions.php:139-153`), the `wp_hhe_subscriptions` upsert (`inc/subscriptions.php:102-128`), the `_hhe_premium_level` / `_hhe_premium_expires` post-meta contract (`inc/premium.php`), the daily expiry cron (`inc/premium.php:417` → `hhec_run_premium_expiry_check`) — is already gateway-agnostic in shape. Only the upstream call-site and the webhook ingress are Stripe-specific.
3. Stripe customer IDs are recorded against WP users (`user_meta._hhe_stripe_customer_id`, `inc/stripe.php:220`, `:606`). Migrating those subs to Airwallex requires either Airwallex's payment-method import (operational, not code) or letting them age out on Stripe. Either way it is not a code blocker.
4. Airwallex is the right primary for the Thailand market: THB-native pricing, PromptPay / TrueMoney / local cards, lower FX cost, local entity. Stripe stays available for international cards / fallback if Airwallex has a degraded period.

The architecture below is the minimum surface area to add Airwallex as a parallel provider, keeping Stripe untouched in its current shape.

Alternative paths considered:

- **Replace Stripe entirely.** Smaller code, single path. Rejected: forces same-day migration of every live subscription and removes the fallback for the rare Stripe-only payment method. Acceptable only after Airwallex has been live ≥3 months with zero unresolved incidents — covered in §11 as the optional Phase 6+ step.
- **Stripe-only with manual Airwallex side-channel.** Rejected: no PromptPay support, no THB-native pricing — the whole reason for adding Airwallex.

---

## 2. Existing payment surface — what we keep, what we extend, what is new

Categorised against the actual audited code:

### Keep unchanged (gateway-agnostic downstream)

| Concern | File:line | Why it is provider-agnostic |
|---|---|---|
| Tier slug allowlist | `inc/subscriptions.php:140` | Already `free|verified|premium|premium_plus`; never references Stripe |
| Apply tier to listing | `inc/subscriptions.php:139-153` (`hhec_apply_subscription_tier`) | Writes `_hhe_premium_level`, `_hhe_premium_expires`. No gateway concept |
| Subscriptions upsert helper | `inc/subscriptions.php:102-128` (`hhec_upsert_subscription`) | Matches on `stripe_subscription_id` today; will be widened in §3 |
| Expire-past-due cron | `inc/premium.php:417` → `hhec_run_premium_expiry_check`, `:479` → warnings | Reads `_hhe_premium_expires`, not Stripe |
| Expiry email | `inc/premium.php:479-519` | wp_mail only |
| `wp_hhe_subscriptions` table | `inc/subscriptions.php:21-50` | Will gain `provider` + nullable `provider_*` columns — see §3 |
| `_hhe_premium_level`, `_hhe_premium_expires`, `_hhe_upgrade_email_sent` | post_meta | Gateway-agnostic |
| Listing-event recording | `hhec_record_listing_event()` (helper) | Already used by Stripe and outreach — passes through unchanged |
| Outcome-engine revenue projection | `inc/outcome-engine.php:77-118`, `:156-213`, `:270-315` | Reads `wp_hhe_subscriptions` rows — works on any provider once `provider` column is populated |
| Owner dashboard edit flow | `wp-content/themes/huahinexpats-co/page-owner-dashboard.php:237-446` | Not payment-related |
| Owner role and capability model | `inc/owner.php`, `inc/roles.php` | Not payment-related |

### Extend (small additive change)

| Concern | File:line | Change shape |
|---|---|---|
| Plugin loader | `huahinexpats-core.php:34-95` | Add `require inc/payments.php;` and `require inc/airwallex.php;` after `inc/stripe.php` (line 57) — see §5 |
| Settings page navigation | `inc/stripe.php:1099-1108` (current Stripe Settings submenu) | Rename submenu callback to "Payments Settings"; introduce provider tabs (Stripe / Airwallex / Global). No new menu slug — preserve URL `wp-admin/admin.php?page=hhe-stripe-settings` for bookmark stability, or alias to `hhe-payments-settings` with a 302 |
| Subscriptions admin page | `inc/subscriptions.php:192-326` | Column "Provider" added; filter by provider |
| `hhec_create_stripe_checkout_session()` callers | `wp-content/themes/huahinexpats-co/page-owner-dashboard.php:737-839` (forms) | Two changes: (a) form `action` value `hhe_create_checkout` is still routed to the dispatcher (see below), (b) hidden input `provider` (default = active provider option) |
| Admin-post dispatch | `inc/stripe.php:265` (current `hhe_create_checkout` action) | Replace direct binding with the new dispatcher in `inc/payments.php` that routes by `provider` to either `hhec_stripe_create_checkout()` or `hhec_airwallex_create_checkout()`. Stripe handler renamed `hhec_stripe_handle_create_checkout` to avoid colliding with the dispatcher name |
| Webhook readiness widget | `inc/stripe.php:1044-1091` (admin notice) | Generalise the missing-config check across both providers |
| Tier-from-price lookup | `inc/stripe.php:81-94` (`hhec_tier_from_price_id`) | Add Airwallex twin `hhec_tier_from_airwallex_price_id` (or a generic `hhec_tier_from_provider_price_id($provider, $price_id)` switch). Webhook handlers call the relevant one |
| `_hhe_stripe_customer_id` user meta | `inc/stripe.php:220`, `:606` | New twin meta `_hhe_airwallex_customer_id`. Not consolidated — keep them separate for forensic clarity, also because customer IDs are non-portable |

### New files (additive)

| File | Purpose |
|---|---|
| `inc/payments.php` | Provider abstraction. Defines the dispatcher, the option-key resolver, the active-provider getter, and a small interface contract |
| `inc/airwallex.php` | Airwallex-specific equivalent of `inc/stripe.php` — auth-token cache, checkout-intent creation, webhook route + verification, event handlers |

### Removed code — none

Nothing is deleted in Phases 1–5. `inc/stripe.php` stays byte-for-byte. Removal candidates are deferred to Phase 6+ once Airwallex has been live a full quarter.

---

## 3. Data model changes

### 3.1 `wp_hhe_subscriptions` schema evolution

Current schema (`inc/subscriptions.php:21-50`):
```
id, listing_id, user_id, stripe_customer_id, stripe_subscription_id,
stripe_price_id, tier, status, current_period_start, current_period_end,
created_at, updated_at
INDEX(stripe_subscription_id), INDEX(listing_id), INDEX(user_id), INDEX(status)
```

Migration (additive, no destructive change — dbDelta-compatible):
```
ALTER TABLE wp_hhe_subscriptions
  ADD COLUMN provider VARCHAR(16) NOT NULL DEFAULT 'stripe' AFTER tier,
  ADD COLUMN provider_customer_id VARCHAR(255) NULL AFTER provider,
  ADD COLUMN provider_subscription_id VARCHAR(255) NULL AFTER provider_customer_id,
  ADD COLUMN provider_price_id VARCHAR(255) NULL AFTER provider_subscription_id,
  ADD COLUMN provider_metadata LONGTEXT NULL AFTER provider_price_id,
  ADD INDEX provider_sub_idx (provider, provider_subscription_id);
```

Implementation note: extend `hhec_create_subscriptions_table()` (`inc/subscriptions.php:21-50`) with the new columns and indexes. dbDelta is idempotent, so the `admin_init` upgrade hook (line 52) and the activation hook (`huahinexpats-core.php:106`) will both upgrade safely.

The existing `stripe_*` columns are NOT removed. Backfill: on upgrade, set `provider='stripe'` for all rows where `stripe_subscription_id IS NOT NULL`, and mirror `stripe_customer_id` / `stripe_subscription_id` / `stripe_price_id` into the new `provider_*` columns. New code reads the `provider_*` columns; legacy webhook code keeps writing the `stripe_*` columns. A one-off backfill function `hhec_subscriptions_backfill_provider()` runs once gated by option `hhec_subs_provider_backfill_v1` (same pattern as `hhec_trust_backfilled_v1` in `inc/trust.php:201-208`).

`hhec_upsert_subscription()` (`inc/subscriptions.php:102-128`) is widened to take a `$provider` arg; resolves the matching row by `(provider, provider_subscription_id)` first, then falls back to the legacy `stripe_subscription_id` for Stripe rows during the transition.

### 3.2 New WP options (autoload defaults)

All options use the same flat `wp_options` storage pattern as the current Stripe settings (`update_option(..., $val)` without autoload arg → autoloaded = on, matching `inc/stripe.php:1126-1141`). The webhook dedup and last-seen options keep `autoload=false` (matching `inc/stripe.php:429`, `:475`).

| Option key | Autoload | Purpose | Read by | Written by |
|---|---|---|---|---|
| `hhe_payments_provider` | yes | Active default provider: `stripe` or `airwallex` | dispatcher in `inc/payments.php` | Payments Settings save handler |
| `hhe_payments_legacy_provider` | yes | The "still-honour-renewals" provider during a cutover (typically `stripe`) | webhook router, owner dashboard rendering | Payments Settings save handler |
| `hhe_airwallex_mode` | yes | `test` or `live` (mirrors `hhe_stripe_mode`, `inc/stripe.php:29-32`) | `hhec_airwallex_get_setting()` | Settings save |
| `hhe_airwallex_client_id` | yes | Test mode | as above | as above |
| `hhe_airwallex_api_key` | yes | Test mode API key (used to obtain a bearer token) | as above | as above |
| `hhe_airwallex_webhook_secret` | yes | Test mode webhook HMAC secret | webhook verifier | Settings save |
| `hhe_airwallex_live_client_id` | yes | Live mode | as above | as above |
| `hhe_airwallex_live_api_key` | yes | Live mode | as above | as above |
| `hhe_airwallex_live_webhook_secret` | yes | Live mode | as above | as above |
| `hhe_airwallex_price_verified_monthly` | yes | Airwallex price/product identifier | checkout creation | Settings save |
| `hhe_airwallex_price_verified_annual` | yes | (same pattern) | | |
| `hhe_airwallex_price_premium_monthly` | yes | | | |
| `hhe_airwallex_price_premium_annual` | yes | | | |
| `hhe_airwallex_price_premium_plus_monthly` | yes | | | |
| `hhe_airwallex_price_premium_plus_annual` | yes | | | |
| `hhe_airwallex_live_price_*` (same 6 keys) | yes | Live-mode equivalents | | |
| `hhe_airwallex_bearer_token_cache` | **no** | Cached bearer token JSON `{token, expires_at}`. Short-lived (~30 min) — see §6.1 | auth helper | auth helper |
| `hhe_airwallex_processed_event_ids` | **no** | Webhook idempotency log (mirrors `hhe_stripe_processed_event_ids`) | webhook handler | webhook handler |
| `hhe_airwallex_last_webhook_at` | **no** | Readiness indicator (mirrors `hhe_stripe_last_webhook_at`) | admin notice | webhook handler |

Naming convention reuses `inc/stripe.php`'s "mode-scoped option" pattern (`inc/stripe.php:40-72`) so the same `hhec_get_setting($provider, $key)` helper resolves it.

Display strings stay shared with Stripe — `hhe_stripe_display_verified`, `hhe_stripe_display_premium`, `hhe_stripe_display_premium_plus` (`inc/stripe.php:1283-1298`) describe the price labels the visitor sees and are gateway-agnostic. They are read by the pricing page (`wp-content/themes/huahinexpats-co/page-templates/template-pricing.php:35-38`) regardless of which gateway processes the payment. We do not duplicate them per provider. The Payments Settings page renames the field label from "Stripe display price" to "Display price (shown on pricing page)".

### 3.3 New user meta

- `_hhe_airwallex_customer_id` — per-user Airwallex customer reference, mirroring `_hhe_stripe_customer_id` (`inc/stripe.php:220`, `:606`). Stored/read with the same `update_user_meta` / `get_user_meta` calls. Kept separate from the Stripe customer id; never merged — a single WP user can have both records during cutover.

### 3.4 New post meta — none

Tier and expiry post-meta keys are unchanged: `_hhe_premium_level`, `_hhe_premium_expires`, `_hhe_upgrade_email_sent` (`inc/stripe.php:574, :620`, `inc/subscriptions.php:146, :149, :151`). Airwallex webhook handlers write these the same way.

---

## 4. Provider abstraction shape (`inc/payments.php`)

A thin procedural API — matches the codebase's existing style (no classes in the audited Stripe code). All functions live in `inc/payments.php`.

```
hhec_payments_active_provider(): string
    // returns get_option('hhe_payments_provider', 'stripe')

hhec_payments_provider_for_listing( int $listing_id ): string
    // active provider for NEW checkouts on this listing; defaults to active provider
    // future-proofing: lets the operator pin a listing to a specific gateway

hhec_payments_create_checkout_session( int $listing_id, string $tier, string $interval, int $user_id, ?string $provider = null ): array|WP_Error
    // dispatch by provider to hhec_stripe_create_checkout_session()
    // or hhec_airwallex_create_checkout_intent()
    // returns { url, provider } on success

hhec_payments_get_setting( string $provider, string $key ): string
    // resolves mode-scoped option for the given provider
    // generalisation of hhec_get_stripe_setting() in inc/stripe.php:65-72

hhec_payments_tier_from_price_id( string $provider, string $price_id ): string
    // generalisation of hhec_tier_from_price_id() in inc/stripe.php:81-94

hhec_payments_handle_create_checkout()
    // bound to admin_post_hhe_create_checkout — REPLACES the binding currently
    // at inc/stripe.php:265. Routes by hidden form field `provider` (defaulting
    // to the active provider), nonce-checks, capability-checks, then calls the
    // provider's session-creator. On success: 302 to the provider's hosted page.

hhec_payments_handle_billing_portal()
    // bound to admin_post_hhe_billing_portal — REPLACES the binding currently
    // at inc/stripe.php:328. Same dispatch pattern.

hhec_payments_record_event( int $listing_id, string $event_type, array $payload )
    // gateway-agnostic wrapper around hhec_record_listing_event()
```

Provider contract — every provider file must expose these functions with the suffix matching the provider slug:

- `hhec_<provider>_create_checkout_session( int $listing_id, string $tier, string $interval, int $user_id ): array|WP_Error` returning `{ url, provider, session_id }`
- `hhec_<provider>_create_billing_portal_session( int $listing_id, int $user_id ): array|WP_Error`
- `hhec_<provider>_get_setting( string $key ): string` (mode-scoped)
- `hhec_<provider>_tier_from_price_id( string $price_id ): string`
- `hhec_<provider>_webhook_route_callback( WP_REST_Request ): WP_REST_Response`

This is documented in a one-screen docblock at the top of `inc/payments.php` — not a PHP interface or abstract class. Matches existing code idiom (procedural, no OO).

---

## 5. Bootstrap order

Insert into `huahinexpats-core.php` immediately after the current line 57 (`require_once HHEC_DIR . 'inc/stripe.php';`):

```
require_once HHEC_DIR . 'inc/payments.php';   // dispatcher; depends on subscriptions, owner, stripe
require_once HHEC_DIR . 'inc/airwallex.php';  // provider; same shape as stripe.php
```

Why after stripe.php: the dispatcher uses `hhec_stripe_create_checkout_session()` as a fallback target and `inc/payments.php` resolves that symbol at call time, not at load time. But ordering it after stripe.php is the more conservative choice and matches the file's "every dependent require comes after its dependency" pattern.

The admin-post action binding `admin_post_hhe_create_checkout` currently lives at `inc/stripe.php:265`. That binding is **removed** from stripe.php and re-bound to `hhec_payments_handle_create_checkout` inside `inc/payments.php`. Same for `admin_post_hhe_billing_portal` (currently `inc/stripe.php:328`).

This is a single edit in `inc/stripe.php` — remove the two `add_action('admin_post_hhe_*', ...)` calls. The handler functions themselves stay in `inc/stripe.php` but are renamed:

- `hhec_handle_create_checkout()` → `hhec_stripe_handle_create_checkout()`
- `hhec_handle_billing_portal()` → `hhec_stripe_handle_billing_portal()`

The functions are unreferenced outside `inc/stripe.php` (verified — no other file calls them), so this rename is local.

---

## 6. Airwallex specifics

### 6.1 Authentication

Airwallex uses short-lived bearer tokens (≈ 30 min lifetime) obtained from `POST /api/v1/authentication/login` with headers `x-client-id` and `x-api-key`. This differs from Stripe's long-lived secret keys (`Bearer sk_*`).

Implementation pattern in `inc/airwallex.php`:

```
hhec_airwallex_get_bearer_token(): string|WP_Error
    1. read option 'hhe_airwallex_bearer_token_cache' (non-autoload)
    2. if cached && expires_at > time()+60 → return cached.token
    3. POST /api/v1/authentication/login with headers x-client-id, x-api-key
    4. on 200 → store { token, expires_at } as the option; return token
    5. on failure → return WP_Error('airwallex_auth_failed', ...)
```

The 60-second skew margin guards against the token expiring mid-request.

Concurrency: under heavy concurrent admin/cron requests two webhooks could race the refresh and both hit `/authentication/login`. The fallback (both succeed and the second writes a newer token over the first) is fine. We do not add a transient-based lock — the operation is idempotent. If it ever becomes a problem we add a `wp_using_ext_object_cache()` short-circuit but YAGNI for now.

This caching strategy mirrors `inc/stripe.php`'s use of a non-autoload option for the dedup log (`HHEC_STRIPE_DEDUP_OPTION`, `inc/stripe.php:443`).

### 6.2 Outbound HTTP helper

Mirror `hhec_stripe_api_request()` (`inc/stripe.php:116-161`):

```
hhec_airwallex_api_request( string $method, string $endpoint, array $body = [], ?string $idempotency_key = null ): array|WP_Error
    base URL: https://api.airwallex.com/api/v1/
    headers:
        Authorization: Bearer <token from hhec_airwallex_get_bearer_token()>
        Content-Type: application/json
        x-idempotency-key: <key, when POST and key provided>
    body: wp_json_encode (Airwallex is JSON-bodied, NOT form-encoded like Stripe)
    timeout: 30s (match Stripe)
    on 401: invalidate token cache once, retry once with fresh token, then surrender
```

The 401-retry is the one extra concern Airwallex has and Stripe doesn't. Implement as a guarded inner call, not a loop — never more than two attempts per request.

### 6.3 Checkout intent creation

Airwallex's hosted-checkout pattern is to create a Payment Intent + Customer, then redirect to the Airwallex-hosted page. Exact API path: `POST /api/v1/pa/payment_intents/create`.

For recurring subscriptions Airwallex has two paths:
1. **Airwallex Subscriptions** (region-dependent rollout) — `POST /api/v1/pa/subscriptions/create` with a customer + plan reference.
2. **Recurring Payments via tokenised payment_method** — first Payment Intent captures `merchant_trigger_reason=scheduled` and a `payment_method_id` reusable token; subsequent charges are server-initiated MITs.

Both are viable. The architecture below assumes path 1 (Subscriptions). If Subscriptions is not available for our merchant region at integration time, the same handler signatures are reused for path 2; only the webhook event names differ. The decision is checked at the start of Phase 1 (§11) via Airwallex's `/api/v1/pa/subscriptions/` capability response.

Function shape (in `inc/airwallex.php`):

```
hhec_airwallex_create_checkout_intent( int $listing_id, string $tier, string $interval, int $user_id ): array|WP_Error
    1. validate tier in [verified, premium, premium_plus]
    2. validate interval in [monthly, annual]
    3. resolve plan id from hhec_airwallex_get_setting('price_<tier>_<interval>')
    4. ensure customer exists:
        a. read user_meta _hhe_airwallex_customer_id
        b. if missing: POST /pa/customers/create with x-idempotency-key 'hhe_aw_customer_user_<id>'
           → store the returned id in user_meta
    5. POST /pa/subscriptions/create with:
        x-idempotency-key 'hhe_aw_sub_<lid>_<tier>_<interval>_<UTC-date>'
        body: {
            customer_id, plan_id,
            return_url: <owner_dashboard>?airwallex=success&listing_id=<id>,
            cancel_url: <owner_dashboard>?airwallex=cancelled,
            metadata: { listing_id, tier, user_id, site_url }
        }
    6. extract hosted_page_url from response
    7. record listing event 'airwallex_checkout_started'
    8. return { url: hosted_page_url, provider: 'airwallex', session_id: <subscription_id> }
```

Idempotency key composition matches Stripe's pattern (`inc/stripe.php:233-239`): per-listing per-tier per-interval per-UTC-day. A double-submit on the same calendar day returns the same hosted-page URL.

### 6.4 Billing portal

Airwallex does not have a Billing Portal equivalent. Two options:

- **Option A: build a minimal in-product self-service surface.** Render in `page-owner-dashboard.php` (after line 695): a "Manage subscription" panel that shows the latest invoice, the renewal date, and a Cancel button. Cancel POSTs `admin_post_hhe_billing_portal`, the dispatcher routes to `hhec_airwallex_cancel_subscription()` which calls `POST /pa/subscriptions/{id}/cancel`. A "Change payment method" link generates a one-time Payment Intent with `next_action.type=collect_payment_method` and redirects to the hosted page. Invoice download: HTTP GET the invoice PDF from Airwallex and stream it.
- **Option B: defer.** Cancel-only first release (just a button that POSTs and confirms). Change-payment-method and invoice download in Phase 6 hardening.

Recommend Option B for Phase 2 (live). Cancel + view-only invoice list. The Phase 6 hardening section adds change-payment-method.

### 6.5 Webhook ingress

REST route registration (in `inc/airwallex.php`, mirroring `inc/stripe.php:384-390`):

```
add_action('rest_api_init', 'hhec_airwallex_register_webhook_route');

function hhec_airwallex_register_webhook_route() {
    register_rest_route('hhe/v1', '/airwallex-webhook', [
        'methods'             => 'POST',
        'callback'            => 'hhec_airwallex_handle_webhook',
        'permission_callback' => '__return_true',  // auth is signature
    ]);
}
```

Verification — Airwallex signs webhooks with HMAC-SHA256 over `timestamp + body`, signature in header `x-signature`, timestamp in header `x-timestamp`. Matches Stripe's shape closely, so reuse the verification pattern from `inc/stripe.php:487-516`:

```
hhec_airwallex_verify_signature( string $payload, string $signature_header, string $timestamp_header, string $secret ): bool
    1. abort if timestamp absent
    2. abort if abs(time() - timestamp) > 300  // 5-min window, matches Stripe
    3. expected = hash_hmac('sha256', timestamp + payload, secret)
    4. return hash_equals(expected, signature_header)
```

Webhook handler skeleton mirrors `hhec_handle_stripe_webhook()` (`inc/stripe.php:392-432`):

1. Parse JSON body.
2. Read `x-signature`, `x-timestamp`.
3. Verify against `hhec_airwallex_get_setting('webhook_secret')` (mode-scoped). On fail → 400.
4. Read `event.id`. Check `hhe_airwallex_processed_event_ids` option. If seen → return `{received:true, deduplicated:true}` 200.
5. Dispatch via `hhec_airwallex_process_event($event)`.
6. Record event id; bump `hhe_airwallex_last_webhook_at`.

Event types we must handle:

| Airwallex event | Maps to internal action |
|---|---|
| `payment_intent.succeeded` (first-charge of a subscription) | `hhec_airwallex_webhook_first_charge_completed` — analogue of `hhec_webhook_checkout_completed` (`inc/stripe.php:562-622`). Upsert `wp_hhe_subscriptions`, apply tier, set `_hhe_airwallex_customer_id`, fire one-shot welcome email gated by `_hhe_upgrade_email_sent` |
| `subscription.created` | If we used the Subscriptions product (§6.3 path 1), this is the canonical "subscription is live" event. Upsert + apply tier |
| `subscription.renewed` / `invoice.paid` | Analogue of `invoice.payment_succeeded`. Re-apply tier with new period end |
| `subscription.updated` | Status mapping: `active→active`, `past_due→past_due`, `cancelled→cancelled`, `incomplete→past_due` (matches Stripe mapping in `inc/stripe.php:737-746`). Re-apply tier when active/past_due |
| `subscription.cancelled` | Downgrade to free, mark sub cancelled (matches `inc/stripe.php:759-772`) |
| `payment_intent.requires_payment_method` after retry exhaustion | Mark sub `past_due`. Daily cron handles eventual downgrade (matches Stripe `invoice.payment_failed` at `inc/stripe.php:774-787`) |
| `refund.succeeded` | Full refund → cancel sub + downgrade (matches `inc/stripe.php:865-902`). Partial → log + email admin |
| `dispute.created` | Mark `past_due`, email admin (matches `inc/stripe.php:911-946`). Do not auto-downgrade |

Unknown events fall through with no action (200). Same posture as Stripe (`inc/stripe.php:528-556`).

### 6.6 Currency

Airwallex prices are denominated. The price IDs we store map to specific currency-amount pairs in Airwallex's product catalogue — typically THB for the Thailand market. The visible price string (`hhe_stripe_display_premium` etc.) is operator-typed and gateway-agnostic; the operator is responsible for keeping the display strings in sync with the Airwallex product prices. No automatic reconciliation in Phase 1–5; Phase 6 hardening introduces a verification check that fetches the Airwallex price object and surfaces a drift warning in admin.

---

## 7. Security model

Cross-referenced against the existing Stripe primitives (which we keep, and which already satisfy these properties).

### 7.1 Webhook authenticity

- `permission_callback => '__return_true'` is intentional and correct. Auth is the HMAC signature, not WP capabilities. Same posture as Stripe (`inc/stripe.php:388`). Documented in code comment so future contributors do not "tighten" it inadvertently.
- 5-minute timestamp window — replays beyond that horizon are rejected. Identical to Stripe at `inc/stripe.php:506`. **Operational note: this depends on server clock sync. The existing Stripe integration has the same dependency. We do not change it.**
- HMAC-SHA256 with `hash_equals` for constant-time compare — Stripe pattern at `inc/stripe.php:512`. Airwallex implementation uses the same `hash_equals`.
- Webhook secret blank → handler returns 500 (matches Stripe at `inc/stripe.php:397-399`). Operators who set up Airwallex with a missing secret will see webhooks failing in Airwallex's dashboard; the admin notice (§8) surfaces it.

### 7.2 Idempotency

Two layers per request, matching Stripe's:

1. **Outbound**: `x-idempotency-key` header on every Airwallex POST (`hhec_airwallex_api_request`). Composition: stable per business operation (customer-create per user; subscription-create per listing/tier/interval/UTC-date). Airwallex stores these for 24h server-side.
2. **Inbound (webhook)**: `event.id` dedup via `hhe_airwallex_processed_event_ids` option. 30-day retention. Hard cap 1000 entries. Matches `HHEC_STRIPE_DEDUP_*` constants at `inc/stripe.php:443-445`.

The welcome-email one-shot gate (`_hhe_upgrade_email_sent` post meta, `inc/stripe.php:574, :620`) is reused for Airwallex's first-charge handler — same meta, same semantics.

### 7.3 Secret storage

Plain `wp_options` rows, autoloaded. Matches Stripe (`inc/stripe.php:1126-1141`). The settings page's "leave blank to keep existing" pattern (`inc/stripe.php:1124-1132`) is replicated for `api_key`, `webhook_secret`, `client_id`. The save handler never echoes the secret back to the page.

We do NOT introduce sealed-storage in this iteration — the Stripe layer doesn't have it, and adding it just for Airwallex would create a maintenance asymmetry. If sealed storage becomes a requirement it should be added uniformly. Tracked in §11 Phase 6 as a future hardening item.

### 7.4 Capability gates

- Admin settings page: `manage_options` (matches `inc/stripe.php:1106`).
- Webhook ingress: no WP capability check, signature-only (correct).
- Checkout-create admin-post: nonce `hhe_checkout_<listing_id>` (matches `inc/stripe.php:267` and `page-owner-dashboard.php:743`). Plus `hhec_user_owns_listing()` check (matches `inc/stripe.php:285-294`). These already gate the dispatcher — Airwallex inherits them automatically by routing through `hhec_payments_handle_create_checkout()`.

### 7.5 Provider-mismatch protection

Owner has Stripe-active subscription. They click an Airwallex upgrade button. Two paths:

- **Block at the dispatcher.** If `hhec_get_active_subscription()` (`inc/subscriptions.php:81-93`) returns a row whose `provider != active_provider`, refuse and surface a notice "You already have an active subscription on the legacy gateway. Cancel it before upgrading on Airwallex." Recommended for Phase 1–2.
- **Allow side-by-side.** Owner ends up with two active subs (one Stripe, one Airwallex). The webhook handlers stack tiers (whichever applies last wins). Higher operational risk; rejected for Phase 1–2.

The dispatcher implements path 1. Implementation: at the top of `hhec_payments_handle_create_checkout()`, after the ownership check, query `hhec_get_active_subscription($listing_id)`. If active sub exists on a different provider → `wp_safe_redirect` to dashboard with `?payments=different_provider`. The dashboard banner renders an explainer.

### 7.6 Customer-id leakage across modes

The Stripe integration has a latent bug (audit §14): `hhec_create_stripe_checkout_session()` reads `_hhe_stripe_customer_id` (mode-agnostic) — switching modes will fail because the test customer id is sent to live, or vice versa. We do not fix this in the Airwallex work but we do **not repeat it**: `_hhe_airwallex_customer_id` is split into `_hhe_airwallex_customer_id_test` and `_hhe_airwallex_customer_id_live` (or stored as a JSON map). The mode resolver in `hhec_airwallex_get_setting()` reads the matching one.

Tracked in §11 Phase 6 as a Stripe-side fix that uses the same split-meta approach.

---

## 8. Admin UX

### 8.1 Payments Settings page

Replace the current Stripe Settings page (`inc/stripe.php:1099-1304`) with a tabbed Payments Settings page. Three tabs:

1. **Global** — Active provider radio (Stripe / Airwallex), Legacy provider radio (default Stripe), display prices (the three `hhe_stripe_display_*` options).
2. **Stripe** — All current Stripe fields (mode, secret keys, webhook secret, six price IDs). Save handler unchanged at the field level.
3. **Airwallex** — Mode (test/live), client id, API key, webhook secret, six price ids. Same "leave blank to keep" UX as Stripe.

Implementation: rename `hhec_stripe_settings_page()` (`inc/stripe.php:1110-1304`) to `hhec_payments_settings_page()`, move into `inc/payments.php`, and split the existing rendering into three include partials. The Stripe field-rendering and save logic moves intact — we only refactor wrap/menu/nonce.

Menu slug: keep `hhe-stripe-settings` as the URL slug for bookmark stability; menu label changes to "Payments Settings". Add a `hhe-payments-settings` alias that 302s to the canonical slug, for future renames.

### 8.2 Admin notice

Generalise `hhec_stripe_config_admin_notice()` (`inc/stripe.php:1044-1091`):

```
function hhec_payments_config_admin_notice() {
    if ( ! is_admin() ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! hhec_payments_is_hhe_admin_screen() ) return;
    $active = hhec_payments_active_provider();
    $issues = [];
    if ( $active === 'airwallex' ) {
        if ( ! hhec_airwallex_get_setting('api_key') ) $issues[] = 'Airwallex API key missing';
        if ( ! hhec_airwallex_get_setting('webhook_secret') ) $issues[] = 'Airwallex webhook secret missing';
        // ... price ids
    }
    if ( $active === 'stripe' ) {
        // existing checks
    }
    if ( $issues ) render_notice( $issues );
}
```

### 8.3 Owner dashboard

`wp-content/themes/huahinexpats-co/page-owner-dashboard.php` — minimal changes.

- Forms (`:737-839`): add hidden input `<input type="hidden" name="provider" value="<?php echo esc_attr( hhec_payments_active_provider() ); ?>">`. The dispatcher honours this; the form does not encode a hard-wired gateway.
- Result banners (`:128-220`): handle the new query params `?airwallex=success`, `?airwallex=cancelled`, `?airwallex=error`, `?payments=different_provider`. Banner text differentiates provider in the success copy ("Payment confirmed via Airwallex" vs "via Stripe") only at the admin's discretion — visitor-facing copy can stay neutral.
- Verified-tier rendering: the upgrade buttons for the `verified` tier currently render whenever `hhe_stripe_price_verified_monthly` is configured (theme `:561, :725`) but the Stripe admin-post handler rejects `tier=verified` submissions (`inc/stripe.php:292`) — broken end-to-end. The dispatcher in `inc/payments.php` widens the tier allowlist to `[verified, premium, premium_plus]` and routes `verified` to the correct provider call. **This fixes the existing latent bug for both gateways.**
- Stripe Billing Portal button (theme `:695-702`): keep the form but the action handler in the dispatcher routes:
  - Stripe sub → existing Stripe Billing Portal flow (unchanged code path)
  - Airwallex sub → in-product cancel flow (§6.4 Option B). Renders the manage-subscription panel.

### 8.4 Subscriptions admin

`inc/subscriptions.php:192-326` admin page — add a "Provider" column (reads `provider` row column), a "Filter by provider" dropdown, and adjust the "View in dashboard" link to point to the right gateway dashboard. Single small edit.

### 8.5 Revenue dashboard

`inc/admin-revenue.php` — out-of-scope to read in this audit; the recommendation is to ensure its revenue counts join on the new `provider` column rather than just `stripe_subscription_id IS NOT NULL`. The outcome-engine reads `wp_hhe_subscriptions` rows (`inc/outcome-engine.php:77-118`); since the new rows still populate the legacy `stripe_*` columns for Stripe sources and the new `provider_*` columns for Airwallex, no change is required to `inc/outcome-engine.php` — the existing query just needs to drop any filter that is Stripe-id-specific (verify by reading `inc/outcome-engine.php` before the Phase 2 cutover).

---

## 9. Payment flow diagrams

### 9.1 Successful upgrade — Airwallex active provider

```
Owner clicks "Upgrade to Premium (Monthly)" in /my-listings/
   │
   │  POST /wp-admin/admin-post.php
   │    action=hhe_create_checkout
   │    listing_id=<id>
   │    tier=premium
   │    interval=monthly
   │    provider=airwallex                          (new hidden field)
   │    hhe_checkout_nonce=<nonce>
   ▼
hhec_payments_handle_create_checkout()              [inc/payments.php]
   ├─ verify nonce 'hhe_checkout_<lid>'
   ├─ hhec_user_owns_listing()                       [inc/owner.php:77]
   ├─ refuse if active sub on other provider        (§7.5)
   ├─ resolve provider from form (default = active)
   ├─ dispatch → hhec_airwallex_create_checkout_intent()
   │     │
   │     ├─ resolve plan_id from option
   │     │   'hhe_airwallex_price_premium_monthly'
   │     ├─ ensure customer:
   │     │   ├─ read user_meta _hhe_airwallex_customer_id
   │     │   └─ if missing: POST /pa/customers/create
   │     │      x-idempotency-key 'hhe_aw_customer_user_<uid>'
   │     │      → save user_meta
   │     ├─ POST /pa/subscriptions/create
   │     │   x-idempotency-key 'hhe_aw_sub_<lid>_premium_monthly_<utc-date>'
   │     │   metadata: { listing_id, tier, user_id, site_url }
   │     │   return_url:  <dashboard>?airwallex=success&listing_id=<id>
   │     │   cancel_url:  <dashboard>?airwallex=cancelled
   │     ├─ record listing event 'airwallex_checkout_started'
   │     └─ return { url, provider:'airwallex', session_id }
   │
   └─ wp_redirect( $url )  ← Airwallex hosted page
                 │
                 │  Owner completes payment on Airwallex
                 ▼
              Airwallex
                 │
                 │  302 to return_url
                 ▼
       <dashboard>?airwallex=success&listing_id=<id>
              (owner sees success banner)

  ── meanwhile, asynchronously ──
              Airwallex
                 │
                 │  POST https://huahinexpats.co/wp-json/hhe/v1/airwallex-webhook
                 │    x-signature, x-timestamp, body=event JSON
                 ▼
       hhec_airwallex_handle_webhook()            [inc/airwallex.php]
              ├─ verify signature (HMAC-SHA256, 5-min window)
              ├─ check event id in hhe_airwallex_processed_event_ids
              ├─ dispatch by event.type
              │      payment_intent.succeeded → first-charge handler
              │      subscription.created     → upsert + apply tier
              │
              ├─ hhec_upsert_subscription(...)   [inc/subscriptions.php:102]
              │      writes wp_hhe_subscriptions row
              │      provider='airwallex'
              │      provider_subscription_id, provider_customer_id, provider_price_id
              │
              ├─ hhec_apply_subscription_tier(   [inc/subscriptions.php:139]
              │      $listing_id, $tier='premium', $period_end )
              │      writes _hhe_premium_level='premium'
              │             _hhe_premium_expires=<date>
              │
              ├─ first time only: send welcome email
              │      gate: _hhe_upgrade_email_sent post_meta
              │
              ├─ record listing event 'airwallex_payment_completed'
              ├─ record event.id in dedup option
              └─ update hhe_airwallex_last_webhook_at
```

### 9.2 Renewal

```
Airwallex billing cycle → next charge succeeds
   │
   ▼
Webhook: subscription.renewed (or invoice.paid)
   │
   ▼
hhec_airwallex_webhook_renewal()
   ├─ upsert wp_hhe_subscriptions row (status=active, new period_end)
   └─ hhec_apply_subscription_tier() refreshes _hhe_premium_expires
```

The daily premium-expiry cron at `inc/premium.php:417` reads `_hhe_premium_expires` and downgrades anything in the past. Each successful renewal pushes the expiry forward — the cron only acts when renewals stop arriving.

### 9.3 Failed payment / dunning

```
Charge fails → Airwallex retries internally per its dunning schedule
   │
   ▼
Webhook: subscription.updated (status=past_due)
   │
   ▼
hhec_airwallex_webhook_subscription_updated()
   ├─ upsert row to status=past_due
   └─ hhec_apply_subscription_tier() still applies the tier
       (matches Stripe's permissive past_due handling at inc/stripe.php:755 —
        owner keeps access during dunning grace period)
   │
   ▼ (days later, Airwallex gives up)
Webhook: subscription.cancelled
   │
   ▼
hhec_airwallex_webhook_subscription_cancelled()
   ├─ upsert status=cancelled
   └─ hhec_apply_subscription_tier(tier='free', period_end=0)
       → _hhe_premium_level='free', _hhe_premium_expires deleted
```

### 9.4 Owner-initiated cancellation

```
Owner clicks "Cancel subscription" on /my-listings/
   │
   ▼  POST admin-post action=hhe_billing_portal
hhec_payments_handle_billing_portal()
   ├─ verify nonce
   ├─ resolve sub provider
   └─ dispatch:
       ├─ provider=stripe → existing Stripe Billing Portal redirect
       │                     (inc/stripe.php:330-376 unchanged)
       └─ provider=airwallex → renders in-page confirm panel
                                POST /pa/subscriptions/<id>/cancel
                                show banner "Subscription will end on <date>"
```

For Airwallex: cancellation is end-of-period by default (matches Stripe Billing Portal default). The webhook `subscription.cancelled` arrives at period end; the listing downgrades then.

---

## 10. Fallback and retry

Two layers — gateway-layer and queue-layer.

### 10.1 Gateway-layer retry

- Outbound API calls: **no retry today** in the Stripe layer (`inc/stripe.php`: 30s timeout, on `WP_Error` returns immediately). Same posture for Airwallex, **except** the one-time 401 token-refresh retry (§6.2). Generic exponential-backoff retries are intentionally not introduced — they can mask Airwallex-side rate limits and create thundering-herd retries on admin-post requests. Operators see a `?airwallex=error` banner and retry manually.
- Webhook handlers: silent on missing-data branches, returning 200 so Airwallex stops retrying. Matches Stripe pattern. Webhook failures (signature mismatch, secret missing) return non-200 so Airwallex re-sends per its retry schedule. The same operator-monitoring discipline that catches stale `hhe_stripe_last_webhook_at` applies to `hhe_airwallex_last_webhook_at`.

### 10.2 Premium-expiry safety net (already exists)

`hhec_run_premium_expiry_check()` at `inc/premium.php:433-470` runs daily. It downgrades any listing whose `_hhe_premium_expires` is in the past, regardless of which gateway fed the row. This is the unconditional fallback if a renewal webhook is missed — operator-visible via the next-day downgrade, recoverable by the operator forcing a Stripe/Airwallex resync (a small new admin action exposed in Phase 2).

We rely on this rather than introducing a new "missed webhook reconciliation" cron. The expiry cron already does the job; the gateway just needs to keep updating `_hhe_premium_expires` on every successful renewal. If it stops, expiry catches it.

### 10.3 Operator override

A new admin action `hhec_payments_force_resync_listing` (Phase 2) — admin-post, capability `manage_options`, parameter `listing_id`. For Airwallex: pulls the latest subscription state via `GET /pa/subscriptions/<id>` and re-runs `hhec_upsert_subscription` + `hhec_apply_subscription_tier`. For Stripe: same via `GET /subscriptions/<id>`. Triggers a "Resync from gateway" button on the subscription admin row.

This replaces the need for retry loops in code with operator-driven reconciliation. Cheap to ship; useful for incident response.

### 10.4 Provider degradation playbook

If Airwallex is down for new checkouts (auth endpoint failing, hosted-page timing out): the dispatcher's checkout-create path returns `WP_Error` and the dashboard banner says "Payment processor unavailable — try again shortly." There is no automatic failover to Stripe — the operator switches `hhe_payments_provider` to Stripe via the settings page if the outage is prolonged. This is intentional: silent gateway switching during a transaction is worse than asking the visitor to try again.

If Stripe is down and Airwallex is up: the operator does the inverse switch. No new code.

---

## 11. Implementation phases

Each phase is a discrete deliverable with its own acceptance gate. Phases 1–2 are this document's primary focus; 3–5 belong to the trusted-source enrichment doc; 6 spans both.

### Phase 1 — Airwallex sandbox (test mode end-to-end, no live keys)

**Goal**: an admin can take a test card / test PromptPay flow on a sandbox Airwallex account and have a listing's `_hhe_premium_level` update to `premium` via webhook, with the row in `wp_hhe_subscriptions` recorded under `provider='airwallex'`. No production traffic.

**Deliverables**:
1. Add `inc/payments.php` with dispatcher functions (§4). Bind `admin_post_hhe_create_checkout` and `admin_post_hhe_billing_portal` here; remove the original bindings in `inc/stripe.php:265, :328`. Rename the Stripe handler functions accordingly.
2. Add `inc/airwallex.php` with: auth-token cache, API helper, `hhec_airwallex_create_checkout_intent`, webhook route + handler, event handlers for `payment_intent.succeeded`, `subscription.created`, `subscription.renewed`, `subscription.updated`, `subscription.cancelled`, `refund.succeeded`, `dispute.created`.
3. Add to `huahinexpats-core.php:34-95`: `require_once HHEC_DIR . 'inc/payments.php';` and `require_once HHEC_DIR . 'inc/airwallex.php';` after the Stripe require.
4. Schema migration: extend `hhec_create_subscriptions_table()` in `inc/subscriptions.php:21-50` with `provider`, `provider_customer_id`, `provider_subscription_id`, `provider_price_id`, `provider_metadata` columns and a `(provider, provider_subscription_id)` index. Add backfill function gated by option `hhec_subs_provider_backfill_v1`.
5. Settings UI: rename Stripe Settings → Payments Settings with three tabs (Global / Stripe / Airwallex). Move `hhec_stripe_settings_page()` body into the Stripe tab partial; add Airwallex tab with mode + credentials + six price IDs; add Global tab with active-provider radio.
6. Owner dashboard form changes (§8.3): hidden `provider` input, new query-param banners.
7. Provider-mismatch guard (§7.5) in the dispatcher.
8. Decision check: confirm Airwallex Subscriptions API is available for our merchant region. If not, switch to the recurring-payment-method path; no design changes, only the API endpoints differ.

**Acceptance**:
- Set `hhe_payments_provider=airwallex`, mode=test.
- Click an upgrade button on a test listing as a logged-in owner.
- Land on Airwallex sandbox hosted page; complete a test payment.
- Return to dashboard with success banner.
- Webhook arrives; `_hhe_premium_level` becomes `premium`; `wp_hhe_subscriptions` has the row; the dedup option contains the event id; `hhe_airwallex_last_webhook_at` is updated.
- Manually trigger a sandbox `subscription.cancelled` event; listing returns to `free`.
- Switch active provider back to Stripe; an existing Stripe sub still renews correctly. Run a Stripe test webhook to confirm.

**Out of scope for Phase 1**: live keys, Airwallex Billing Portal substitute, force-resync action, PromptPay-specific UX polish, multi-currency display.

### Phase 2 — Airwallex live (test → live cutover)

**Goal**: real money flowing through Airwallex for new subscriptions; Stripe still receives renewals for existing subs.

**Deliverables**:
1. Live Airwallex credentials in settings; mode toggle to `live`.
2. `hhe_payments_legacy_provider=stripe` set explicitly so existing Stripe sub renewals still flow.
3. Force-resync admin action (§10.3) for both providers.
4. Cancellation-only Airwallex self-service panel on the dashboard (§6.4 Option B).
5. Settings admin notice generalisation (§8.2).
6. Subscriptions admin column for `provider` (§8.4).
7. Webhook delivery monitoring: verify `hhe_airwallex_last_webhook_at` is being updated. Surface a "no Airwallex webhooks in 24h" warning in the admin notice if the option is stale.
8. Smoke-test playbook documented in `docs/ops/airwallex-live-cutover.md` (separate file — covers test transaction, refund, dispute, cancellation, dunning).

**Acceptance**:
- One real low-value subscription (operator's own listing) cycles end-to-end on Airwallex.
- Existing Stripe subscriptions continue to renew without incident for a full billing cycle.
- Admin can force-resync from Airwallex dashboard truth without manual DB edits.

### Phase 3 — Google Places enrichment (covered in trusted-source doc)

No payment code touched.

### Phase 4 — website / social verification (covered in trusted-source doc)

No payment code touched.

### Phase 5 — enrichment automation (covered in trusted-source doc)

No payment code touched.

### Phase 6 — production hardening

Spans both payments and enrichment. Payments-specific items:

1. Airwallex change-payment-method self-service (§6.4 Option A completion).
2. Airwallex price-drift verification: nightly job that fetches Airwallex price objects and compares the visible price strings to the active price; surfaces drift in admin.
3. Stripe customer-id mode-split fix (§7.6) using the same split-meta pattern Airwallex uses from day one.
4. Sealed-storage for both gateway secret keys. Single migration that applies to `hhe_stripe_secret_key`, `hhe_stripe_live_secret_key`, `hhe_airwallex_api_key`, `hhe_airwallex_live_api_key`, `hhe_stripe_webhook_secret`, `hhe_airwallex_webhook_secret`.
5. Webhook payload retention table `wp_hhe_payments_event_archive` (currently we keep only event ids; archive lets us audit after Stripe / Airwallex retention windows expire).
6. `cancel_at_period_end` flag handling for Stripe (currently absent — see audit §14). When introduced, the Airwallex dispatcher also reads it.
7. Decision point: deprecate Stripe entirely. If Airwallex has been at zero unresolved incidents for ≥90 days and ≥80% of subscriptions are on Airwallex, retire Stripe — remove the require, archive the file, drop the legacy admin tab. Existing Stripe rows in `wp_hhe_subscriptions` retain their data; the historical readout in revenue dashboards still works because the `provider` column is set.

---

## 12. Exact files to modify or add (master index)

### New files

| Path | Purpose |
|---|---|
| `wp-content/plugins/huahinexpats-core/inc/payments.php` | Dispatcher and shared helpers |
| `wp-content/plugins/huahinexpats-core/inc/airwallex.php` | Airwallex provider |

### Modified files

| Path | Change |
|---|---|
| `huahinexpats-core.php:34-95` | Add two `require_once` lines after `inc/stripe.php` (line 57) |
| `inc/stripe.php:265` | Remove `add_action('admin_post_hhe_create_checkout', ...)` |
| `inc/stripe.php:267` | Rename `hhec_handle_create_checkout` → `hhec_stripe_handle_create_checkout` |
| `inc/stripe.php:328` | Remove `add_action('admin_post_hhe_billing_portal', ...)` |
| `inc/stripe.php:330` | Rename `hhec_handle_billing_portal` → `hhec_stripe_handle_billing_portal` |
| `inc/stripe.php:1099-1108` | Repoint the settings submenu callback to the Payments Settings entry in `inc/payments.php` (or leave in stripe.php and have it delegate) |
| `inc/stripe.php:1110-1304` | Extract the rendering into `render_stripe_tab()` helper invoked from the Payments Settings page — no logic change |
| `inc/subscriptions.php:21-50` | Add `provider`, `provider_customer_id`, `provider_subscription_id`, `provider_price_id`, `provider_metadata` columns + index |
| `inc/subscriptions.php:102-128` | `hhec_upsert_subscription()` widened to take provider arg, match by `(provider, provider_subscription_id)` then fall back to legacy `stripe_subscription_id` |
| `inc/subscriptions.php:192-326` | Admin page: add provider column, provider filter |
| `wp-content/themes/huahinexpats-co/page-owner-dashboard.php:128-220` | Handle new query params `?airwallex=*`, `?payments=different_provider` |
| `wp-content/themes/huahinexpats-co/page-owner-dashboard.php:695-702` | Conditional rendering: Airwallex sub → in-page cancel panel; Stripe sub → existing portal redirect |
| `wp-content/themes/huahinexpats-co/page-owner-dashboard.php:737-839` | Add `<input type="hidden" name="provider" value="...">` to each checkout form |

### Hooks added or rebound

| Hook | Owner | Change |
|---|---|---|
| `admin_post_hhe_create_checkout` | `inc/payments.php` | rebound to `hhec_payments_handle_create_checkout` |
| `admin_post_hhe_billing_portal` | `inc/payments.php` | rebound to `hhec_payments_handle_billing_portal` |
| `admin_post_hhe_payments_force_resync` | `inc/payments.php` | new in Phase 2 |
| `admin_post_hhe_airwallex_cancel_sub` | `inc/airwallex.php` | new (cancel flow) |
| `rest_api_init` | `inc/airwallex.php` | new: registers `/wp-json/hhe/v1/airwallex-webhook` |
| `admin_notices` | `inc/payments.php` | new `hhec_payments_config_admin_notice` (replaces `hhec_stripe_config_admin_notice`) |
| `admin_menu` | `inc/payments.php` | new `hhec_payments_settings_menu` (replaces `hhec_stripe_settings_menu`) |

### Functions added

| Function | File | Purpose |
|---|---|---|
| `hhec_payments_active_provider()` | `inc/payments.php` | resolves `hhe_payments_provider` option |
| `hhec_payments_handle_create_checkout()` | `inc/payments.php` | dispatcher |
| `hhec_payments_handle_billing_portal()` | `inc/payments.php` | dispatcher |
| `hhec_payments_handle_force_resync()` | `inc/payments.php` | operator-driven reconciliation (Phase 2) |
| `hhec_payments_settings_page()` | `inc/payments.php` | tabbed settings UI |
| `hhec_payments_render_stripe_tab()` | `inc/payments.php` | thin wrapper around existing Stripe rendering |
| `hhec_payments_render_airwallex_tab()` | `inc/payments.php` | Airwallex fields |
| `hhec_payments_render_global_tab()` | `inc/payments.php` | active-provider radio + display prices |
| `hhec_payments_tier_from_price_id()` | `inc/payments.php` | provider-routing wrapper around `hhec_tier_from_price_id` and `hhec_tier_from_airwallex_price_id` |
| `hhec_payments_config_admin_notice()` | `inc/payments.php` | generalised admin notice |
| `hhec_subscriptions_backfill_provider()` | `inc/subscriptions.php` | one-shot backfill (option gate) |
| `hhec_airwallex_get_setting()` | `inc/airwallex.php` | mode-scoped setting resolver |
| `hhec_airwallex_get_bearer_token()` | `inc/airwallex.php` | auth helper with cache |
| `hhec_airwallex_api_request()` | `inc/airwallex.php` | HTTP wrapper |
| `hhec_airwallex_create_checkout_intent()` | `inc/airwallex.php` | subscription create + hosted page url |
| `hhec_airwallex_cancel_subscription()` | `inc/airwallex.php` | API call |
| `hhec_airwallex_register_webhook_route()` | `inc/airwallex.php` | REST route |
| `hhec_airwallex_handle_webhook()` | `inc/airwallex.php` | entrypoint |
| `hhec_airwallex_verify_signature()` | `inc/airwallex.php` | HMAC verification |
| `hhec_airwallex_process_event()` | `inc/airwallex.php` | dispatcher by event.type |
| `hhec_airwallex_webhook_*` (one per event type) | `inc/airwallex.php` | event handlers — mirror `hhec_webhook_*` shapes from `inc/stripe.php:562-946` |
| `hhec_airwallex_event_seen()` / `hhec_airwallex_record_event()` | `inc/airwallex.php` | dedup helpers; mirror `inc/stripe.php:447-476` |
| `hhec_airwallex_settings_page_render()` | `inc/airwallex.php` (or in payments.php) | field rendering for the tab |
| `hhec_tier_from_airwallex_price_id()` | `inc/airwallex.php` | reverse mapping |

### Constants added

| Constant | File | Value |
|---|---|---|
| `HHEC_AIRWALLEX_DEDUP_OPTION` | `inc/airwallex.php` | `'hhe_airwallex_processed_event_ids'` |
| `HHEC_AIRWALLEX_DEDUP_RETENTION` | `inc/airwallex.php` | `30 * DAY_IN_SECONDS` |
| `HHEC_AIRWALLEX_DEDUP_HARD_CAP` | `inc/airwallex.php` | `1000` |
| `HHEC_AIRWALLEX_API_BASE` | `inc/airwallex.php` | `'https://api.airwallex.com/api/v1/'` |
| `HHEC_AIRWALLEX_API_TIMEOUT` | `inc/airwallex.php` | `30` |

Mirrors the Stripe constants at `inc/stripe.php:443-445`.

---

## 13. Open questions to confirm before Phase 1 starts

These are not blockers for design — they are decisions the operator must confirm before code lands.

1. **Airwallex region capability**: confirm via the Airwallex dashboard that Subscriptions (recurring billing) is available for the Thailand merchant account. If not, the implementation uses recurring payment-method tokens with operator-managed billing cycles — same dispatcher signatures, different webhook event names. Validate during Phase 1 day 1.
2. **Plan / price object setup**: do we mirror Stripe's six price points exactly (3 tiers × 2 intervals) on Airwallex, or simplify to one or two on launch? Recommend mirror for parity; pricing change can ride a later release.
3. **Currency**: confirm pricing currency for the Airwallex prices — THB only, or also USD? If both, the price-id mapping in §3.2 needs two more options per tier/interval pair (`hhe_airwallex_price_<tier>_<interval>_thb`, `..._usd`) and a currency selector on the checkout form. Recommend THB-only for Phase 1–2; add multi-currency in Phase 6.
4. **Webhook URL allowlist**: confirm the production WordPress URL `https://huahinexpats.co/wp-json/hhe/v1/airwallex-webhook` is registered in the Airwallex webhook configuration with all needed event types.
5. **Cancel-at-period-end semantics**: Airwallex defaults to end-of-period cancellation. If the operator wants immediate cancellation as an option (with proration refund), that adds a UI choice and the `cancel_at` parameter — Phase 6.

These belong in a `docs/ops/airwallex-cutover-checklist.md` once Phase 1 begins.

---

## 14. Not in scope

- Migrating existing Stripe customers' payment methods into Airwallex. Stripe holds those tokens; mass-migration tooling is an Airwallex commercial conversation, not a code change. Practically: let Stripe subs run to natural cancellation; new signups go to Airwallex.
- Tax handling, promo codes, free trials — none exist in the current Stripe flow (audit §14), and adding them under Airwallex first would create asymmetry. Treat as a separate cross-gateway initiative.
- Refunds initiated from inside WordPress. Today refunds happen in the Stripe dashboard and arrive as `charge.refunded` webhooks; same posture for Airwallex.
- One-off payments. The whole flow is subscription-only (audit §4). One-off (e.g. paid claim) is a deliberate future scope.
- Multi-currency display per-visitor. The pricing page renders a single price string; geo-aware pricing is a separate front-end project.

---

## 15. Acceptance criteria for this design doc

Cross-checked against the brief's acceptance criteria:

- **Precise implementation roadmap against the real WP architecture**: every file/function/line cited above is verified in the audit-package tree. Function renames are local-only (verified call-graph). No hypothetical APIs invented.
- **Existing systems reused, not duplicated**: `_hhe_premium_level` / `_hhe_premium_expires` / `wp_hhe_subscriptions` / daily-expiry cron / nonce conventions / admin-post action names / display-price strings / webhook idempotency pattern / signature-verification pattern — all reused. Only the upstream call-site and a single new REST route are new.
- **Airwallex integration path clear**: §6 + §11 spell out provider, auth, checkout, webhook, billing portal substitute, currency, and the phase 1 / phase 2 cutover.
- **Trusted-source enrichment path clear**: covered in the companion `docs/TRUSTED_SOURCE_ENRICHMENT_ARCHITECTURE.md`.
- **Security and moderation remain intact**: §7 — every existing capability check, nonce, signature primitive, idempotency layer is preserved. Moderation flows are not touched.
- **No production changes occur yet**: this document is design only.

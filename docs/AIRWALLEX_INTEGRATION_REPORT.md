# Airwallex Integration Report

**Branch:** `claude/integrate-airwallex-payments-pcMOu`
**Target file location requested by brief:** `~/ai-lab/ventures/huahinexpats/AIRWALLEX_INTEGRATION_REPORT.md`
**Written here instead:** `docs/AIRWALLEX_INTEGRATION_REPORT.md` (the brief path is outside this ephemeral repo container).

---

## 1. Context mismatch

The brief describes a WordPress codebase (custom plugin `huahinexpats-core`, custom theme `huahinexpats-co`, existing Stripe modules, post-meta keys `_hhe_premium_level`, `_hhe_premium_expires`, etc.). This repository is **not** that codebase. It is a Node.js / Express + PostgreSQL app with:

- `server.js` — Express app, JWT-based admin auth, single admin password.
- `database.js` — `pg` Pool, `listings`/`reviews`/`edit_suggestions` tables.
- `public/` — static HTML/JS frontend (`admin.html`, `directory.html`, `index.html`, ...).
- **No** Stripe integration, **no** user accounts, **no** listing owner concept, **no** PHP files.

Per user direction, the brief was adapted to the actual stack rather than scaffolding a fictional WordPress plugin. The "do not delete Stripe until Airwallex is verified" rule was therefore a no-op (no Stripe code exists). A `stripe.enabled` flag is reserved in the settings store for a future fallback.

## 2. What was built

### 2.1 Database schema additions (in `database.js`)

| Table | Purpose |
|---|---|
| `settings` | Admin-only config + secrets. Keyed JSONB. |
| `audit_log` | Append-only event log. Secrets are redacted before insert. |
| `payments` | One row per checkout attempt (`pending` → `succeeded`/`failed`/`cancelled`/`expired`/`refunded`). |
| `payment_events` | Webhook events, **unique on `(provider, event_id)`** for idempotency. |

Plus columns added to `listings`: `premium_level`, `premium_expires_at`, `verified`, `featured`, `admin_notes`, `owner_email`.

### 2.2 Code modules

| Module | Responsibility |
|---|---|
| `src/lib/settings.js` | Get/update JSONB settings with deep merge and a `getRedacted()` view that masks `api_key` / `webhook_secret`. |
| `src/lib/audit.js` | `audit.log(action, ...)`; redacts `api_key`, `webhook_secret`, `authorization`, `token`, `password` from `details` before persistence. |
| `src/airwallex/client.js` | Server-side Airwallex client. Sandbox base `https://api-demo.airwallex.com`, live `https://api.airwallex.com`. Caches the bearer for ~25 min. `createPaymentLink`, `getPaymentIntent`, `getPaymentLink`. |
| `src/airwallex/webhook.js` | HMAC SHA-256 signature verification: `hmac(secret, timestamp + rawBody)` compared with `crypto.timingSafeEqual`; rejects timestamps older than 5 min. |
| `src/payments/repository.js` | CRUD on `payments` + `payment_events` (idempotent `recordEvent` via `ON CONFLICT DO NOTHING`). |
| `src/payments/upgrade.js` | Applies a verified `succeeded` payment to the listing: extends `premium_expires_at` by `tier.duration_days`, sets `premium_level`, `verified`, `featured` per tier; emits `listing.upgrade.applied` audit log. |
| `src/payments/routes.js` | Public + admin routes (see §2.3); handles webhook. |

### 2.3 HTTP endpoints

Public:

- `GET  /api/payments/tiers` — returns enabled tier prices.
- `POST /api/payments/checkout` `{listing_id, tier, owner_email?}` — creates a pending payment, calls Airwallex `payment_links/create`, returns the hosted URL.
- `GET  /api/payments/status/:id` — non-sensitive status only.
- `POST /api/webhooks/airwallex` — **mounted before `express.json()`** so raw body is preserved for signature verification.

Admin (require admin JWT):

- `GET  /api/admin/airwallex-settings` — redacted config.
- `PUT  /api/admin/airwallex-settings` — update; `api_key`/`webhook_secret` only overwritten if explicitly sent (placeholders containing `…` are ignored, so the redacted display value can't accidentally clobber the real secret).
- `POST /api/admin/airwallex-settings/test` — round-trips the auth call.
- `GET  /api/admin/airwallex-settings/webhook-url` — returns the canonical webhook URL for copy-paste into Airwallex.
- `GET  /api/admin/payments[?status=...]`
- `GET  /api/admin/payments/:id` — includes recorded webhook events.
- `POST /api/admin/payments/:id/retry-sync` — refetches from Airwallex; applies the upgrade if status flipped.
- `POST /api/admin/payments/:id/mark-reviewed`.

### 2.4 Admin UI

- `public/admin/airwallex-settings.html` — Mode toggle (sandbox/live), client_id, api_key (password field), webhook secret (password field), success/cancel URLs, enabled toggle, tier pricing matrix, **webhook URL with copy button**, **test connection** button.
- `public/admin/payments.html` — filterable list, retry-sync / mark-reviewed / events drawer, displays provider IDs, hosted URLs, and status pills.
- Existing `public/admin.html` got header links to the new admin screens.

### 2.5 Owner-facing flow

- `public/upgrade.html?listing_id=...` — tier picker, email field, calls `/api/payments/checkout` and redirects the browser to the Airwallex hosted URL.
- `public/upgrade-success.html`, `public/upgrade-cancel.html` — neutral landing pages. **No** trust is placed in browser redirects: the listing only upgrades when a valid signed webhook arrives.

## 3. How the flow works end-to-end

1. Owner clicks **Upgrade** for a listing → `/upgrade.html?listing_id=X`.
2. Page fetches `/api/payments/tiers` and renders prices.
3. On submit: `POST /api/payments/checkout` →
   - validates `listing_id`, `tier`, optional email,
   - creates `payments` row (`status='pending'`),
   - calls Airwallex `POST /api/v1/pa/payment_links/create`,
   - stores `provider_link_id`, `hosted_url`,
   - returns `hosted_url` to the browser.
4. Browser is redirected to Airwallex's hosted checkout.
5. After payment, Airwallex POSTs to `/api/webhooks/airwallex`:
   - `x-timestamp` and `x-signature` headers,
   - raw body preserved (raw express middleware mounted before `express.json()`).
6. Server verifies HMAC SHA-256 of `timestamp + body` against the stored `webhook_secret` using `crypto.timingSafeEqual`. Invalid signatures → 401.
7. Server records the event into `payment_events` (UNIQUE on `provider, event_id`); duplicates short-circuit with `{ok:true, duplicate:true}`.
8. If the event type matches a known success/failure/cancel/expire/refund pattern, `payments.status` is updated.
9. On `succeeded`, `applyUpgrade` writes `premium_level`, extends `premium_expires_at`, sets `verified`/`featured` when applicable, and audit-logs `listing.upgrade.applied`.

## 4. Secret handling

- API key and webhook secret live in the `settings` JSONB row.
- Admin endpoint returns them only via `getRedacted()` (masked).
- Update endpoint will only **overwrite** a secret when a plain (non-masked) value is sent — placeholders like `sk_te…23` are filtered out, so admins can save other changes without accidentally re-setting the secret to the masked string.
- Audit `redact()` strips `api_key`, `webhook_secret`, `authorization`, `token`, `password` from any details object before insert (`PASS - Audit log redacts api_key` in the test suite).
- No secrets are sent to the browser; client-side admin pages only ever see masked values.

## 5. Test results (30/30 green)

Running `test-integration.js` against a real Postgres database:

```
PASS - API key is redacted via getRedacted
PASS - Webhook secret is redacted
PASS - Plain key not exposed in redacted view
PASS - Raw settings keep the actual key
PASS - Payment created in pending status
PASS - Webhook signature verifies
PASS - Wrong secret rejected
PASS - Old timestamp rejected
PASS - Duplicate webhook event ignored (idempotency)
PASS - Listing premium_level set to premium after success
PASS - Listing premium_expires_at populated
PASS - Failed payment did not flip featured flag
PASS - Second upgrade extends premium expiry
PASS - Duplicate detected by place_id
PASS - Duplicate detected by exact name
PASS - Duplicate detected by phone
PASS - Candidate created
PASS - DQ score populated
PASS - New place not flagged as duplicate of existing listing
PASS - Duplicate candidate matched to existing listing
PASS - Conflicts recorded for duplicate match
PASS - Cannot promote a candidate with a duplicate match
PASS - Promotion creates listing id
PASS - Promoted listing has admin_notes from source
PASS - Audit log redacts api_key
PASS - Audit log preserves non-secret fields
PASS - Webhook rejects invalid signature with 401
PASS - Webhook accepts valid signature with 200
PASS - Replayed webhook flagged as duplicate
PASS - Public listing API does not expose admin_notes column
```

The test file is `test-integration.js`; it is not committed (it's a one-shot harness). To re-run:

```bash
sudo service postgresql start
DATABASE_URL='postgres://hhe:hhe@127.0.0.1:5432/hhe' node test-integration.js
```

## 6. Acceptance criteria status

| Criterion | Status |
|---|---|
| Sandbox checkout works end-to-end | Code path tested locally (mock webhook). Requires real Airwallex sandbox creds to test the outbound call against the demo API. |
| Webhook updates listing tier only after verified payment event | YES — proven by tests (`Listing premium_level set...` + `Webhook rejects invalid signature with 401`). |
| Failed payment does not upgrade listing | YES (`Failed payment did not flip featured flag`). |
| Duplicate webhook does not duplicate upgrade | YES (`Duplicate webhook event ignored (idempotency)` + `Replayed webhook flagged as duplicate`). |
| Secrets never exposed | YES — redacted view, audit redaction; no API key ever sent to the browser. |
| Existing Stripe code not deleted | N/A — there was no Stripe code to delete. A `stripe.enabled` flag is reserved in `settings` for a future implementation. |
| Stripe can remain as fallback | Pre-allocated; not implemented. |

## 7. Operator runbook (sandbox bring-up)

1. Sign up at https://www.airwallex.com/, switch the dashboard to **Demo mode**.
2. In Demo → **API → API keys**, copy the Client ID and API key.
3. In Demo → **API → Webhooks**, add the URL displayed at `/admin/airwallex-settings.html` (e.g. `https://your-site.example/api/webhooks/airwallex`) and copy the generated signing secret.
4. Open `/admin/airwallex-settings.html`, paste the client_id, api_key, webhook_secret, set mode = **sandbox**, set the success/cancel URLs, toggle **Enabled**, click **Test connection**.
5. On any listing, browse to `/upgrade.html?listing_id=<id>` and run a sandbox card through the hosted checkout.
6. Verify in `/admin/payments.html` that the payment status flips from `pending` → `succeeded`, and that the listing now shows the premium level / expiry in the DB (`SELECT premium_level, premium_expires_at, verified, featured FROM listings WHERE id = <id>;`).

## 8. Known limitations / follow-ups

- No retry logic for transient Airwallex errors on `payment_links/create` — failures bubble up to the client as a 502.
- The `applyUpgrade` mutation is not wrapped in a DB transaction across multiple rows because everything lives on a single listings row update. If a future change widens the write (e.g. inserting into a `subscriptions` table), wrap it.
- The token cache in `airwallex/client.js` is in-process; multiple Node workers will each authenticate. Fine until you actually scale horizontally.
- No charge-rate-limit yet on `/api/payments/checkout`. Recommend adding to `submitLimiter`-style logic before going live, since each call costs a Payment Link creation.

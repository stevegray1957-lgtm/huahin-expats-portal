# Postmark Transactional Email Architecture

Status: design — Phase 1 implementation lands in the same commit as this doc.
Scope: **transactional email only.** Marketing/newsletter automation is explicitly out of scope and deferred.
Audit basis: full read of `inc/email-identity.php`, `inc/deliverability.php`, `inc/email-outreach.php`, plus the four billing-adjacent `wp_mail()` callers in `inc/stripe.php` and `inc/premium.php`.

This doc is the implementation contract for Phase 1. The broader deliverability design (per-Server SMTP isolation, BIMI, newsletter Broadcast streams) lives in [`EMAIL_DELIVERABILITY_ARCHITECTURE.md`](./EMAIL_DELIVERABILITY_ARCHITECTURE.md) and is deferred.

---

## 1. Scope boundary — what counts as transactional

Transactional email in this codebase is mail that:
- Is triggered by a specific user-initiated event (a form submission, a Stripe webhook, a cron tick observing state).
- Is sent to a single, known recipient.
- Carries information the recipient is reasonably expecting because of their relationship to the site.

Examples that are in scope:
- Claim form submission notice to admin (`inc/claims.php`)
- Claim approval confirmation to claimant (`inc/email-outreach.php:618`)
- Stripe upgrade success receipt to listing owner (`inc/stripe.php:689`)
- Stripe refund / dispute admin notices (`inc/stripe.php:978`, `:1010`)
- Stripe unmapped-event admin notice (`inc/stripe.php:1030`)
- Premium expiry warning (`inc/premium.php:550`)
- Reader review submission notice (`inc/reviews.php`)
- Outreach invitation / reminders to claim — borderline "transactional"; they are sent to a list. Treated as transactional in this codebase because they're 1:1 sends with personalisation and a List-Unsubscribe header.
- WordPress core mail (password reset, new-user notifications, admin emails)

Examples explicitly NOT in scope this sprint:
- Newsletter blasts (`inc/newsletter.php`) — separate subsystem, stays on the same SMTP relay but its routing is not redesigned now.
- Marketing automation (drip sequences, re-engagement campaigns).
- Anything that requires Postmark Broadcast streams.

The Phase 1 implementation does not move newsletter mail off the shared relay. It will continue to send through Postmark's `outbound` stream at the same Server. A later phase splits it.

---

## 2. Current state — what the audit shows

The existing email subsystem is more mature than expected. Three files contain almost everything:

### 2.1 `inc/email-identity.php` (181 lines)

Constants `HHE_EMAIL_INFO`, `HHE_EMAIL_ACCOUNTS`, `HHE_EMAIL_LEGAL`, `HHE_EMAIL_FROM_NAME` defined at `:24-27`, overridable via `wp-config.php` `define()`. `hhec_email_address($role)` resolver at `:35-41` returns the relevant address. Default From: filters at `:164-180` rewrite the WordPress "wordpress@host" placeholder to `info@`. `hhec_sync_admin_email()` at `:67-72` forces `get_option('admin_email')` to `info@` on `admin_init`.

**Gap:** addresses are constants only. The brief requires admin-editable values. Phase 1 introduces `hhe_email_*` wp_options that the resolver checks first, falling back to the constants.

### 2.2 `inc/deliverability.php` (391 lines)

`hhec_smtp_settings()` at `:25-35` reads `hhe_smtp_enabled`, `hhe_smtp_provider`, `hhe_smtp_host`, `hhe_smtp_port`, `hhe_smtp_encryption`, `hhe_smtp_username`, `hhe_smtp_password`. `hhec_smtp_setup_phpmailer()` at `:55-71` hooks `phpmailer_init` and applies the credentials. Provider presets at `:42-49` for sendgrid / ses / mailgun / custom — **no Postmark**. DNS checks at `:96-115` probe SPF / DKIM / DMARC with a 6h transient cache. Click tracking REST route at `/wp-json/hhe/v1/email-click` (`:195-200`). Reply webhook at `/wp-json/hhe/v1/email-reply` (`:202-206`). Bounce handling via `wp_mail_failed` at `:368-391` marks `wp_hhe_email_log` rows as failed.

**Gap:** no Postmark preset. DKIM probe doesn't know Postmark's auto-generated dated selectors — relies on operator pasting selector into `hhe_smtp_dkim_selector`.

### 2.3 `inc/email-outreach.php` (1143 lines)

`hhec_email_send($args)` at `:324-408` is the central wrapper. Accepts `from_role` (`info|accounts|legal`), `reply_to`, `category`, `listing_id`, `respects_test`, `respects_supp`. Sets per-send `wp_mail_from`/`wp_mail_from_name` filters at priority 99. Hits `wp_mail()`. Logs to `wp_hhe_email_log`. Includes the List-Unsubscribe headers (`:366-367`) — RFC 8058 compliant. Suppression list at `wp_hhe_email_suppression` (`:55-65`). HMAC-signed unsubscribe tokens (`:118-125`). Plain-page unsubscribe handler (`:142-198`).

**Gap:** four `wp_mail()` callers in `inc/stripe.php` and `inc/premium.php` bypass the wrapper and therefore route through the default `info@` From: instead of `accounts@`. Phase 1 wraps them.

### 2.4 Bypass call sites

Five `wp_mail()` calls that go directly to WordPress core without passing through `hhec_email_send()`:

| File:line | Function | Today's From: | Target From: |
|---|---|---|---|
| `inc/stripe.php:689` | `hhec_send_upgrade_success_email` | info@ (default filter) | **accounts@** |
| `inc/stripe.php:978` | `hhec_send_admin_notice_refund` | info@ | **accounts@** |
| `inc/stripe.php:1010` | `hhec_send_admin_notice_dispute` | info@ | **accounts@** |
| `inc/stripe.php:1030` | `hhec_send_admin_notice_unmapped_event` | info@ | **accounts@** |
| `inc/premium.php:550` | `hhec_send_premium_expiry_warning_email` | info@ | **accounts@** |

All five are billing-adjacent. The default `wp_mail_from` filter at `inc/email-identity.php:164-180` only rewrites the "wordpress@host" placeholder — it does not touch these calls because PHPMailer's From: is already populated (these calls don't set a custom From: header but wp_mail's default uses what WP previously filtered). Net effect today: all five send as `info@`. The fix is to route them through `hhec_email_send($args)` with `from_role => 'accounts'`.

---

## 3. Phase 1 design

### 3.1 Three pillars

1. **Admin page** — `Hua Hin Expats → Email Settings` (slug `hhe-email-settings`). Provider presets, transport credentials, role addresses, From: name, Reply-To, test recipient, enable toggle, test-send button, DNS panel.
2. **Address resolver** — `hhec_email_address($role)` checks options first, falls back to constants. Address values become admin-editable without losing the `wp-config.php` define override for staging mirrors.
3. **Billing-mail re-routing** — five `wp_mail()` callers wrapped in `hhec_email_send()` with `from_role => 'accounts'`.

The Phase 1 build keeps SMTP as a single shared credential set — no per-role tokens, no per-Server Postmark isolation. That isolation is Phase 2 in [`EMAIL_DELIVERABILITY_ARCHITECTURE.md`](./EMAIL_DELIVERABILITY_ARCHITECTURE.md) §11. Doing both Phase 1 and that Phase 2 in one sprint would introduce two unrelated regressions surfaces; we do Phase 1 cleanly first.

### 3.2 Option schema

All Phase 1 options are flat `wp_options` rows. Naming matches the existing convention (`hhe_smtp_*` for transport, `hhe_email_*` for addresses).

| Option key | Type | Default | Purpose |
|---|---|---|---|
| `hhe_smtp_enabled` | bool ('0'/'1') | '0' (existing) | Master toggle for SMTP transport |
| `hhe_smtp_provider` | string | 'custom' (existing) | One of postmark / sendgrid / ses / mailgun / custom |
| `hhe_smtp_host` | string | '' (existing) | SMTP hostname |
| `hhe_smtp_port` | int | 587 (existing) | SMTP port |
| `hhe_smtp_encryption` | string | 'tls' (existing) | tls / ssl / none |
| `hhe_smtp_username` | string | '' (existing) | API token for Postmark (literal `apikey` for SendGrid, IAM user for SES, etc.) |
| `hhe_smtp_password` | string | '' (existing) | API token for Postmark (same value as username); never echoed in admin |
| `hhe_smtp_dkim_selector` | string | '' (existing) | Operator-pasted; used by DNS probe |
| `hhe_email_from_name` | string | '' (NEW) | Display name; falls back to `HHE_EMAIL_FROM_NAME` constant |
| `hhe_email_info` | string | '' (NEW) | info@ override; falls back to `HHE_EMAIL_INFO` constant |
| `hhe_email_accounts` | string | '' (NEW) | accounts@ override; falls back to `HHE_EMAIL_ACCOUNTS` constant |
| `hhe_email_legal` | string | '' (NEW) | legal@ override; falls back to `HHE_EMAIL_LEGAL` constant |
| `hhe_email_replyto` | string | '' (NEW) | Default Reply-To; falls back to `info@` |
| `hhe_email_test_recipient` | string | '' (NEW) | Used by the test-send tool |
| `hhec_email_settings_last_test` | array (NEW, non-autoload) | [] | Last test send result; redacted on render |

No new constants. No new table — Phase 1 logging reuses `wp_hhe_email_log` (already exists; populated by `hhec_email_send()` at `:396-405` and `:414-441`).

### 3.3 Resolver fallback chain

Address values resolve through this chain (highest priority first):

1. `wp-config.php` `define( 'HHE_EMAIL_INFO', ... )` — operator override, immutable from WP admin. Used for staging mirrors that need different domains.
2. wp_option `hhe_email_info` — admin-editable via the new Email Settings page.
3. The constant default value (e.g. `'info@huahinexpats.co'`) defined at `inc/email-identity.php:24-27`.

Implementation: the `if ( ! defined(...) ) define(...)` guards at the top of `inc/email-identity.php` ensure the constants are always defined. The resolver `hhec_email_address($role)` checks the option, returning the constant as fallback. A `define()` in wp-config.php takes precedence over the wp-config-load-time constant value, which the resolver returns when the option is blank.

This satisfies "addresses must be admin-editable" and "operator can lock addresses via wp-config" simultaneously.

### 3.4 Admin page surface

Submenu: `hhe-reviews` (existing parent, registered at `inc/reviews.php:214-222`) → `Email Settings` (slug `hhe-email-settings`, cap `manage_options`).

Sections (top to bottom):

1. **Transport** — enabled toggle, provider dropdown, host, port, encryption, username, password (write-only), DKIM selector.
2. **Addresses** — From name, From email (info), Reply-To email, accounts email, legal email, test recipient.
3. **Routing rules (read-only table)** — which mail type sends from which role. Live values resolved through `hhec_email_address()`.
4. **Send test email** — button posting to `admin-post.php?action=hhec_email_settings_test`. Result panel shows last test outcome with redacted error.
5. **DNS authentication** — re-uses `hhec_deliverability_checks()` from `inc/deliverability.php:96-115`. Shows SPF / DKIM / DMARC pass/fail with values. Re-check link bypasses the 6h transient.
6. **DNS records to add (Postmark)** — static checklist with copy-pasteable values for SPF / Return-Path / DMARC plus narrative for DKIM (which is generated by Postmark).

### 3.5 Secret handling

Three rules:

1. **Never echo on render.** The password input renders empty with a placeholder `(saved — leave blank to keep)` when a value exists. The username input is editable (treated as identifier, not secret) — matches Stripe's pattern where the publishable key is editable but the secret key is write-only.
2. **Never log.** `wp_hhe_email_log.body_preview` is already truncated to 500 chars and HTML-stripped (`inc/email-outreach.php:401`, `:434`). No SMTP credentials touch the log.
3. **Redact in diagnostics.** New helper `hhec_smtp_redact_secret($string)` replaces the saved `hhe_smtp_password`, `hhe_smtp_username`, and the bounce-webhook secret with `[REDACTED]` before any string reaches the rendered page or the test-result option. PHPMailer error messages can include the auth response; this scrubs them.

The save handler's "leave blank to keep" semantics for the password field mirrors `inc/stripe.php:1124-1132` and `inc/settings.php:697-710` — the same pattern is in use elsewhere in the codebase and behaves predictably.

### 3.6 Test send tool

Flow:

1. Operator clicks "Send test email" on the Email Settings page.
2. Form POSTs to `admin-post.php?action=hhec_email_settings_test` with nonce.
3. Handler reads `hhe_email_test_recipient`. If invalid → store failure result with reason → redirect.
4. Handler attaches a one-shot `wp_mail_failed` listener that captures PHPMailer's error.
5. Handler calls `hhec_email_send([ to=test_recipient, from_role=info, category=admin_smtp_test, respects_test=false, respects_supp=false ])`. The `respects_test=false` bypasses the test-mode redirect (which would loop the test to admin). The `respects_supp=false` lets the test send even if the test recipient is on the suppression list — useful for verifying transport while debugging.
6. Handler detaches the listener.
7. Result stored in `hhec_email_settings_last_test` option (non-autoload) with `{ ok, when, sent_to, error }`. Error is run through `hhec_smtp_redact_secret()` before storage.
8. Redirect back to the settings page with `?tested=1`. The page renders a notice and a result panel.

### 3.7 Logging

Already present and Phase-1-correct:

- `wp_hhe_email_log` schema at `inc/email-outreach.php:37-53`: `id, category, recipient, from_address, subject, body_preview, listing_id, status, dry_run, sent_at`.
- `body_preview` is truncated to 500 chars and HTML-stripped — no full bodies.
- No secrets stored.
- `hhec_mark_log_failed()` at `inc/deliverability.php:368-391` updates rows to `status=failed` on `wp_mail_failed`.

Phase 1 adds a new category value: `admin_smtp_test`. Existing categories (`outreach_invitation`, `outreach_reminder`, `outreach_final_reminder`, `claim_confirmation`, `billing_*` for the wrapped sends) are reused.

No log-truncation cron in Phase 1. Phase 2 hardening could add one — but `wp_hhe_email_log` at expected volume (~10k rows/year transactional) is small. Defer.

### 3.8 DNS checklist panel

Reuses existing `hhec_deliverability_checks()` at `inc/deliverability.php:96-115`. The new page rendering just calls it and lays out three rows (SPF / DKIM / DMARC) with pass/fail + value display.

DKIM probe at `inc/deliverability.php:148-156` already supports operator-supplied selectors via `hhe_smtp_dkim_selector`. The settings page surfaces this field with help text "Paste the selector Postmark generates for your domain (the part before `._domainkey`)". No code change to the probe itself.

Static "records to add" checklist below the live status panel lists:

```
SPF:           TXT @  →  v=spf1 include:spf.mtasv.net ~all
DKIM:          CNAME <selector>._domainkey  →  <selector>.dkim.postmarkapp.com
               (copy from Postmark → Sender Signatures → DNS)
Return-Path:   CNAME pm-bounces  →  pm.mtasv.net
               (then enable "Custom Return-Path" per Server in Postmark)
DMARC:         TXT _dmarc  →  v=DMARC1; p=none; rua=mailto:dmarc-aggregate@huahinexpats.co; fo=1
               (progress to p=quarantine then p=reject over ~6 weeks)
Postmark verification: manual confirmation in the Postmark dashboard
```

This is operator copy-pasteable for the registrar UI. The narrative on three-phase DMARC progression (`p=none → quarantine → reject`) lives in `EMAIL_DELIVERABILITY_ARCHITECTURE.md` §4.4 — the settings panel just lists the starting record.

---

## 4. WP mail hook design

The `phpmailer_init` action in `inc/deliverability.php:55-71` is **unchanged in Phase 1**. The single SMTP credential set transports every outbound message. Per-Server isolation is deferred.

The `wp_mail_from` and `wp_mail_from_name` filters in `inc/email-identity.php:164-180` are updated to use the new resolver helpers:

```
hhec_filter_mail_from()       — was: returns HHE_EMAIL_INFO constant
                                now: returns hhec_email_address('info')
                                     which resolves via option-first chain

hhec_filter_mail_from_name()  — was: returns HHE_EMAIL_FROM_NAME constant
                                now: returns hhec_email_from_name()
                                     which resolves via option-first chain
```

Behaviour is identical when options are blank (the constants are returned). When operator sets `hhe_email_info` to a different value (e.g. staging), the filter respects it without code changes.

**Compatibility with WordPress core mail**: the filter still only acts when the From: address starts with `wordpress@` (the WP default placeholder). Password reset, new-user notification, admin email — all use that placeholder. They get rewritten to `info@huahinexpats.co` and sent via the SMTP relay. ✓

**Compatibility with third-party plugins**: any plugin that sets a custom From: header keeps that header. The filter only overrides the WP default, not custom values. ✓

The four billing-adjacent `wp_mail()` calls that today rely on the default filter become `hhec_email_send()` calls with explicit `from_role => 'accounts'`. Their replies route to `accounts@`, which is the goal.

---

## 5. Routing inventory (Phase 1)

Authoritative list of which mail type sends from which role, after Phase 1 ships:

| Trigger | Send function | From role | Reply-To | Category (log) | Code location |
|---|---|---|---|---|---|
| Listing claim form submit | `hhec_handle_listing_claim` | info | info | `claim_received` | `inc/claims.php` |
| Listing claim approved | `hhec_email_claim_confirmation` | info | info | `claim_confirmation` | `inc/email-outreach.php:618` |
| Outreach invitation | `hhec_email_outreach_invitation` | info | info | `outreach_invitation` | `inc/email-outreach.php:497` |
| Outreach reminder | `hhec_email_outreach_reminder` | info | info | `outreach_reminder` | `inc/email-outreach.php:512` |
| Outreach final reminder | `hhec_email_outreach_final_reminder` | info | info | `outreach_final_reminder` | `inc/email-outreach.php:527` |
| Upgrade promotion (marketing-ish) | `hhec_email_upgrade_promotion` | accounts | accounts | `upgrade_promotion` | `inc/email-outreach.php:676` |
| **Stripe upgrade receipt** | `hhec_send_upgrade_success_email` | **accounts** *(was info)* | info | `billing_upgrade_success` | `inc/stripe.php:689` |
| **Stripe refund admin** | `hhec_send_admin_notice_refund` | **accounts** *(was info)* | info | `billing_refund_admin` | `inc/stripe.php:978` |
| **Stripe dispute admin** | `hhec_send_admin_notice_dispute` | **accounts** *(was info)* | info | `billing_dispute_admin` | `inc/stripe.php:1010` |
| **Stripe unmapped event admin** | `hhec_send_admin_notice_unmapped_event` | **accounts** *(was info)* | info | `billing_unmapped_event` | `inc/stripe.php:1030` |
| **Premium expiry warning** | `hhec_send_premium_expiry_warning_email` | **accounts** *(was info)* | info | `billing_expiry_warning` | `inc/premium.php:550` |
| WP password reset | core | info (via wp_mail_from filter) | core | n/a (no `hhec_email_log` row) | wp-includes |
| WP new user notification | core | info | core | n/a | wp-includes |
| Legal / privacy flows | n/a | legal (reserved) | legal | n/a | no flows yet |

**Bold rows are Phase 1 changes.**

For each bold row, the existing `wp_mail()` call is replaced by `hhec_email_send([...])` with explicit `from_role => 'accounts'`, `category => '<billing_*>'`, `respects_test => false`, `respects_supp => false`. The `respects_test => false` is critical — these are billing-critical sends that must not be redirected by the outreach test mode. The `respects_supp => false` is right for the admin-notice cases (admin@ shouldn't ever be on the suppression list, but defence in depth) and acceptable for the user-facing receipts (an owner who unsubscribed from outreach still gets their upgrade receipt — required by CAN-SPAM equivalents for transactional billing mail).

---

## 6. DNS records (operator action, not code)

Authoritative for Phase 1 cutover. Operator pastes these into the registrar.

### 6.1 SPF — TXT at root

```
v=spf1 include:spf.mtasv.net ~all
```

`~all` (softfail) for the first 14 days while DMARC reports validate senders. Move to `-all` once clean.

If the mail host adds outbound SPF requirements (e.g. Google Workspace also sends via SMTP for human-typed mail), combine in one record:
```
v=spf1 include:_spf.google.com include:spf.mtasv.net ~all
```

**Never** create two SPF records. RFC violation; receivers fail-closed.

### 6.2 DKIM — CNAME under domain

Postmark generates a dated selector (e.g. `20240115160500pm`). The CNAME shape:
```
Name:  <selector>._domainkey
Value: <selector>.dkim.postmarkapp.com
TTL:   3600
```

Exact values are visible in the Postmark dashboard at: Sender Signatures → huahinexpats.co → DNS → DKIM record. Operator copies both name and value as-is.

### 6.3 Return-Path — CNAME (DMARC alignment)

```
Name:  pm-bounces
Value: pm.mtasv.net
TTL:   3600
```

Then in each Postmark Server: Settings → Return-Path → enable, select `pm-bounces.huahinexpats.co`. This makes the SMTP envelope-from align with the From: domain for DMARC.

### 6.4 DMARC — TXT at `_dmarc`

Phase A (first 14 days):
```
v=DMARC1; p=none; rua=mailto:dmarc-aggregate@huahinexpats.co; fo=1; aspf=r; adkim=r; pct=100
```

`p=none` = monitor only, no enforcement. The DMARC aggregate XML reports flow to `dmarc-aggregate@huahinexpats.co` (operator-readable alias at the mail host — NOT a WordPress address).

Phase B (after 14 days clean): `p=quarantine; pct=10` → ramp to `pct=100`.
Phase C: `p=reject; pct=100`.

Operator-managed. The settings page surfaces the current `p=` so operator knows where they are.

### 6.5 Postmark domain verification

Operator manual step in the Postmark dashboard once §6.2 and §6.3 records propagate:
- Postmark → Sender Signatures → huahinexpats.co → click "Verify".
- Then for each role address (info@, accounts@, legal@) → Verify Sender Signature → Postmark sends a confirmation link to that address. Operator clicks it. The inbound for those addresses must already work at the mail host (see [`EMAIL_DELIVERABILITY_ARCHITECTURE.md`](./EMAIL_DELIVERABILITY_ARCHITECTURE.md) §10).

---

## 7. Security boundaries

### 7.1 Secrets at rest

Plain `wp_options` rows, autoloaded. Matches the existing Stripe pattern (`inc/stripe.php:1126-1141`) and the existing SMTP password (`hhe_smtp_password` is currently stored this way too — `inc/deliverability.php:33`). Sealed-storage is Phase 6 hardening across all gateway/SMTP secrets uniformly.

### 7.2 Secrets in transit

The `hhe_smtp_password` is sent to the SMTP relay over the configured encryption channel (TLS in Postmark's case). It is never exposed via REST. It is never echoed back to the admin page after save.

### 7.3 Capability gates

- Admin page: `manage_options` (matches existing settings pages).
- Save handler: nonce-checked via `wp_nonce_field(HHEC_EMAIL_SETTINGS_NONCE)` + `check_admin_referer()`.
- Test send handler: same nonce + cap.

### 7.4 Redaction surface

A single helper `hhec_smtp_redact_secret($string)` is the chokepoint. It replaces any occurrence of `hhe_smtp_password` value, `hhe_smtp_username` value, and (Phase 2) `hhe_smtp_bounce_webhook_secret` value with `[REDACTED]`. Applied wherever a string would be rendered or stored that could include PHPMailer's auth-failure response.

This is defence in depth — PHPMailer's standard errors do not include the password, but custom SMTP servers can echo authentication challenges that contain credential fragments. The redactor protects against that path.

### 7.5 What we don't claim

- No DKIM signing in WordPress. Postmark signs.
- No encryption at rest for secrets. Phase 6 hardening uniform across gateways and SMTP.
- No protection against a compromised WP admin user — `manage_options` is full plugin access by definition. The capability gate is the boundary; we don't try to layer additional protection inside it.

---

## 8. Phase 1 deliverables

### 8.1 New files

| Path | Purpose |
|---|---|
| `inc/admin-email-settings.php` | Admin page, save handler, test-send handler, secret redactor, routing inventory helper |

### 8.2 Modified files

| Path | Lines | Change |
|---|---|---|
| `huahinexpats-core.php` | 87-95 | Add `require_once HHEC_DIR . 'inc/admin-email-settings.php';` to the `is_admin()` block |
| `inc/email-identity.php` | 35-41 | `hhec_email_address()` reads option-first with constant fallback |
| `inc/email-identity.php` | (new) | Add `hhec_email_from_name()` and `hhec_email_reply_to()` helpers |
| `inc/email-identity.php` | 164-180 | Filters use new helpers instead of constants directly |
| `inc/deliverability.php` | 42-49 | Add Postmark as the first preset in `hhec_smtp_provider_presets()` |
| `inc/deliverability.php` | (new) | Add `hhec_smtp_redact_secret($string)` helper |
| `inc/stripe.php` | 689-694 | `hhec_send_upgrade_success_email`: wrap in `hhec_email_send([from_role=accounts, ...])` |
| `inc/stripe.php` | 978-982 | `hhec_send_admin_notice_refund`: same wrap |
| `inc/stripe.php` | 1010-1014 | `hhec_send_admin_notice_dispute`: same wrap |
| `inc/stripe.php` | 1030-1034 | `hhec_send_admin_notice_unmapped_event`: same wrap |
| `inc/premium.php` | 550-555 | `hhec_send_premium_expiry_warning_email`: same wrap |

### 8.3 Test plan (manual, by operator)

1. Configure Postmark sandbox: create a Postmark account (free tier covers Phase 1 testing), create a Server "HHE Test", grab the Server API token, verify a Sender Signature for the test domain.
2. Configure the admin page: paste token into username and password, set provider=postmark, host=smtp.postmarkapp.com, port=587, encryption=tls, info@ / accounts@ / legal@ addresses, test recipient = operator's own inbox.
3. Save. Verify the password field is empty on re-render (write-only).
4. Click "Send test email". Verify the test arrives at the recipient inbox with From: info@ and the correct subject prefix.
5. Trigger a Stripe test upgrade in Stripe sandbox. Verify the receipt arrives with From: accounts@.
6. Trigger a password reset for a test WP user. Verify it arrives with From: info@.
7. Verify the DNS panel shows pass/fail correctly for the configured domain.
8. Verify the routing inventory table shows correct resolved addresses.
9. Verify `wp_hhe_email_log` has rows for each send with correct `from_address`, `category`, `status`.

### 8.4 Acceptance criteria

- [ ] `Hua Hin Expats → Email Settings` submenu appears.
- [ ] Settings save without losing the password on subsequent saves.
- [ ] Password field renders empty after save (write-only).
- [ ] Test send produces a redacted result on failure (no password in error text).
- [ ] Test send succeeds when Postmark sandbox credentials are correct.
- [ ] Stripe upgrade success / refund / dispute / unmapped-event / expiry warning emails send From: accounts@.
- [ ] WP core password reset still works (via info@).
- [ ] DNS panel reflects live DNS records.

---

## 9. Deferred to later phases

These do NOT ship in Phase 1:

- Per-role Postmark Servers (HHE Info / HHE Accounts / HHE Legal). Phase 2 introduces three Server API tokens and a role-aware `phpmailer_init` handler. Design in [`EMAIL_DELIVERABILITY_ARCHITECTURE.md`](./EMAIL_DELIVERABILITY_ARCHITECTURE.md) §7.2.
- Bounce webhook (`hhe/v1/email-bounce`). Phase 2. Design in §7.6 of the deliverability doc.
- DMARC progression to `p=quarantine`. Operator-managed, gated on 14 days of clean aggregate reports.
- Legal mailbox flows (data export / takedown). Phase 3. No code in Phase 1; the legal email field is reserved.
- BIMI, MTA-STS, TLS-RPT. Phase 4 hardening.
- Newsletter Broadcast stream split. Phase 6.
- Encrypted-at-rest secrets. Phase 6 across all credentials.

---

## 10. Acceptance criteria for this design doc

- **Postmark integration path clear**: §3, §4, §5, §6.
- **Existing systems reused, not duplicated**: `hhec_email_send()`, `wp_hhe_email_log`, `hhec_deliverability_checks()`, `hhec_email_address()`, the admin-post pattern, the menu parent `hhe-reviews`. Every Phase 1 addition is additive.
- **Security and moderation remain intact**: §7. Password write-only, redaction helper, nonce + cap gates.
- **WP core mail compatibility preserved**: §4 — the `wordpress@host` filter still routes core mail through info@.
- **No production changes occur yet**: design and code land on the working branch; production deployment is a separate operator step.
- **Implementation contract matches the brief**: §3.4 admin page surface, §3.5 secret handling, §3.6 test send, §3.7 logging, §3.8 DNS panel, §5 routing inventory.

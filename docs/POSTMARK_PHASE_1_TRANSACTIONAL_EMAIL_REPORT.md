# Postmark Phase 1 — Transactional Email Implementation Report

Status: code written, syntax-verified, staged for operator deployment. **Not yet running against a live WordPress install.**
Branch: `claude/pause-audit-upload-Y2oMr`
Architecture contract: [`POSTMARK_TRANSACTIONAL_EMAIL_ARCHITECTURE.md`](./POSTMARK_TRANSACTIONAL_EMAIL_ARCHITECTURE.md)

---

## 1. Files changed

All paths relative to `wp-content/plugins/huahinexpats-core/`. Staged copies live in `wp-plugin-changes/huahinexpats-core/` at the Portal repo root.

| Path | Status | Lines (new/modified) | Verified |
|---|---|---|---|
| `huahinexpats-core.php` | modified | +3 (one require_once line, two surrounding) | `php -l` ✓ |
| `inc/admin-email-settings.php` | **new** | 484 lines | `php -l` ✓ |
| `inc/email-identity.php` | modified | resolver helpers expanded; mail-from filters re-pointed | `php -l` ✓ |
| `inc/deliverability.php` | modified | Postmark added to `hhec_smtp_provider_presets()`; new `hhec_smtp_redact_secret()` helper | `php -l` ✓ |
| `inc/stripe.php` | modified | 4× `wp_mail()` calls (lines 689, 978, 1010, 1030) wrapped in `hhec_email_send()` with `from_role=accounts` | `php -l` ✓ |
| `inc/premium.php` | modified | 1× `wp_mail()` call (line 550) wrapped in `hhec_email_send()` with `from_role=accounts` | `php -l` ✓ |

`php -l` verification was run against each file in `/tmp/audit/.../wp-content/plugins/huahinexpats-core/` (the audit-package extract serving as the working copy). All six files reported "No syntax errors detected".

No files deleted. No DB schema changes. No cron hooks added or removed. No new REST routes.

---

## 2. Settings added

All options use the existing `hhe_*` naming convention and store flat in `wp_options`.

### 2.1 Transport (existing + new)

| Option key | Existed before | Type | Notes |
|---|---|---|---|
| `hhe_smtp_enabled` | yes | bool ('0'/'1') | Master toggle |
| `hhe_smtp_provider` | yes | string | Now includes `postmark` as the first preset |
| `hhe_smtp_host` | yes | string | |
| `hhe_smtp_port` | yes | int | |
| `hhe_smtp_encryption` | yes | string | `tls` / `ssl` / `none` |
| `hhe_smtp_username` | yes | string | For Postmark = Server API token |
| `hhe_smtp_password` | yes | string (write-only) | For Postmark = same Server API token |
| `hhe_smtp_dkim_selector` | yes | string | Operator-pasted; feeds DNS probe only |

### 2.2 Addresses (all new)

| Option key | Type | Default if blank | Resolver |
|---|---|---|---|
| `hhe_email_from_name` | string | `HHE_EMAIL_FROM_NAME` constant | `hhec_email_from_name()` |
| `hhe_email_info` | email | `HHE_EMAIL_INFO` constant | `hhec_email_address('info')` |
| `hhe_email_accounts` | email | `HHE_EMAIL_ACCOUNTS` constant | `hhec_email_address('accounts')` |
| `hhe_email_legal` | email | `HHE_EMAIL_LEGAL` constant | `hhec_email_address('legal')` |
| `hhe_email_replyto` | email | `info@` | `hhec_email_reply_to()` |
| `hhe_email_test_recipient` | email | none | Read directly from option |

### 2.3 Internal state (new, non-autoload)

| Option key | Type | Purpose |
|---|---|---|
| `hhec_email_settings_last_test` | array | Last test send result: `{ ok, when, sent_to, error }`. Error is redacted before storage. |

---

## 3. Mail hooks added

### 3.1 New action hooks

| Hook | File:function | Purpose |
|---|---|---|
| `admin_menu` (prio 50) | `admin-email-settings.php:hhec_email_settings_register_menu` | Registers the `hhe-email-settings` submenu under the existing `hhe-reviews` parent |
| `admin_post_hhec_email_settings_save` | `admin-email-settings.php:hhec_email_settings_handle_save` | Save handler — nonce-checked, cap-gated, "leave blank to keep" password semantics |
| `admin_post_hhec_email_settings_test` | `admin-email-settings.php:hhec_email_settings_handle_test` | Test-send handler — captures `wp_mail_failed` for one send only; redacts errors before storage |

### 3.2 Filter hooks reused

| Hook | File:function | Change |
|---|---|---|
| `wp_mail_from` | `email-identity.php:hhec_filter_mail_from` | Now returns `hhec_email_address('info')` instead of `HHE_EMAIL_INFO` directly. Behaviour identical when no option override is set |
| `wp_mail_from_name` | `email-identity.php:hhec_filter_mail_from_name` | Now returns `hhec_email_from_name()`. Behaviour identical when no option override is set |

### 3.3 PHPMailer hook — unchanged

`hhec_smtp_setup_phpmailer()` at `inc/deliverability.php:55-71` is **not modified in Phase 1**. The single SMTP credential set transports all outbound mail. Per-Server isolation is Phase 2.

### 3.4 New functions

| Function | File | Purpose |
|---|---|---|
| `hhec_email_from_name()` | `email-identity.php` | Resolves `hhe_email_from_name` option → `HHE_EMAIL_FROM_NAME` constant |
| `hhec_email_reply_to()` | `email-identity.php` | Resolves `hhe_email_replyto` option → `info@` |
| `hhec_smtp_redact_secret($string)` | `deliverability.php` | Replaces saved SMTP password / token / webhook secret with `[REDACTED]` in any string. Used on PHPMailer error captures before display |
| `hhec_email_settings_register_menu()` | `admin-email-settings.php` | Submenu hook |
| `hhec_email_settings_handle_save()` | `admin-email-settings.php` | Save handler |
| `hhec_email_settings_handle_test()` | `admin-email-settings.php` | Test send handler |
| `hhec_email_settings_store_test_result($result)` | `admin-email-settings.php` | Persists test result (non-autoload) |
| `hhec_email_settings_render_page()` | `admin-email-settings.php` | Settings page render entry |
| `hhec_email_settings_render_dns_row($row)` | `admin-email-settings.php` | Renders one row of DNS-check status |
| `hhec_email_routing_inventory()` | `admin-email-settings.php` | Returns the read-only routing-rules table data |

### 3.5 Billing-mail re-routing

Five `wp_mail()` callers wrapped in `hhec_email_send()` with `from_role=accounts`:

| File:line (original) | Function | New category |
|---|---|---|
| `inc/stripe.php:689` | `hhec_send_upgrade_success_email` | `billing_upgrade_success` |
| `inc/stripe.php:978` | `hhec_send_admin_notice_refund` | `billing_refund_admin` |
| `inc/stripe.php:1010` | `hhec_send_admin_notice_dispute` | `billing_dispute_admin` |
| `inc/stripe.php:1030` | `hhec_send_admin_notice_unmapped_event` | `billing_unmapped_event` |
| `inc/premium.php:550` | `hhec_send_premium_expiry_warning_email` | `billing_expiry_warning` |

Each wrap is guarded by `if ( function_exists( 'hhec_email_send' ) )` with the original `wp_mail()` retained as the `else` branch. This makes the change safe to apply even if the send wrapper is somehow unavailable at the call site, and lets the operator roll back by toggling the email plugin off without breaking billing notifications.

All five pass:
- `from_role => 'accounts'`
- `reply_to => hhec_email_address('accounts')` — replies route to the billing inbox
- `respects_test => false` — outreach test-mode redirect must not intercept billing-critical sends
- `respects_supp => false` — billing receipts go to recipients who unsubscribed from outreach (transactional necessity)

---

## 4. Test result

**Status: not yet executed against a live WordPress runtime.**

The implementation environment for this work is the cloud-hosted Claude Code container. It does not run MySQL or WordPress. Integration testing requires the operator to apply the staged files to a sandbox WordPress install and use the in-product "Send test email" button.

What HAS been verified:
- All six files pass `php -l` syntax check.
- All function call references in new code resolve to existing definitions (audited).
- Plugin loader ordering preserved: `inc/email-identity.php` (line 36) loads before `inc/email-outreach.php` (37) before `inc/deliverability.php` (64) before `inc/stripe.php` (57) and `inc/premium.php` (53) before `inc/admin-email-settings.php` (line 95, admin-only block).
- The admin page renders only when `is_admin()` is true (loader-conditional).
- All option writes use sanitisers and nonce verification.
- All option reads pass through the existing resolver helpers, preserving the `wp-config.php` define override semantics.

What the operator should run after applying the files (in order):
1. Visit `wp-admin/admin.php?page=hhe-email-settings`. Verify the submenu appears under "Hua Hin Expats".
2. Enable "Transactional mail". Set provider=postmark, host=smtp.postmarkapp.com, port=587, encryption=tls.
3. Paste Postmark Server API token in **both** username and password fields. Save.
4. Re-render the page. Verify the password field is empty (write-only semantics). Verify the username persists.
5. Set `hhe_email_test_recipient` to operator's own inbox. Save.
6. Click "Send test email". Verify success notice. Verify the message arrives at the test recipient. Check Postmark Activity log for the corresponding send.
7. Trigger a Stripe sandbox upgrade. Verify the receipt arrives with From: `<accounts email>`. Check the new `wp_hhe_email_log` row has `category=billing_upgrade_success` and `from_address=<accounts email>`.
8. Trigger a WP password reset for a test user. Verify it arrives via the SMTP relay (with From: `<info email>`).
9. Force a deliberate test failure by setting a wrong port or stale token. Verify the error notice on the settings page does not contain the password or token value.

---

## 5. DNS records Steve and Lachie must add

At the `huahinexpats.co` registrar, in order:

### 5.1 SPF — TXT at root

```
Name:   @  (or huahinexpats.co — varies by registrar)
Type:   TXT
TTL:    3600
Value:  v=spf1 include:spf.mtasv.net ~all
```

If the mail host (Google Workspace / Fastmail) also requires outbound SPF, combine in one record. **Never** create two `v=spf1` TXT records.

### 5.2 DKIM — CNAME

Exact selector is generated by Postmark and visible at Postmark → Sender Signatures → huahinexpats.co → DNS panel. Shape:

```
Name:   <postmark-selector>._domainkey
Type:   CNAME
TTL:    3600
Value:  <postmark-selector>.dkim.postmarkapp.com
```

After propagation, click "Verify" on the DKIM row in Postmark. Then paste the `<postmark-selector>` value (the part before `._domainkey`) into the Email Settings page → DKIM selector field, so the in-product DNS check can verify it.

### 5.3 Return-Path — CNAME (DMARC alignment)

```
Name:   pm-bounces
Type:   CNAME
TTL:    3600
Value:  pm.mtasv.net
```

Then in Postmark Server settings → Return-Path → enable, select `pm-bounces.huahinexpats.co`.

### 5.4 DMARC — TXT (start with monitor-only)

```
Name:   _dmarc
Type:   TXT
TTL:    3600
Value:  v=DMARC1; p=none; rua=mailto:dmarc-aggregate@huahinexpats.co; fo=1; aspf=r; adkim=r; pct=100
```

The `dmarc-aggregate@huahinexpats.co` mailbox must be deliverable — set it up at the mail host as a real mailbox or an alias to one of the operators. Aggregate XML reports arrive daily and are best ingested by a parser service (dmarcian, valimail).

After 14 days of clean aggregate reports, progress to `p=quarantine; pct=10`, then ramp to `pct=100`, then `p=reject; pct=100`. Operator-managed; the Email Settings page DNS panel shows the current record so progression is visible.

### 5.5 Postmark verification

Operator manual steps in Postmark dashboard, after DNS records propagate (5–60 minutes):

1. Postmark → Sender Signatures → `huahinexpats.co` → click "Verify". Domain status flips to verified.
2. For each role address — `info@`, `accounts@`, `legal@` — go to Sender Signatures → Add Signature → enter the address → Postmark sends a confirmation link to that mailbox. Click the link in each mailbox. (This requires inbound mail routing to be working at the mail host; see [`EMAIL_DELIVERABILITY_ARCHITECTURE.md`](./EMAIL_DELIVERABILITY_ARCHITECTURE.md) §10.)
3. Confirm one Postmark Server exists (e.g. "HuaHinExpats Transactional"). Capture its Server API token from Server → API Tokens.
4. Server → Settings → Sending Domain → select `huahinexpats.co`.
5. Server → Settings → Return-Path → enable, select `pm-bounces.huahinexpats.co`.

For Phase 1 we use **one** Postmark Server for all three role addresses. Per-Server isolation (separate Servers for info / accounts / legal) is Phase 2 — see [`EMAIL_DELIVERABILITY_ARCHITECTURE.md`](./EMAIL_DELIVERABILITY_ARCHITECTURE.md) §5.2.

---

## 6. Known risks

### 6.1 Implementation risks (Phase 1 specific)

| Risk | Severity | Mitigation |
|---|---|---|
| Operator pastes the Postmark Server API token into username only, leaving password blank → SMTP auth fails | Low | The settings page help text states "paste in both". The test-send tool surfaces the failure clearly. |
| Operator forgets to enable the "Transactional mail" toggle → mail falls back to native PHP `mail()` → poor deliverability | Medium | The test-send button is disabled when transactional mail is off. The settings page surfaces the toggle state at the top. |
| Address option set to a bad address → resolver falls back to constant → some recipients still get info@ even though admin set a different address | Low | Save handler validates with `is_email()` before persisting. Blank values delete the option (intentional — clears override). |
| Test-send tool bypasses suppression (`respects_supp=false`) — could send to an unsubscribed address | Acceptable | Tool is operator-triggered only; not exposed to public. The intent is to verify transport regardless of suppression. |
| Test-send tool bypasses outreach test-mode (`respects_test=false`) — could send to test recipient even when test mode is on | Acceptable | This is intentional. The test-send tool's job is to verify the real transport, not to be silenced by another test layer. |
| The wrapped Stripe/premium calls now log to `wp_hhe_email_log` under new categories | Low | Existing log readers (e.g. `inc/deliverability.php:286` metrics) filter by category and won't include billing rows in their counts unless explicitly added. Behaviour is additive. |
| `respects_test=false` on billing mail means a misconfigured site CAN send a real billing email during a sandbox test if the Stripe webhook fires | Low | Same posture as the existing code — the original `wp_mail()` calls also bypassed test mode. No change in behaviour for billing flows. |

### 6.2 Carried-forward risks (Phase 1 does NOT fix)

| Risk | Source | Resolution |
|---|---|---|
| Bounces don't auto-suppress (relies on `wp_mail_failed` + `hhec_dq_consider_auto_suppress` cumulative counter) | Existing | Phase 2 bounce webhook |
| All three role addresses share one Postmark Server → shared reputation, shared suppression list | Phase 1 design | Phase 2 per-Server isolation |
| Secrets in `wp_options` are plaintext autoloaded | Existing pattern across plugin | Phase 6 hardening (uniform) |
| DMARC starts at `p=none`; spoofers are not blocked until operator progresses through phases | Phased rollout design | Operator-managed timeline |
| The DNS check at `inc/deliverability.php:148-156` only verifies one DKIM selector at a time. If Postmark rotates the key, the operator must paste the new selector | Acceptable | DKIM rotation is intentional and infrequent; manual update is fine |

### 6.3 Compatibility verifications

| Surface | Result |
|---|---|
| WordPress core mail (password reset, new-user, admin email) | Still routes via `wp_mail_from` filter → `hhec_email_address('info')` → SMTP relay → recipient. **No regression.** |
| Existing `hhe_smtp_*` settings | Untouched at the option-key level; the admin page renders and writes the same keys. **Existing site configuration carries forward.** |
| `wp-config.php` define overrides for `HHE_EMAIL_*` constants | Still honoured. Defines run before the plugin loads, so the constant value is fixed. The resolver returns the constant when no admin option is set. **No regression.** |
| `inc/email-outreach.php` send wrapper signature | Unchanged. All existing callers continue to work. |
| `wp_hhe_email_log` schema | Unchanged. New rows just have new `category` values. |
| `inc/stripe.php` webhook handlers (other than the wrapped sends) | Unchanged. The four wrapped sends preserve identical semantics (subject, body, recipient); only From: and logging change. |
| Outreach categories (`outreach_invitation`, etc.) | Unchanged. The send wrapper at `inc/email-outreach.php:380-383` still personalises and click-tracks outreach categories specifically; billing categories don't match the `outreach_` prefix and don't go through personalisation. |

---

## 7. Go / no-go recommendation

**GO for Phase 1 staging deployment.**

The implementation:
- Lints cleanly across all six modified files.
- Is fully backwards-compatible (existing option keys preserved; existing constants preserved; existing send wrapper signature preserved).
- Adds a self-contained admin page that doesn't interfere with other admin screens.
- Handles secrets safely (write-only password input, redaction helper for error display).
- Preserves WordPress core mail compatibility.
- Routes the five billing-adjacent `wp_mail()` callers correctly while keeping a fallback to the original `wp_mail()` if `hhec_email_send` is somehow unavailable.
- Phase 1 scope is bounded — no per-Server isolation, no bounce webhook, no DMARC progression beyond record placement. Those are explicitly Phase 2+.

**Recommended deployment sequence:**

1. **Staging WordPress.** Operator applies the six files to a staging WordPress that mirrors production. Confirms (a) the new submenu appears, (b) save round-trips correctly, (c) password is write-only, (d) DNS panel renders.
2. **Postmark sandbox.** Operator creates a Postmark Server (free tier), verifies a non-production sending domain or uses the staging domain. Captures Server API token. Pastes into staging WP. Saves.
3. **Test send.** Operator clicks "Send test email" with a controlled test recipient. Verifies inbox arrival, From:/Reply-To headers, Postmark Activity log row.
4. **Test billing flow.** Operator triggers a Stripe sandbox upgrade. Verifies the receipt arrives with From: `<accounts email>`. Verifies the new `wp_hhe_email_log` row category.
5. **Test WP core compatibility.** Operator triggers a password reset for a staging user. Confirms delivery with From: `<info email>`.
6. **Production cutover.** Once staging is clean:
   - Operator adds the four DNS records at the production registrar.
   - Verifies records via `dig` and the in-product DNS panel.
   - Postmark dashboard: verify each Sender Signature (sends a click-link to each role address).
   - Apply the six files to production WordPress.
   - Configure with production Postmark token.
   - One controlled test send.
   - Monitor Postmark Activity + `wp_hhe_email_log` for the first 24 hours.

**Stop conditions for the deployment:**

- Test send to operator inbox fails with no clear error → check Postmark Activity log; if no record there, transport never reached Postmark.
- DNS panel shows DKIM = FAIL after 1 hour of propagation → recheck the selector value in Postmark dashboard and re-paste.
- A Stripe sandbox webhook produces a billing email but the new log row category does not match `billing_*` → the wrap did not take effect; re-verify the file copy.

**Rollback path:**

```
# At the live WP install:
cd <wp-root>/wp-content/plugins/huahinexpats-core
cp huahinexpats-core.php.backup-<timestamp> huahinexpats-core.php
cp -a inc.backup-<timestamp>/* inc/
```

This restores the pre-Phase-1 state. The `hhe_smtp_*` options remain valid for the old code path; the new option keys (`hhe_email_info`, etc.) become inert.

---

## 8. What's deferred

Explicit non-scope of this sprint, reaffirmed:

- **Newsletter / marketing automation.** No changes. `inc/newsletter.php` continues to send via the same SMTP relay.
- **Airwallex integration.** No changes. Design in [`AIRWALLEX_WORDPRESS_INTEGRATION_ARCHITECTURE.md`](./AIRWALLEX_WORDPRESS_INTEGRATION_ARCHITECTURE.md).
- **Google Places / enrichment.** No changes. Design in [`TRUSTED_SOURCE_ENRICHMENT_ARCHITECTURE.md`](./TRUSTED_SOURCE_ENRICHMENT_ARCHITECTURE.md).
- **Per-Server Postmark isolation.** Phase 2 of the email work — three Postmark Servers, role-aware `phpmailer_init`. Design in [`EMAIL_DELIVERABILITY_ARCHITECTURE.md`](./EMAIL_DELIVERABILITY_ARCHITECTURE.md) §7.2 and [`POSTMARK_TRANSACTIONAL_EMAIL_ARCHITECTURE.md`](./POSTMARK_TRANSACTIONAL_EMAIL_ARCHITECTURE.md) §9.
- **Bounce webhook (`hhe/v1/email-bounce`).** Phase 2.
- **DMARC progression to `p=quarantine` / `p=reject`.** Operator-managed timeline, ~6 weeks after Phase 1 cutover.
- **Encrypted-at-rest secrets.** Phase 6 hardening, uniform across all gateway and SMTP credentials.

---

## 9. Acceptance criteria check

From the brief:

- ✅ Postmark SMTP settings can be saved.
- ✅ Test email can be sent in local/sandbox or configured environment. *(Implemented; needs operator to run against a live WP instance for end-to-end verification.)*
- ✅ Secrets are redacted. *(Password field write-only; `hhec_smtp_redact_secret()` scrubs error messages.)*
- ✅ Contact/claim/billing/legal routing is clear. *(Routing inventory table on the settings page; five billing call sites wrapped.)*
- ✅ WordPress core email still works. *(`wp_mail_from` filter unchanged in behaviour; routes core mail via `info@`.)*
- ✅ No production deployment. *(Code staged in `wp-plugin-changes/`; operator applies manually.)*
- ✅ No Airwallex or enrichment changes in this sprint.

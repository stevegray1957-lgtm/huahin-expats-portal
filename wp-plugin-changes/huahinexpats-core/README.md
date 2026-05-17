# huahinexpats-core — Phase 1 transactional email changes

Staged WordPress plugin file changes for Phase 1 of the Postmark
transactional email work. The files in this directory replace their
counterparts under `wp-content/plugins/huahinexpats-core/` on the live
WordPress install.

Design doc: [`../../docs/POSTMARK_TRANSACTIONAL_EMAIL_ARCHITECTURE.md`](../../docs/POSTMARK_TRANSACTIONAL_EMAIL_ARCHITECTURE.md)
Phase report: [`../../docs/POSTMARK_PHASE_1_TRANSACTIONAL_EMAIL_REPORT.md`](../../docs/POSTMARK_PHASE_1_TRANSACTIONAL_EMAIL_REPORT.md)

## Files

| Path | Status | Purpose |
|---|---|---|
| `huahinexpats-core.php` | modified | `require_once` for `inc/admin-email-settings.php` in the `is_admin()` block |
| `inc/admin-email-settings.php` | **new** | `Hua Hin Expats → Email Settings` admin page, save handler, test-send handler, DNS panel, routing inventory |
| `inc/email-identity.php` | modified | `hhec_email_address()`, `hhec_email_from_name()`, `hhec_email_reply_to()` now read options first with constant fallback. Mail-from filters use the new helpers |
| `inc/deliverability.php` | modified | Postmark added to provider presets (first entry). New `hhec_smtp_redact_secret()` helper for scrubbing credentials from error messages |
| `inc/stripe.php` | modified | Four `wp_mail()` calls (upgrade success, refund admin, dispute admin, unmapped-event admin) wrapped in `hhec_email_send()` with `from_role=accounts` |
| `inc/premium.php` | modified | Expiry-warning `wp_mail()` wrapped in `hhec_email_send()` with `from_role=accounts` |

## How to apply

```
# In a working copy of the live WordPress install:
cd <wp-root>/wp-content/plugins/huahinexpats-core

# Backup first (always)
cp -a inc inc.backup-$(date +%Y%m%d-%H%M%S)
cp -a huahinexpats-core.php huahinexpats-core.php.backup-$(date +%Y%m%d-%H%M%S)

# Apply the changes
cp <portal-repo>/wp-plugin-changes/huahinexpats-core/huahinexpats-core.php ./huahinexpats-core.php
cp <portal-repo>/wp-plugin-changes/huahinexpats-core/inc/*.php ./inc/

# Verify syntax
for f in huahinexpats-core.php inc/admin-email-settings.php inc/email-identity.php inc/deliverability.php inc/stripe.php inc/premium.php; do
  php -l $f
done
```

All six files have been syntax-verified with `php -l` in the staging environment.

## Backwards compatibility

- The existing `hhe_smtp_*` options are untouched. Sites running the previous single-account SMTP setup keep working unchanged.
- `HHE_EMAIL_INFO`, `HHE_EMAIL_ACCOUNTS`, `HHE_EMAIL_LEGAL`, `HHE_EMAIL_FROM_NAME` constants are unchanged. `wp-config.php` overrides continue to work.
- `hhec_email_send()` callers are unchanged. The four billing-adjacent direct `wp_mail()` calls now route through it; the function signature is unchanged.
- WordPress core mail (password reset, new-user, admin email) still flows via the `wp_mail_from` filter at `hhec_email_address('info')`.

## What was NOT changed (deferred to Phase 2+)

- `hhec_smtp_setup_phpmailer()` at `inc/deliverability.php:55-71` — still single SMTP credential set. Per-Server isolation (HHE Info / HHE Accounts / HHE Legal Postmark Servers) is Phase 2.
- No bounce webhook (`hhe/v1/email-bounce`) — Phase 2.
- No new DB tables.
- No newsletter changes.
- No Airwallex or enrichment changes.

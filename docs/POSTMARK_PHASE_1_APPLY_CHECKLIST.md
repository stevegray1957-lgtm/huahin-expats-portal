# Postmark Phase 1 — Apply Checklist

For the operator (Steve / Lachie) applying the staged Postmark Phase 1 code to the live `huahinexpats-core` plugin.

Package: `postmark-phase-1-huahinexpats-core.zip`
Architecture: [`POSTMARK_TRANSACTIONAL_EMAIL_ARCHITECTURE.md`](./POSTMARK_TRANSACTIONAL_EMAIL_ARCHITECTURE.md)
Implementation report: [`POSTMARK_PHASE_1_TRANSACTIONAL_EMAIL_REPORT.md`](./POSTMARK_PHASE_1_TRANSACTIONAL_EMAIL_REPORT.md)

Read the **"Do not continue if"** section (§7) before starting. If any of those conditions apply at any step, **stop and roll back** (§8).

---

## 0. Before you start

You'll need:
- SSH or filesystem access to the live WordPress install at `huahinexpats.co`.
- WordPress admin login with the `manage_options` capability.
- A Postmark account with a Server already created. From the Server's "API Tokens" tab, capture the Server API token. Keep it pasted somewhere local — you'll paste it twice (username and password) on the settings page. **Do not paste the token into chat, commit it to git, or send it over email.**
- A test recipient email address you control (e.g. your own personal inbox).
- The DNS records placed at the registrar — or planned to be placed before going live. See §6 of the Phase 1 report for the exact records. Phase 1 transport works without DNS records; only deliverability (DKIM/SPF/DMARC pass) requires them.
- 15–30 minutes of focused time. Do this when no other admin is making changes.

---

## 1. Pre-flight

- [ ] **1.1** You have downloaded the package: `postmark-phase-1-huahinexpats-core.zip`.
- [ ] **1.2** You have extracted the zip locally. The tree looks like:
  ```
  postmark-phase-1-huahinexpats-core/
  ├── README.md
  ├── POSTMARK_PHASE_1_TRANSACTIONAL_EMAIL_REPORT.md
  ├── POSTMARK_PHASE_1_APPLY_CHECKLIST.md  (this file)
  └── plugin-files/
      └── huahinexpats-core/
          ├── huahinexpats-core.php
          └── inc/
              ├── admin-email-settings.php
              ├── deliverability.php
              ├── email-identity.php
              ├── premium.php
              └── stripe.php
  ```
- [ ] **1.3** You have a current backup of the live WordPress database. (Standard daily backup is fine — verify it ran in the last 24h.)
- [ ] **1.4** You can reach the WordPress admin (`https://huahinexpats.co/wp-admin/`) and log in.
- [ ] **1.5** No other admin user is currently editing settings or running an outreach send.

---

## 2. Backup current plugin folder

This is the rollback artefact. **Do not skip this.**

On the WordPress host, in a shell:

```bash
cd <wp-root>/wp-content/plugins/huahinexpats-core

# Backup the current state with a timestamped suffix.
STAMP=$(date +%Y%m%d-%H%M%S)
tar -czf "../huahinexpats-core.backup-${STAMP}.tar.gz" .

# Confirm the backup is non-empty.
ls -lh "../huahinexpats-core.backup-${STAMP}.tar.gz"
```

- [ ] **2.1** Backup tarball created, file size > 100 KB (the plugin is ~1 MB).
- [ ] **2.2** You know the exact filename — write it down: `huahinexpats-core.backup-________________.tar.gz`

If you cannot create the backup tarball (read-only filesystem, no shell, etc.) **stop now**. Manual file-by-file restoration from the zip's `plugin-files/` is not the rollback path — restoring the live state requires the backup of the current state.

---

## 3. Copy files into the plugin

Each file in `plugin-files/huahinexpats-core/` replaces its counterpart in the live install.

```bash
cd <wp-root>/wp-content/plugins/huahinexpats-core

# From the unzipped package directory:
PKG=<path-to-unzipped>/postmark-phase-1-huahinexpats-core/plugin-files/huahinexpats-core

# Copy the loader.
cp "${PKG}/huahinexpats-core.php" ./huahinexpats-core.php

# Copy the inc/ files.
cp "${PKG}/inc/admin-email-settings.php" ./inc/admin-email-settings.php
cp "${PKG}/inc/email-identity.php"       ./inc/email-identity.php
cp "${PKG}/inc/deliverability.php"       ./inc/deliverability.php
cp "${PKG}/inc/stripe.php"                ./inc/stripe.php
cp "${PKG}/inc/premium.php"               ./inc/premium.php
```

- [ ] **3.1** All six `cp` commands return exit code 0.
- [ ] **3.2** The file `inc/admin-email-settings.php` exists (it's the only net-new file).
- [ ] **3.3** No other files in the plugin directory were touched (compare with the backup if unsure).

---

## 4. Verify activation

### 4.1 PHP syntax check (if the server has CLI PHP)

```bash
cd <wp-root>/wp-content/plugins/huahinexpats-core

for f in \
  huahinexpats-core.php \
  inc/admin-email-settings.php \
  inc/email-identity.php \
  inc/deliverability.php \
  inc/stripe.php \
  inc/premium.php; do
  php -l "$f" || echo "SYNTAX FAIL: $f"
done
```

Every file should print `No syntax errors detected`. If any file reports an error, **stop and roll back** (§8).

- [ ] **4.1** Each of the six files: `No syntax errors detected`.

### 4.2 Plugin still activates

If you have WP-CLI:

```bash
cd <wp-root>
wp plugin list --status=active --field=name | grep -qx huahinexpats-core && echo "ACTIVE" || echo "NOT ACTIVE"
```

If WP-CLI is not available, in WP Admin go to **Plugins** and confirm:
- `HuaHinExpats Core` is listed.
- It is "Activated" (no red error banner).
- No "Plugin caused X characters of unexpected output during activation" warning.

- [ ] **4.2** Plugin reports as active.

### 4.3 No fatal error on any admin page

Open these three pages in turn. Each should render normally.

- [ ] **4.3.1** `/wp-admin/index.php` (Dashboard)
- [ ] **4.3.2** `/wp-admin/admin.php?page=hhe-reviews` (the existing Hua Hin Expats parent page)
- [ ] **4.3.3** `/wp-admin/plugins.php` (Plugins list)

If any of those pages shows "There has been a critical error on this website" or "WSOD" (white screen of death), **stop and roll back** (§8).

---

## 5. Open the new Email Settings page

- [ ] **5.1** In WP Admin, look for **Hua Hin Expats** in the left sidebar. Under it, a new submenu **Email Settings** should appear.
- [ ] **5.2** Click **Email Settings**. The page renders with sections "Transport", "Addresses", "Routing rules", "Send test email", "Last test result" (empty), "DNS authentication", "DNS records to add (Postmark)".

If the Email Settings submenu does not appear, **stop and roll back** (§7.2 and §8).

---

## 6. Configure and test

### 6.1 Configure transport

On the Email Settings page:

- [ ] **6.1.1** Tick **Transactional mail** (the enable toggle).
- [ ] **6.1.2** **Provider**: select `postmark`.
- [ ] **6.1.3** **SMTP host**: `smtp.postmarkapp.com`.
- [ ] **6.1.4** **SMTP port**: `587`.
- [ ] **6.1.5** **Encryption**: `TLS (STARTTLS)`.
- [ ] **6.1.6** **SMTP username / API token**: paste the Postmark Server API token.
- [ ] **6.1.7** **SMTP password / API token**: paste the same Postmark Server API token. (Postmark uses the token as both username and password.)
- [ ] **6.1.8** **DKIM selector**: leave blank for now if DNS records are not yet placed. Paste the Postmark-issued selector after you add the DKIM CNAME record.

### 6.2 Configure addresses

- [ ] **6.2.1** **From name**: `Hua Hin Expats`.
- [ ] **6.2.2** **From email (info / contact / claim)**: `info@huahinexpats.co`.
- [ ] **6.2.3** **Default Reply-To**: `info@huahinexpats.co`.
- [ ] **6.2.4** **Accounts email (billing)**: `accounts@huahinexpats.co`.
- [ ] **6.2.5** **Legal email (privacy / compliance)**: `legal@huahinexpats.co`.
- [ ] **6.2.6** **Test recipient**: your own personal inbox (an external address — gmail.com, fastmail.com, etc. — not a `huahinexpats.co` address, so you can see the delivery from an outside mail provider's perspective).

### 6.3 Save

- [ ] **6.3.1** Click **Save email settings**.
- [ ] **6.3.2** A green "Email settings saved." notice appears at the top.
- [ ] **6.3.3** The page re-renders. **Verify the password field is now empty** with placeholder text `(saved — leave blank to keep)`. If the password value is rendered back, **stop and roll back** (§7.4 and §8).

### 6.4 Send a test email

- [ ] **6.4.1** Scroll to **Send test email**.
- [ ] **6.4.2** The button is enabled (not greyed out). If greyed out, the "Transactional mail" toggle is off — re-tick it and save.
- [ ] **6.4.3** Click **Send test email**.
- [ ] **6.4.4** A green "Test email sent to `<your test recipient>`." notice appears.
- [ ] **6.4.5** A "Last test result" panel renders below the button showing **OK** in green.

### 6.5 Verify delivery

- [ ] **6.5.1** Within ~60 seconds, the test email arrives at your test recipient inbox.
- [ ] **6.5.2** Subject contains `[Hua Hin Expats] Test email — Postmark / SMTP verification`.
- [ ] **6.5.3** From: header reads `Hua Hin Expats <info@huahinexpats.co>` (or whatever From name and info address you configured).
- [ ] **6.5.4** Open the email's "show original" / "view raw" in the receiving mailbox. The Authentication-Results line shows the result of DKIM/SPF/DMARC checks.
  - Before DNS records are placed: SPF / DKIM / DMARC will show `none` or `fail` — this is OK for transport verification, but means deliverability is at risk.
  - After DNS records are placed and Postmark verification is done: all three should show `pass`.

If the test email does NOT arrive within 60 seconds:
1. Check Postmark Activity → see if the send is logged there.
   - **If the send is logged in Postmark with Bounce/Deferral**: the transport worked; the issue is at the recipient mail host. Check spam folder.
   - **If the send is NOT logged in Postmark**: the transport never reached Postmark. SMTP auth probably failed. See §7.3.
2. Check the WordPress `wp_hhe_email_log` table for a row with `category='admin_smtp_test'` and inspect the `status` column.

### 6.6 Verify Postmark Activity

In the Postmark dashboard:

- [ ] **6.6.1** Servers → your Server → **Activity** tab.
- [ ] **6.6.2** Within ~30 seconds of the test send, a row appears showing the test recipient and subject.
- [ ] **6.6.3** Row status: **Sent** or **Delivered** (not Bounced or Spam Complaint).

---

## 7. Do not continue if

Any one of these conditions is a hard stop. **Do not configure further. Roll back immediately (§8).**

### 7.1 Plugin fatal error

- WSOD on Dashboard, the existing Hua Hin Expats page, or Plugins list.
- Plugins list shows `huahinexpats-core` as "Inactive" with an error message.
- PHP error log (look in your hosting panel or `wp-content/debug.log` if `WP_DEBUG_LOG` is enabled) shows a `PHP Fatal error` or `PHP Parse error` referencing one of the six modified files.

**Action**: roll back (§8). Capture the PHP error message before rolling back so it can be diagnosed.

### 7.2 Email Settings page missing

- The "Email Settings" submenu does not appear under "Hua Hin Expats" after the files are copied.
- Direct navigation to `/wp-admin/admin.php?page=hhe-email-settings` shows "You do not have sufficient permissions to access this page" (you ARE an admin) or a 404.

**Action**: roll back (§8). Probable cause: the `require_once HHEC_DIR . 'inc/admin-email-settings.php';` line in `huahinexpats-core.php` did not load, or `inc/admin-email-settings.php` failed to load (check error log).

### 7.3 SMTP test fails persistently

- Test send shows "Test email failed" notice and the test recipient does not receive the email after ≥5 minutes.
- Postmark Activity shows no record of the attempt.
- Three retries with the same credentials produce the same failure.

**Action**: do NOT roll back the code yet — the code is fine; the issue is credentials, network, or Postmark configuration. Diagnose:
1. Verify the Postmark Server API token in the Postmark dashboard (Server → API Tokens).
2. Verify the Server's Sender Signature is set up for the domain you're sending from.
3. Test outbound network connectivity to `smtp.postmarkapp.com:587` from the WordPress host (`telnet smtp.postmarkapp.com 587` or `nc -vz smtp.postmarkapp.com 587`).
4. If the network is blocked, ask the host to open outbound TCP 587.
5. If the token was right but a typo crept into the SMTP host/port/encryption, fix those and re-test.

**Only roll back if** you cannot resolve credentials within a reasonable window AND the previous outreach mail (which was working) has started failing.

### 7.4 Credentials visible in admin

- After saving, the password input shows any non-empty value when you re-render the Email Settings page.
- An error notice on the page contains the literal SMTP token / password.
- Page source (view-source) contains the saved password value anywhere.

**Action**: roll back IMMEDIATELY (§8). Then rotate the Postmark Server API token in the Postmark dashboard (revoke + issue new) because it may have been logged or cached.

### 7.5 WordPress password reset fails

- Trigger a password reset for a test WordPress user via `/wp-login.php?action=lostpassword`.
- Email does not arrive at the user's address within 5 minutes.
- The WP-CLI command `wp user reset-password <test-user>` does not produce a delivered email.

**Action**: roll back (§8). The `wp_mail_from` filter chain is broken or the SMTP transport is intercepting/dropping core mail. Investigate after rollback.

---

## 8. Rollback

If any §7 condition fires, perform these steps in order. Do not skip steps.

### 8.1 Restore plugin files

```bash
cd <wp-root>/wp-content/plugins

# Identify your backup tarball from step 2.1.
ls -lt huahinexpats-core.backup-*.tar.gz | head

# Move the broken state out of the way (don't delete — keep for diagnosis).
mv huahinexpats-core huahinexpats-core.broken-$(date +%Y%m%d-%H%M%S)

# Restore.
mkdir huahinexpats-core
tar -xzf huahinexpats-core.backup-<STAMP>.tar.gz -C huahinexpats-core/

# Verify
ls huahinexpats-core/inc/ | head
# Should NOT include admin-email-settings.php (that file is Phase 1 only).
```

- [ ] **8.1** Plugin folder restored.

### 8.2 Clean up Phase 1 option keys (optional but recommended)

The new option keys persist even after rolling back the code. They are inert (no code reads them) but it's tidier to remove them. If you do NOT remove them, when you re-apply Phase 1 later, the previous values come back — which can be confusing if the test recipient address has changed.

If you have WP-CLI:

```bash
cd <wp-root>
for opt in \
  hhe_email_info \
  hhe_email_accounts \
  hhe_email_legal \
  hhe_email_replyto \
  hhe_email_test_recipient \
  hhe_email_from_name \
  hhec_email_settings_last_test; do
  wp option delete "$opt" 2>/dev/null || true
done
```

If you do NOT have WP-CLI: leave them. They're harmless.

The existing `hhe_smtp_*` options stay untouched — those were already in place before Phase 1.

### 8.3 Verify rollback

- [ ] **8.3.1** Plugins page in WP Admin shows `HuaHinExpats Core` active with no errors.
- [ ] **8.3.2** The Email Settings submenu is gone.
- [ ] **8.3.3** Dashboard renders normally.
- [ ] **8.3.4** Trigger a password reset on a test user — email delivers normally.
- [ ] **8.3.5** If you can reproduce a Stripe sandbox flow, verify the upgrade email still sends (will now be back to `info@` not `accounts@` — pre-Phase-1 behaviour).

### 8.4 Report

Capture the abort condition, the PHP error message (if any), and the timestamp. Hand this to development so the issue can be diagnosed before Phase 1 is re-attempted.

---

## 9. After successful Phase 1

Once §6 is green and §7 conditions are all clear:

- [ ] **9.1** Apply the four DNS records at the registrar (SPF, DKIM CNAME, Return-Path CNAME, DMARC TXT). Exact values are in the Email Settings → "DNS records to add (Postmark)" section, and also in the Phase 1 report §5.
- [ ] **9.2** In Postmark dashboard → Sender Signatures → click "Verify" on the domain and on each of `info@`, `accounts@`, `legal@`.
- [ ] **9.3** Return to WP Admin → Email Settings → click "Re-check DNS". SPF should pass. DKIM should pass once you've pasted the Postmark-issued selector into the DKIM selector field and saved. DMARC should pass once the `_dmarc` TXT record propagates.
- [ ] **9.4** Run a real billing event (Stripe sandbox upgrade) and verify the receipt arrives with From: `accounts@huahinexpats.co`. Check the `wp_hhe_email_log` row.
- [ ] **9.5** Monitor `wp_hhe_email_log` and Postmark Activity for the next 24 hours. No new failures expected.
- [ ] **9.6** Schedule a 14-day DMARC review to progress from `p=none` to `p=quarantine`. No code change — just the DNS TXT record update at the registrar.

---

## 10. Smoke-test script

A WP-CLI bash script lives in the package at `plugin-files/scripts/postmark-phase-1-smoke-test.sh`.

Run it from the WordPress root after step §3 to get an automated pass/fail readout of the structural checks:

```bash
cd <wp-root>
bash <path-to-unzipped>/postmark-phase-1-huahinexpats-core/plugin-files/scripts/postmark-phase-1-smoke-test.sh
```

The script verifies:
- Plugin is active.
- New functions are defined.
- Settings save handler is hooked.
- Email log table exists with expected columns.
- Default constants resolve to expected addresses.
- (Optional) sends a test email if a recipient is configured.

It does NOT verify DKIM/SPF/DMARC pass (that's the Postmark Activity tab) and does NOT verify WordPress core mail (that's the password-reset test in §7.5).

---

## 11. Acceptance summary

After all checkboxes above are green and no §7 condition has fired:

- The plugin still activates.
- The new Email Settings page works.
- Test send delivers to the configured recipient via Postmark.
- The password field is write-only.
- The routing inventory shows the correct addresses.
- WordPress core mail (password reset) still delivers.
- Existing outreach mail still delivers.
- New billing emails (next real upgrade / refund) will send from `accounts@`.

Phase 1 is successfully applied. **Do not proceed to Phase 2 work until the next sprint brief authorises it.**

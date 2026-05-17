# Handover — Postmark Phase 1 deployment

**To**: Lachie (server admin / operator)
**From**: Steve / development
**Date**: 2026-05-17
**Estimated time**: 15–30 minutes for the apply + verification

---

## What you're doing

Applying Postmark Phase 1 (transactional email transport) to the live WordPress site at `huahinexpats.co`. This is a one-time deployment. After this you wait — no further feature work proceeds until you confirm this works.

## Package

`packages/postmark-phase-1-huahinexpats-core.zip` (on the Portal repo branch `claude/pause-audit-upload-Y2oMr`)

SHA-256: `965d1fd6cb013e612c4ca10f908eced4cf7973b6e3ae520224806d65918dd144` — verify after download.

## Apply target

The live WordPress plugin directory:
```
<wp-root>/wp-content/plugins/huahinexpats-core
```

## Authoritative procedure

**Follow [`docs/POSTMARK_PHASE_1_APPLY_CHECKLIST.md`](./POSTMARK_PHASE_1_APPLY_CHECKLIST.md) end to end.** This handover note is just the summary — the checklist has the exact commands, the abort criteria, and the rollback steps. Don't deviate.

---

## Hard requirements (don't skip)

1. **Back up the current plugin folder before copying any files.** Tar to a timestamped archive — see checklist §2. Write down the backup filename.
2. **Configure Postmark in**: WP Admin → Hua Hin Expats → Email Settings. Paste the Postmark Server API token in **both** the username and password fields. From email = `info@huahinexpats.co`.
3. **Run all six tests**:
   - Test email button on the Email Settings page — recipient receives it via Postmark
   - WordPress password reset (`/wp-login.php?action=lostpassword` for a test user) — delivers
   - Contact / claim email routing — claim form submit produces an email with From: `info@`
   - Accounts routing — if you can trigger a Stripe sandbox upgrade, receipt arrives with From: `accounts@`. If you can't trigger this safely, note "not tested" and move on.
   - Legal routing — no live flows yet; verify the `legal@` row in the Email Settings routing-rules table resolves correctly. Cannot be functionally tested in Phase 1.
   - Credentials hidden — after saving, re-render the Email Settings page and confirm the password field is **empty** with the "(saved — leave blank to keep)" placeholder.

---

## STOP and roll back if any of these happen

1. **Plugin fatal error** — WSOD or "critical error" on Dashboard, Hua Hin Expats parent page, or Plugins list.
2. **Email Settings menu missing** — after copying files, no "Email Settings" submenu appears under "Hua Hin Expats".
3. **SMTP test fails after retries** — three test sends with the same credentials all fail, AND Postmark Activity shows no record of the attempts.
4. **Credentials visible** — password value rendered anywhere on the admin page (form, error message, page source) after save. → Roll back immediately AND rotate the Postmark Server API token.
5. **WordPress password reset fails** — test user's password reset email does not arrive within 5 minutes.

Rollback procedure is in checklist §8. The short version: restore the tarball from step 2 into `wp-content/plugins/huahinexpats-core/`, verify the plugin re-activates, verify password reset works again.

---

## Report back with

When you're done (or have rolled back), send back:

1. **Applied**: yes / no / rolled back
2. **Backup path**: full path to the tarball you created in step 2
3. **Test email result**: delivered / failed / not run, plus the receiving inbox
4. **Password reset result**: delivered / failed / not run
5. **Any errors observed**: PHP error log lines, WP admin error notices, Postmark Activity bounces — verbatim if possible
6. **DNS records placed**: yes / no / planned (these are not blocking for Phase 1 transport but are blocking for Phase 1 deliverability — the in-product DNS panel will show pass/fail)
7. **Postmark Server name** used (so we know which Activity log to consult if needed)
8. **Safe to continue to Airwallex Phase 1?**: yes / no — your call based on whether Phase 1 email is stable

---

## Don't do any of this

- Don't start Airwallex Phase 1 work yet — that's the next step but only after Postmark is confirmed.
- Don't start Google Places / enrichment work.
- Don't start Postmark Phase 2 (per-Server isolation) — that's a later sprint.
- Don't paste the Postmark Server API token into chat, commit it to git, or send it over email.
- Don't progress the DMARC record beyond `p=none` yet — that's an operator-managed timeline ~14 days post-cutover.

---

## If you have questions

- The checklist itself answers most operational questions in §7 (do not continue if) and §8 (rollback).
- The Phase 1 report (in the zip) explains *why* each change was made.
- For anything else, send a message before proceeding. It is always cheaper to ask than to roll back.

Good luck.

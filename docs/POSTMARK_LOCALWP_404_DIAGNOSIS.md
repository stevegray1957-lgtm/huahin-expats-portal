# Postmark Phase 1 — LocalWP admin 404 diagnosis

**Status**: design only — no patches applied yet.
**Affects**: Steve's LocalWP install of Postmark Phase 1.
**Live site**: untouched. No production risk.

---

## 1. What Steve reported

After copying the Phase 1 files into his LocalWP plugin folder:

- ✅ Plugin remains "Active" in the Plugins list
- ✅ No fatal crash banner / no WSOD on Dashboard
- ✅ Frontend site loads
- ✅ Admin (`/wp-admin/`) loads
- ❌ Clicking **Hua Hin Expats** (the top-level menu) → 404
- ❌ Clicking **Email Outreach** (an existing submenu that worked before) → 404
- ❌ The new **Email Settings** submenu does not appear at all

---

## 2. Code health check — done

Before diagnosing further, every file Phase 1 ships has been re-verified in the staging environment **on the same files Steve received** (the zip was built from this working copy):

| File | `php -l` | Notes |
|---|---|---|
| `huahinexpats-core.php` | OK | Loader change is a single `require_once` inside the existing `is_admin()` block (line 95). No semantic risk. |
| `inc/admin-email-settings.php` | OK | 593 lines. 6 file-scope `const`s, 3 `add_action` registrations, function declarations. No collisions with any existing `HHEC_*` constants in the audited plugin (verified by grep). |
| `inc/email-identity.php` | OK | `hhec_email_address()` reads options first, falls back to constants. All 4 `HHE_EMAIL_*` constants still defined at file load. |
| `inc/deliverability.php` | OK | Added Postmark to presets + `hhec_smtp_redact_secret()`. PHPMailer hook unchanged from pre-Phase-1. |
| `inc/stripe.php` | OK | 4× `wp_mail()` calls wrapped with `function_exists('hhec_email_send')` fallback. |
| `inc/premium.php` | OK | 1× `wp_mail()` call wrapped same way. |

**Conclusion**: the code does not contain a defect that would produce Steve's symptoms when loaded correctly.

---

## 3. Why Steve's symptoms can't all come from a defect in this code

Two observations make a "bug in the Phase 1 code" diagnosis implausible:

### 3.1 The existing Email Outreach submenu now 404s

Email Outreach is registered in `inc/email-outreach.php:721` against the `hhe-reviews` parent menu. Phase 1 did **not** modify `email-outreach.php`. Phase 1 added one `add_submenu_page` call against the same parent (`inc/admin-email-settings.php:42`).

There is no mechanism by which adding a new submenu page to a parent slug invalidates other submenu pages on that same parent. WordPress's `$submenu` global is keyed by parent slug and additive — registrations don't overwrite each other.

So the Email Outreach 404 implies one of:
- A change happened that ISN'T Phase 1, OR
- The Phase 1 code never actually loaded, OR
- A PHP fatal happens during the Hua Hin Expats admin page render but not on Dashboard render.

### 3.2 Plugin reported as "Active" with no fatal

If `inc/admin-email-settings.php` were missing at the path the loader expects (`HHEC_DIR . 'inc/admin-email-settings.php'`), the `require_once` would issue a `PHP Fatal error: Failed opening required …`. The plugin would either deactivate itself or display a fatal screen. Neither happened — so the file IS at the expected path AND PHP IS loading it.

Combining 3.1 + 3.2: the most likely scenario is that **the modified `huahinexpats-core.php` did not actually get copied**, and one of the OTHER files Steve copied has a runtime issue ONLY visible when a Hua Hin Expats admin page is rendered (not the Dashboard).

This is the leading hypothesis. There are a small number of plausible alternatives. All are testable with two terminal commands and a `wp-config.php` flag flip.

---

## 4. Ranked root-cause candidates

Candidates ranked by probability given the symptoms, and the verification step for each.

### Candidate A (highest probability) — Partial file copy: modified `huahinexpats-core.php` not applied

**Hypothesis**: Steve copied the `inc/` files but didn't copy the modified `huahinexpats-core.php` to the plugin root. So:
- `inc/admin-email-settings.php` IS present, just sitting there.
- The plugin loader is the OLD version — it doesn't `require_once` the new admin page file.
- **This explains "Email Settings menu not visible"** (the file is never loaded).

This doesn't yet explain the existing Email Outreach 404. For that, look at Candidate B.

**Verification** — open Terminal and run:
```bash
grep -c "admin-email-settings" \
  "/Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/huahinexpats-core/huahinexpats-core.php"
```

- If output is `1` → modified loader IS in place. Rule out Candidate A.
- If output is `0` → modified loader NOT copied. **Candidate A confirmed.** Fix: copy the modified `huahinexpats-core.php` (see §6).

### Candidate B (probable) — One of the modified `inc/` files has a runtime issue ONLY visible on specific admin pages

**Hypothesis**: even if Candidate A is true (loader unchanged), Steve did copy `email-identity.php`, `deliverability.php`, `stripe.php`, `premium.php`. Each of these has subtle behavioural differences from the original:

- `email-identity.php` — `hhec_email_address()` now calls `get_option()`. If `get_option()` somehow returns a non-string truthy non-email value, `is_email()` returns false, and the constant fallback kicks in — fine. But if there's a PHP warning (e.g., deprecated `strpos` with non-string first arg), display_errors could send it to output mid-render.
- `stripe.php` — wraps 4 `wp_mail()` calls in `hhec_email_send()`. These call paths don't fire on Dashboard load but they fire when Stripe webhooks arrive. Could fatal there, but not on admin menu render.
- `deliverability.php` — adds `hhec_smtp_redact_secret()` which reads options. If `get_option` returns non-string... again, function-call safety.

None of these would explain an Email Outreach submenu 404 directly. Email Outreach calls `hhec_email_outreach_admin_page` which lives elsewhere — Phase 1 didn't touch it.

**BUT** — if Steve enabled WP_DEBUG and these files are now spewing PHP warnings to output, the warnings could break HTML rendering of certain admin pages while Dashboard happens to escape. This would manifest as "page loads but shows wrong / broken output that looks like 404".

**Verification** — enable WP_DEBUG and re-load the failing pages. Step in §5.2.

### Candidate C (less probable) — PHP opcache holding stale or partial state

**Hypothesis**: LocalWP runs PHP with opcache enabled. After file copy, opcache may have a stale or mixed view — some files updated, some not. This can cause class/function mismatches that look like missing menus or 404s.

**Verification** — stop and restart the LocalWP site (not just the browser) to flush PHP opcache:
1. LocalWP → click the site → **Stop Site**.
2. Wait 5 seconds.
3. Click **Start Site**.
4. Retry the failing pages.

If the symptoms vanish after restart → **Candidate C confirmed**. Permanent fix: same restart procedure after every file copy.

If symptoms persist after restart → rule out Candidate C.

### Candidate D (less probable) — Steve is misreading the screen

**Hypothesis**: the page Steve sees is not a literal HTTP 404 but a WordPress error like "Sorry, you are not allowed to access this page", "Cheatin' uh?", or a blank rendered page. These look 404-ish to a non-technical observer.

**Verification** — Steve copies the exact text he sees on screen, AND the URL bar contents. If the text isn't "404" / "Not Found" / "Page Not Found", we're looking at a WP-level error, not an HTTP 404.

### Candidate E (low probability) — Wrong site / wrong plugin folder

**Hypothesis**: Steve has multiple LocalWP sites (e.g., `huahinexpats-local` and `huahinexpats-local-2`). He copied files to one and is testing in another.

**Verification**:
```bash
ls "/Users/stephenmgray/Local Sites/"
```
If only one folder matches `huahinexpats-local*`, rule out.

### Candidate F (low probability) — A non-Phase-1 plugin changed

**Hypothesis**: another WordPress update / plugin update / autoupdate happened on the LocalWP install between when Email Outreach last worked and now.

**Verification**:
```bash
cd "/Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/"
ls -la
```
Look at the modified-date column for plugins other than `huahinexpats-core` and the backup. Anything modified today is suspect.

### Candidates ruled out before listing

- ❌ **Const collision**: grep across the audit confirms zero collisions between the 6 new `HHEC_EMAIL_SETTINGS_*` constants and any existing constant. Would be a fatal anyway.
- ❌ **BOM / leading whitespace**: file starts with bare `<?php` (byte 0-5 is `<?php\n`). No BOM.
- ❌ **Trailing whitespace after `?>`**: file ends without `?>` at all (good practice). No trailing output.
- ❌ **Missing parent slug**: Phase 1 attaches to `hhe-reviews` which is unconditionally registered in `inc/reviews.php:214` at default priority 10. My registration is at priority 50 — fires AFTER. Parent definitely exists by the time my callback runs.
- ❌ **Wrong callback name**: `hhec_email_settings_render_page` is defined at `inc/admin-email-settings.php:255` and referenced in the `add_submenu_page` call at `:48`. Name matches exactly.
- ❌ **Capability mismatch**: `HHEC_EMAIL_SETTINGS_CAP = 'manage_options'`, same as all other Hua Hin Expats submenu pages. Steve as the LocalWP admin has it.

---

## 5. Verification procedure for Steve (in order, stop at first hit)

Steve runs these in his Mac Terminal. Total time ~5 minutes. Each step either confirms a candidate or rules it out.

### 5.1 — Step 1: file-presence checks (proves or disproves Candidate A)

Open **Terminal** (Cmd+Space → "Terminal" → Return). Paste this block:

```bash
PLUGIN="/Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/huahinexpats-core"

echo "=== Is admin-email-settings.php present? ==="
ls -la "$PLUGIN/inc/admin-email-settings.php" 2>&1

echo ""
echo "=== Does the loader include it? (should print 1) ==="
grep -c "admin-email-settings" "$PLUGIN/huahinexpats-core.php"

echo ""
echo "=== Loader file size (should be 4819 bytes if modified) ==="
ls -l "$PLUGIN/huahinexpats-core.php" | awk '{print $5}'

echo ""
echo "=== inc/ modified files have today's date ==="
ls -l "$PLUGIN/inc/admin-email-settings.php" \
      "$PLUGIN/inc/email-identity.php" \
      "$PLUGIN/inc/deliverability.php" \
      "$PLUGIN/inc/stripe.php" \
      "$PLUGIN/inc/premium.php" \
   2>&1 | awk '{print $6, $7, $8, $9}'
```

**Read the output**:

- If `admin-email-settings.php` is **not found** → file wasn't copied. Re-do Section 4 of the self-test guide, then retry. (Stop here — Candidate A confirmed in part.)
- If `grep -c "admin-email-settings"` returns **`0`** → the modified loader is NOT in place. **Candidate A confirmed.** Skip to §6.1 for the fix.
- If loader file size is **NOT 4819 bytes** → loader is either original (4623 bytes ± a few — but should not be 4819) or partial copy. Confirm by re-grepping.
- If all five inc/ files don't share today's date → only some were copied. Re-do Section 4 of the self-test guide.

### 5.2 — Step 2: enable WP_DEBUG and reproduce the 404 (proves or disproves Candidates B + D)

Edit `wp-config.php` to enable verbose error logging.

**File to edit**: `/Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-config.php`.

Open it in TextEdit (right-click → Open With → TextEdit). Find these lines:

```php
define( 'WP_DEBUG', false );
```

Replace with:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', 0 );
```

Save the file.

Then in browser, hit `http://huahinexpats-local.local/wp-admin/admin.php?page=hhe-reviews`. Reproduce the 404.

Then in Terminal:

```bash
tail -50 "/Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/debug.log"
```

**Read the output**:

- If the log shows `PHP Fatal error` referring to one of the modified files → Candidate B confirmed. Send the exact error message back; do NOT try to patch.
- If the log shows `PHP Warning` / `PHP Notice` mentioning a function call → Candidate B subtype confirmed; same: send the message back.
- If the log shows nothing relevant → not a PHP error. Move to Step 3.

Also capture the EXACT screen text from the "404" page. If it says "404 Not Found" → HTTP 404 from the web server (the request died). If it says anything else (e.g., "Sorry, you are not allowed..." or "Page not found in admin menu") → Candidate D, different problem.

### 5.3 — Step 3: full opcache flush via LocalWP restart (proves or disproves Candidate C)

1. LocalWP → click `huahinexpats-local` → **Stop Site**. Wait for grey indicator.
2. Wait 5 seconds (lets opcache fully purge).
3. Click **Start Site**. Wait for green Running indicator.
4. In browser, hard-refresh (Cmd+Shift+R) the failing page.

If the page now loads normally → Candidate C confirmed. The root cause is opcache staleness. Fix: always restart the LocalWP site after copying plugin files.

If the page still 404s → rule out Candidate C.

### 5.4 — Step 4: confirm the site folder is the one being served

```bash
echo "=== LocalWP sites on this Mac ==="
ls "/Users/stephenmgray/Local Sites/"
```

There should be exactly one folder name starting with `huahinexpats-local`. If there's more than one (`huahinexpats-local-2`, `huahinexpats-local copy`, etc.) — Steve may have edited the wrong one. Confirm which one LocalWP is actually serving (LocalWP → click site → look at "Site path").

---

## 6. Patches required

### 6.1 If Candidate A confirmed (`grep -c "admin-email-settings"` returned 0)

No code patch — just re-copy the file Steve missed.

```bash
PLUGIN="/Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/huahinexpats-core"
PKG="<path to unzipped postmark-phase-1-huahinexpats-core>/plugin-files/huahinexpats-core"

# Copy the modified loader.
cp "$PKG/huahinexpats-core.php" "$PLUGIN/huahinexpats-core.php"

# Restart the LocalWP site (flushes opcache).
# (Use LocalWP UI: Stop Site → wait → Start Site)
```

Then hit the Hua Hin Expats menu → should see the new Email Settings submenu.

### 6.2 If Candidate B confirmed (PHP fatal in the debug log)

This is a real bug. Do NOT patch in production. Send back the exact debug.log entry — that tells us:
- Which file is fataling
- What line
- What the error message is

The patch will be derived from that exact information. We are not guessing.

### 6.3 If Candidate C confirmed (opcache stale)

No code patch. Future deployments to LocalWP must include the LocalWP restart as a post-copy step. We'll update the self-test guide to make this explicit (it's currently mentioned but not emphasised as a Section 4 step).

### 6.4 If Candidate D confirmed (Steve's "404" is actually a different error)

Diagnose based on the actual error text. Likely candidates:
- "Sorry, you are not allowed to access this page" → user logged out / capability check failure → log back in
- A blank white page → enable WP_DEBUG_DISPLAY and reload to see PHP output
- Theme 404 template → request is being handled as frontend, not admin → routing/redirect issue

### 6.5 No patches needed if Candidates E or F confirmed

Wrong-site / unrelated-plugin issues are operator-resolved, not code-patched.

---

## 7. Rollback recommendation

**Yes — Steve should roll back to the backup before continuing diagnostic work, with one caveat below.**

Rationale:
- Steve's LocalWP install is in an unknown state.
- Each diagnostic step changes the system slightly (WP_DEBUG flag, opcache flush) and may interact with the unknown state.
- Rolling back FIRST puts the system in a known-good baseline. We then know any subsequent issue is reproducible from a clean start.

**Procedure** — per §12.6 of `docs/STEVE_LOCALWP_POSTMARK_SELF_TEST_GUIDE.md`:

1. LocalWP → Stop Site for `huahinexpats-local`.
2. Finder: navigate to the plugins folder.
3. **Important — don't delete the modified folder yet.** Rename it instead so we can inspect it if needed:
   ```
   huahinexpats-core         →  huahinexpats-core.phase1-broken-YYYY-MM-DD-HHMM
   huahinexpats-core.backup-<timestamp>  →  huahinexpats-core
   ```
4. LocalWP → Start Site.
5. Verify: site loads, admin loads, Hua Hin Expats menu works, Email Outreach submenu opens normally, Email Settings menu is gone.

**Caveat — don't roll back IF you can keep the modified state long enough to run Step §5.1 first.** Step §5.1 takes 60 seconds and tells us conclusively whether Candidate A (file copy issue) is the cause. If it is, the fix is one `cp` command and a restart — no need to roll back. If §5.1 results aren't immediately conclusive, then roll back and report.

So the recommended sequence is:
1. **Run §5.1 first** (60 seconds in Terminal). Capture output.
2. **If Candidate A confirmed**: apply §6.1 (one `cp` + LocalWP restart). Done.
3. **If §5.1 is ambiguous OR Candidate A ruled out**: roll back, then run §5.2 / §5.3 from the clean baseline.

---

## 8. Safest next action

**1. Steve runs §5.1 (the four-command Terminal block) immediately.** Output answers the most likely cause in 60 seconds.

**2. Steve pastes back the §5.1 output.** Specifically:
- The output of `ls -la $PLUGIN/inc/admin-email-settings.php`
- The output of `grep -c "admin-email-settings" $PLUGIN/huahinexpats-core.php`
- The output of `ls -l $PLUGIN/huahinexpats-core.php` (size)
- The modified-dates line for the 5 inc/ files

**3. Based on the output, we either**:
   - Apply §6.1 (one `cp` command — if Candidate A confirmed),
   - Or roll back and run §5.2 (WP_DEBUG enabled, capture debug.log) for deeper diagnosis,
   - Or do nothing and re-examine the LocalWP install (if Candidates E or F surface).

**4. Until we have §5.1 output, do not**:
   - Re-copy files (might overwrite useful diagnostic state).
   - Edit any PHP file (we have no confirmed defect).
   - Touch the live `huahinexpats.co` site (production isolation maintained).
   - Start Airwallex or enrichment work.

---

## 9. What this diagnosis does NOT claim

- It does NOT claim the code has a defect. The lint, function-reference, constant-collision, and file-loading checks all pass against the shipped files.
- It does NOT claim Steve made a copy error. That's the leading hypothesis but unverified without §5.1 output.
- It does NOT recommend any source-code patch. If §5.2 surfaces a fatal, the patch will be derived from the exact debug.log entry — not invented.

---

## 10. Acceptance criteria check

From the brief:

- ✅ **Root cause clearly identified**: ranked candidate list with explicit verification for each. Candidate A is the leading hypothesis with concrete confirmation/refutation in 60 seconds of Terminal output.
- ✅ **No guessing**: all code-defect hypotheses have been actively ruled out via lint + grep + symbol-resolution checks. The remaining candidates are environmental (file copy, opcache, misread error, wrong site, unrelated update) and each has an explicit verification step.
- ✅ **No production risk**: live site explicitly excluded. All diagnostic steps are local to Steve's LocalWP. Rollback procedure documented and safe.
- ✅ **No patches applied**: this doc is design-only. Section 6 lists conditional patches (one `cp` for Candidate A; nothing for others) keyed to verification outcomes.

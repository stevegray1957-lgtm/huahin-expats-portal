# Steve's LocalWP Postmark Phase 1 self-test guide

**Lachie is unavailable.** You're going to test the Postmark email changes by yourself, on your local LocalWP copy only. Nothing you do here touches the live `huahinexpats.co` site.

**Total time**: ~30 minutes if everything works first try. ~45 minutes if Postmark signup is a new exercise.

**What you produce at the end**: a short report (Section 13) that you paste back so we know whether the live deployment is safe to schedule.

> **A more detailed walkthrough exists at `docs/STEVE_LOCALWP_POSTMARK_APPLY_GUIDE.md`** with extra explanation. This file is the tight self-test version. If anything below is unclear, jump to the detailed guide for context.

---

## Section 0 — Before you start

You need:

- [ ] Mac with LocalWP installed and the site `huahinexpats-local` listed in LocalWP.
- [ ] The zip file `postmark-phase-1-huahinexpats-core.zip` downloaded somewhere on your Mac (Desktop or Downloads is fine). It lives in this repo at `packages/postmark-phase-1-huahinexpats-core.zip`.
- [ ] A web browser open.
- [ ] A personal email inbox you can sign in to (Gmail, iCloud, etc.) — needed for Postmark sign-up and as the test recipient.
- [ ] 30 minutes of uninterrupted time.

You do **not** need: Terminal experience, a live-server login, Lachie.

---

## Section 1 — Paths you'll use

These are the two paths you keep coming back to:

| What | Path |
|---|---|
| Local plugin folder (what we're updating) | `/Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/huahinexpats-core` |
| Package (the zip) | `packages/postmark-phase-1-huahinexpats-core.zip` in the Portal repo, or wherever you've downloaded it |

If your LocalWP site is named differently (e.g. you reinstalled and it's `huahinexpats-local-2`), replace `huahinexpats-local` in the plugin path with the actual name. You can confirm in LocalWP by clicking the site and looking at the "Site path" shown in the panel.

---

## Section 2 — Back up the plugin folder

**Do not skip this.** This backup is what restores you to safety if anything goes wrong.

1. Open **LocalWP**.
2. Click your site `huahinexpats-local` in the left sidebar.
3. Right-click the site → **Reveal in Finder** (or click the "Go to site folder" link in the site panel). A Finder window opens at the site folder.
4. In Finder, navigate: `app` → `public` → `wp-content` → `plugins`.
5. You should see a folder called `huahinexpats-core`. **Click once on it to highlight it** (don't double-click).
6. Press **`Cmd + D`** to duplicate. Finder makes `huahinexpats-core copy`.
7. Click once on `huahinexpats-core copy`, press Return, and rename it to include today's date and time:
   ```
   huahinexpats-core.backup-2026-05-17-1432
   ```
   Use the actual current date and time, format `YYYY-MM-DD-HHMM`.
8. Press Return to confirm.

You should now see two folders side by side:
- `huahinexpats-core` ← the one we'll change
- `huahinexpats-core.backup-2026-05-17-1432` ← your safety net

**Write the backup folder name on a sticky note** — you'll need it in Section 12 (rollback) and in the final report (Section 13).

---

## Section 3 — Unzip the package

1. In Finder, locate `postmark-phase-1-huahinexpats-core.zip`.
2. Double-click it. macOS unzips it next to the zip file, into a folder called `postmark-phase-1-huahinexpats-core`.
3. Open that folder. You should see:
   ```
   postmark-phase-1-huahinexpats-core/
   ├── README.md
   ├── POSTMARK_PHASE_1_APPLY_CHECKLIST.md
   ├── POSTMARK_PHASE_1_TRANSACTIONAL_EMAIL_REPORT.md
   └── plugin-files/
       ├── huahinexpats-core/
       │   ├── huahinexpats-core.php
       │   └── inc/
       │       ├── admin-email-settings.php
       │       ├── deliverability.php
       │       ├── email-identity.php
       │       ├── premium.php
       │       └── stripe.php
       └── scripts/
           └── postmark-phase-1-smoke-test.sh
   ```

The folder you'll copy *from* is `plugin-files/huahinexpats-core/`. Keep this Finder window open.

---

## Section 4 — Copy the six changed plugin files

You have two Finder windows open:

- **Window A**: the unzipped package, showing `plugin-files/huahinexpats-core/`
- **Window B**: the LocalWP plugin folder, showing the contents of `huahinexpats-core/` (the original — NOT the backup)

You'll drag six files from A into B, replacing five and adding one.

### 4.1 The main plugin file (1 of 6)

1. In **Window A**, you should see `huahinexpats-core.php` and an `inc/` folder.
2. Drag `huahinexpats-core.php` from Window A into Window B.
3. Finder asks: **"An item named 'huahinexpats-core.php' already exists. ..."**. Click **Replace**.

### 4.2 The five `inc/` files (2-6 of 6)

1. In **Window A**, double-click into `inc/`. You should see exactly five files:
   - `admin-email-settings.php` ← brand new
   - `deliverability.php`
   - `email-identity.php`
   - `premium.php`
   - `stripe.php`
2. In **Window B**, double-click into `huahinexpats-core/inc/`. You'll see many existing files.
3. Select all five files in Window A: click the first, hold Shift, click the last (or `Cmd + A`).
4. Drag them into Window B's `inc/` folder.
5. Finder will prompt about four replacements. Click **Replace** four times (or hold Option while clicking to "Replace All"). The fifth file (`admin-email-settings.php`) is new and appears without prompting.

### 4.3 Verify the copy worked

In Window B's `inc/` folder:

- Sort by Date Modified (View → Sort By → Date Modified).
- The four updated files plus the one new file should sit at the top with today's date stamps.
- `admin-email-settings.php` should be visible.
- All other plugin files (claims.php, importer.php, etc.) must still be present.

If any non-Phase-1 file looks missing or moved: stop and roll back (Section 12).

---

## Section 5 — Start the LocalWP site

1. Switch back to **LocalWP**.
2. Click `huahinexpats-local`. If the indicator says "Stopped", click **Start Site**.
3. Wait for the green "Running" indicator.

---

## Section 6 — Verify the local site loads

1. In LocalWP, click the big **Open Site** button. Your browser opens to your local site (`http://huahinexpats-local.local/` or similar).
2. The home page should load normally — header, menus, content. No errors. No "There has been a critical error on this website" banner.

✅ If the frontend loads: proceed to Section 7.
🛑 If it shows a white screen or a critical-error banner: **stop and roll back** (Section 12).

---

## Section 7 — Open WP Admin and verify plugin

1. Back in LocalWP, click **WP Admin** (the button next to "Open Site"). The admin login or dashboard opens in your browser.
2. Log in with your LocalWP admin user (typically `admin` and the password you set when creating the site).
3. The Dashboard should load with no red error banners.
4. In the left sidebar, click **Plugins**. The plugin **HuaHinExpats Core** must appear in the active list with no "this plugin has been deactivated due to error" banner.

✅ Plugin active, no errors: proceed to Section 8.
🛑 Plugin shows as deactivated with an error, or the admin pages WSOD: **stop and roll back** (Section 12).

---

## Section 8 — Find Hua Hin Expats → Email Settings

1. In the WordPress admin sidebar, hover over **Hua Hin Expats** (the storefront icon).
2. Look at the submenu. You should see entries including:
   - Reviews
   - Subscriptions
   - Claims
   - Stripe Settings
   - **Email Settings** ← this is the new one
   - Moderation
   - (and others)
3. Click **Email Settings**.

The page should render with sections "Transport", "Addresses", "Routing rules", "Send test email", "DNS authentication", "DNS records to add (Postmark)".

✅ Page renders: proceed to Section 9.
🛑 Email Settings is not in the submenu, or clicking it produces a "permissions denied" or 404: **stop and roll back** (Section 12).

---

## Section 9 — Get Postmark credentials

If you already have a Postmark account, skip to 9.4.

### 9.1 Sign up

1. Open https://postmarkapp.com/sign_up.
2. Sign up with your own email address (e.g., your Gmail).
3. Confirm your email by clicking the link Postmark sends.

### 9.2 Create a Server

If you weren't prompted to create one during sign-up:

1. In Postmark, click **Servers** → **Create Server**.
2. Name it `HuaHinExpats Local Test`. Choose any color.
3. Save.

### 9.3 Verify a Sender Signature

Because LocalWP runs on a fake domain (`huahinexpats-local.local`), Postmark won't let you send AS `info@huahinexpats.co` from here. Instead you'll send AS your own email.

1. In Postmark, click **Sender Signatures** (top nav).
2. Your sign-up email should already be **Verified** (green check). If not:
   - Click **Add Sender Signature** → enter your personal email → confirm via the link Postmark sends.
3. Note the verified address — you'll paste it into WordPress as the From email.

### 9.4 Capture the Server API Token

1. In Postmark → Servers → your `HuaHinExpats Local Test` Server → click the **API Tokens** tab.
2. The "Server API Token" is a UUID-shaped string like `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`.
3. Click **Show**, then click the copy icon.
4. **Keep this token private.** Don't paste it into chat, git, or email.

---

## Section 10 — Enter Postmark SMTP settings

Back in WP Admin → **Hua Hin Expats → Email Settings**.

### Transport section

| Field | Value |
|---|---|
| **Transactional mail** | ☑ Check the box |
| **Provider** | Select `Postmark` |
| **SMTP host** | `smtp.postmarkapp.com` |
| **SMTP port** | `587` |
| **Encryption** | `TLS (STARTTLS)` |
| **SMTP username / API token** | Paste the Postmark Server API Token |
| **SMTP password / API token** | Paste the **same** token (yes, same value in both fields) |
| **DKIM selector** | Leave blank |

### Addresses section

| Field | Value (for LocalWP test) |
|---|---|
| **From name** | `Hua Hin Expats` |
| **From email (info)** | Your verified Postmark sender (e.g., `stephen@gmail.com`) — **not** `info@huahinexpats.co` |
| **Default Reply-To** | Same as From email |
| **Accounts email** | Same as From email |
| **Legal email** | Same as From email |
| **Test recipient** | The inbox where you want the test email (your Gmail, or `your-name+localtest@gmail.com` for the +trick) |

Then click **Save email settings**.

### Verify the save

- Green banner: **"Email settings saved."**
- Scroll back up to the SMTP password field. It must be **empty** with placeholder text `(saved — leave blank to keep)`.
- The username field keeps your token (that's fine — username is treated as an identifier).

🛑 If the password field shows your token value back to you after save: **stop and roll back** (Section 12). Also rotate the Postmark API token (in Postmark → API Tokens → Issue new).

---

## Section 11 — Send the test email

1. Scroll down to the **Send test email** section.
2. The button should be enabled. (If greyed out: re-tick "Transactional mail" at top, confirm test recipient is set, save, and refresh.)
3. Click **Send test email**.

### Success path

- Green banner: "Test email sent to `<your-test-recipient>`."
- A **Last test result** panel appears showing **OK** in green.

### Confirm the email arrived

1. Open your test inbox.
2. Within ~60 seconds, expect an email:
   - **From**: `Hua Hin Expats <your-verified-email>`
   - **Subject**: starts with `[Hua Hin Expats Local Test]` (or your site title) — `Test email — Postmark / SMTP verification`
3. If it's in Spam, that's normal on LocalWP (because `huahinexpats-local.local` is a fake domain). Move to inbox.

### Cross-check Postmark Activity

1. In Postmark → your Server → **Activity** tab.
2. Within ~30 seconds the test send should appear with status **Sent** or **Delivered**.

✅ Email arrived AND Postmark Activity logs the send: test passed. Go to Section 13 (report).
🛑 Test failed three times in a row, AND Postmark Activity shows no record: **stop and roll back** (Section 12).

### Optional but recommended — also test WordPress password reset

This proves WordPress's own emails (password reset, new-user) still flow correctly.

1. WP Admin → **Users** → click your admin user → set the **Email** field to your verified Postmark sender (LocalWP default is a `.test` address that Postmark would reject). Save.
2. Log out.
3. At the login page, click **Lost your password?** → enter your username → submit.
4. Within 60 seconds the reset email arrives at the inbox you set.

✅ Reset email arrives: WordPress core mail works.
⚠️ Reset email doesn't arrive but the test email did: check Postmark Activity. If the reset send is logged, it's a recipient/spam issue, not a code issue.
🛑 Reset send isn't logged in Postmark Activity at all: **stop and roll back** (Section 12).

---

## Section 12 — STOP IMMEDIATELY if any of these happen

These are hard-stop conditions. Don't push through, don't try to "fix" — go straight to rollback (Section 12.6).

### 12.1 White screen / "There has been a critical error"

- Frontend or admin shows a blank page or the WordPress critical-error notice.
- **Why**: a PHP error in one of the copied files.
- **Action**: rollback.

### 12.2 Plugin error on the Plugins page

- HuaHinExpats Core shows as Inactive with an error banner.
- Or: "Plugin caused unexpected output during activation" warning.
- **Why**: PHP load or syntax error.
- **Action**: rollback.

### 12.3 Email Settings menu missing

- After Section 4 file copy, the **Email Settings** entry doesn't appear under Hua Hin Expats.
- **Why**: the new file `inc/admin-email-settings.php` didn't load, or the loader edit in `huahinexpats-core.php` didn't take effect.
- **Action**: rollback.

### 12.4 SMTP test fails repeatedly

- Three test sends, same credentials, all fail.
- AND Postmark Activity shows no record of any of the three.
- **Why**: credentials wrong, sender not verified, or network blocked.
- **Action**: re-check Section 9 (correct token? sender signature verified for the From email?). If three more attempts with confirmed-correct credentials still fail, rollback.

### 12.5 Credentials visible after save

- The password field renders any value back to you after save (instead of being empty with placeholder text).
- The token appears in the page HTML (View → Developer → View Source, then search for the first few characters of your token).
- An error message includes your literal token value.
- **Why**: serious — the secret-hiding mechanism is broken.
- **Action**: rollback **immediately** AND rotate the Postmark token (Postmark → API Tokens → Issue new). The exposed token may be cached in browser history.

### 12.6 How to rollback (do this now if any 12.1–12.5 fired)

Per the brief: delete the modified plugin folder, restore from backup, restart LocalWP.

1. **Stop the LocalWP site**: in LocalWP, click your site → **Stop Site**. Wait for the indicator to go grey.
2. **Open Finder** at the plugins folder (LocalWP → "Reveal in Finder" → `app/public/wp-content/plugins/`).
3. **Delete the modified folder**:
   - Click `huahinexpats-core` once to highlight.
   - Press **`Cmd + Delete`** (moves to Trash — recoverable for a while if needed).
   - Confirm.
4. **Restore the backup**:
   - Click `huahinexpats-core.backup-<your-timestamp>` once.
   - Press Return to rename.
   - Rename it to `huahinexpats-core` (the original name).
   - Press Return.
5. **Restart LocalWP**:
   - Back in LocalWP, click **Start Site** for `huahinexpats-local`.
   - Wait for the green Running indicator.
6. **Verify rollback worked**:
   - Click **Open Site** → frontend loads normally.
   - Click **WP Admin** → admin loads normally.
   - Plugins page → HuaHinExpats Core is Active, no error banner.
   - Hua Hin Expats sidebar → the **Email Settings** entry is **gone**. Confirms you're back on pre-Phase-1 code.

If the rollback itself fails (frontend still broken after restore): empty the Trash is NOT recommended yet — the deleted folder is recoverable from there. Send the report (Section 13) with rollback notes and ask for help before doing anything destructive.

---

## Section 13 — Final report (copy-paste this back)

When you're done — whether you passed everything or had to roll back — paste this filled-in block back:

```
=== POSTMARK PHASE 1 LOCALWP SELF-TEST REPORT ===
Date:    <YYYY-MM-DD HH:MM local>
Tester:  Steve
Site:    huahinexpats-local (LocalWP)
Backup folder name: huahinexpats-core.backup-<your-timestamp>

— Test results —
Local site loads after copy:       yes / no
HuaHinExpats Core plugin active:   yes / no
Email Settings menu visible:       yes / no
Postmark Server API token entered: yes / no
Test email sent (button clicked):  yes / no
Test email received in inbox:      yes / no
Postmark Activity shows the send:  yes / no
WP password reset email works:     yes / no / skipped
Credentials hidden after save:     yes / no

— Postmark info —
Postmark Server name used:      <e.g. HuaHinExpats Local Test>
Verified sender used (From:):   <e.g. stephen@gmail.com>
Test recipient inbox:           <e.g. stephen+localtest@gmail.com>

— Errors / observations —
<paste any error messages from the WP admin, the Last Test Result panel,
 the browser console, the PHP error log if you saw one, etc.>

<if you rolled back, note WHICH step (Section 12.x) triggered it>

— Overall —
Phase 1 working in LocalWP:        yes / no / partial
Confidence to deploy live:         high / medium / low / not yet
Anything you want a second look at: <free text>

=== END REPORT ===
```

Fill in every line. "Skipped" is a valid answer for the password reset row if you didn't run it.

---

## Section 14 — What's next (and what isn't)

After you paste the report back:

- ✅ If everything is green: we'll schedule the live deployment for Lachie when he's available.
- ⚠️ If anything is yellow/red: we'll diagnose and (most likely) ship a small fix before re-testing.

**Not happening yet — regardless of the test result**:
- ❌ No Airwallex work.
- ❌ No Google Places / enrichment work.
- ❌ No Postmark Phase 2 (per-Server SMTP isolation).
- ❌ No live deployment until you've reported back AND we've confirmed safe-to-proceed.

Don't run the live deployment yourself, even if the local test goes perfectly. The live cutover has DNS, Postmark domain verification, and live-data implications that need Lachie's server access.

---

## Section 15 — One-page reference

Keep this section visible while testing:

```
Local plugin folder:
  /Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/huahinexpats-core

Backup folder you made (Section 2):
  huahinexpats-core.backup-YYYY-MM-DD-HHMM

Package zip:
  packages/postmark-phase-1-huahinexpats-core.zip

LocalWP buttons:
  - Reveal in Finder / Go to site folder
  - Start Site / Stop Site
  - Open Site
  - WP Admin

URLs:
  http://huahinexpats-local.local/           — frontend
  http://huahinexpats-local.local/wp-admin   — admin
  https://account.postmarkapp.com/           — Postmark dashboard

Postmark settings to paste into Email Settings:
  Provider:    Postmark
  SMTP host:   smtp.postmarkapp.com
  SMTP port:   587
  Encryption:  TLS (STARTTLS)
  Username:    <Server API Token>
  Password:    <same Server API Token>
  From email:  <your verified Postmark Sender Signature>
  Test recipient: <an inbox you can check>

Rollback recipe (Section 12.6):
  1. LocalWP → Stop Site
  2. Finder: delete `huahinexpats-core` (Cmd+Delete)
  3. Finder: rename `huahinexpats-core.backup-...` → `huahinexpats-core`
  4. LocalWP → Start Site
  5. Verify frontend and admin still work, Email Settings menu is gone
```

---

## One last thing

If the test email doesn't arrive on the first try, **don't panic and don't immediately roll back unless Section 12 says to**. Re-read Section 12.4 first, check the things in order, try again. SMTP misconfiguration is the most common first-attempt failure and is almost always fixable without rollback (wrong token, wrong From: address, network blocked).

If you're stuck for more than 15 minutes on the same problem, **stop and ask** — paste back where you got to, what you tried, and what you see. Don't push through.

Good luck.

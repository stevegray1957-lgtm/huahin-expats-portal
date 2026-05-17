# LocalWP — Postmark Phase 1 apply guide (for Steve)

Hands-on walkthrough for testing the Postmark Phase 1 changes in your local LocalWP site before anything touches the live `huahinexpats.co`.

**Read this entire guide once before starting.** Then come back and follow it step by step.

Total time: about 30 minutes if everything goes smoothly. 45 minutes if you've never used Postmark before.

---

## What you're doing (in one paragraph)

You have a zip file with five updated plugin files and one new one. You'll back up your current local plugin folder, copy the new files in, log into your local WordPress admin, configure Postmark (a transactional email service), and click "Send test email." If the test email lands in your inbox, you're done — report back, and we wait before continuing to Airwallex or anything else.

If anything looks wrong at any step, **stop and roll back** (Section 12). It's much cheaper to back out than to push through a broken state.

---

## Section 0 — What you need before starting

Tick these off before opening the zip:

- [ ] Mac with LocalWP already installed.
- [ ] The LocalWP site `huahinexpats-local` is listed and runs (clicking it should show a green "Running" or "Open Site" button).
- [ ] The zip file downloaded: `postmark-phase-1-huahinexpats-core.zip` (about 60 KB). Where you save it on your Mac doesn't matter — Desktop or Downloads is fine.
- [ ] A web browser open.
- [ ] An email inbox you can check (Gmail / iCloud / whatever — needs to be an inbox you can verify a "click this link" email in).
- [ ] About 30 minutes uninterrupted.

You do **not** need:
- Terminal experience (this guide uses Finder where possible).
- A Postmark account (we'll create one in Section 8).
- A live `huahinexpats.co` login.

---

## Section 1 — Locate the plugin folder

The folder we're going to back up and then update is:

```
/Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/huahinexpats-core
```

That's a long path, but you don't need to type it. Two easy ways to reach it:

### Easiest — open it from LocalWP

1. Open the **LocalWP** app.
2. Click your site `huahinexpats-local` in the left sidebar.
3. Near the top of the site detail panel, click the link labeled **"Go to site folder"** (or right-click the site and choose **"Reveal in Finder"**). This opens Finder at:
   ```
   /Users/stephenmgray/Local Sites/huahinexpats-local/
   ```
4. In Finder, double-click into: `app` → `public` → `wp-content` → `plugins`.
5. You should now see a folder called **`huahinexpats-core`** sitting alongside any other plugins (akismet, hello, etc.).
6. **You're now in the plugins folder.** Keep this Finder window open — you'll use it in the next sections.

### Alternative — open it via Finder's "Go to Folder"

1. In Finder, press **`Cmd + Shift + G`**.
2. Paste this path:
   ```
   /Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/
   ```
3. Press Return.
4. You're now in the plugins folder.

If the folder doesn't exist or you get an error, **stop**: your LocalWP site path may be different (sometimes LocalWP uses `huahinexpats-local-2` if you've reinstalled). Open LocalWP, click the site, and look at the path it shows. Replace `huahinexpats-local` in the path above with whatever LocalWP shows.

---

## Section 2 — Back up the current plugin folder

This is the most important step. If anything breaks later, this backup is what restores you to safety. **Do not skip.**

### Easy way (Finder)

1. In the Finder window showing the `plugins/` folder, **click once** on the folder named `huahinexpats-core` to highlight it (do NOT double-click; that would open it).
2. Press **`Cmd + D`** to duplicate it. Finder makes a copy called `huahinexpats-core copy`.
3. Click once on `huahinexpats-core copy` to highlight it, then press Return (or right-click → Rename).
4. Rename it to include today's date and time, like:
   ```
   huahinexpats-core.backup-2026-05-17-1432
   ```
   (Use the date and time you're doing this — the format is `YYYY-MM-DD-HHMM`.)
5. Press Return to confirm the new name.

You should now see TWO folders in the plugins directory:
- `huahinexpats-core` (the one we'll change)
- `huahinexpats-core.backup-2026-05-17-1432` (your safety net)

**Write the backup folder name on a sticky note or in a text file.** You'll need it if you have to roll back.

### Alternative (Terminal — only if you prefer)

1. Open **Terminal** (Cmd+Space, type "Terminal", press Return).
2. Paste this command, replacing the timestamp with the current one:
   ```bash
   cd "/Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/"
   cp -R huahinexpats-core "huahinexpats-core.backup-$(date +%Y-%m-%d-%H%M)"
   ls -la | grep huahinexpats-core
   ```
3. The output should list both the original `huahinexpats-core` and the new backup.

---

## Section 3 — Unzip the package

1. In Finder, find your downloaded `postmark-phase-1-huahinexpats-core.zip` (Desktop or Downloads).
2. Double-click it. macOS unzips it automatically into a folder called `postmark-phase-1-huahinexpats-core` right next to the zip file.
3. Open the unzipped folder. You should see:
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
4. The folder you'll use for copying files is **`plugin-files/huahinexpats-core/`** — note this path, you'll come back to it.

---

## Section 4 — Copy the new files into the plugin

You have two Finder windows open:
- **Window A**: the unzipped package, showing `plugin-files/huahinexpats-core/`
- **Window B**: the LocalWP plugin folder, showing `wp-content/plugins/huahinexpats-core/` (the original, not the backup)

You're going to drag six files from Window A into Window B, replacing the existing ones.

### File 1 — the main plugin file

1. In **Window A**, double-click into `plugin-files/huahinexpats-core/`. You should see:
   - `huahinexpats-core.php` (the file you want)
   - `inc/` (a folder we'll handle next)
2. **Click once** on `huahinexpats-core.php` to select it.
3. In **Window B**, navigate into `huahinexpats-core/` (the one we're updating, NOT the backup). You should see a file with the same name plus an `inc/` folder.
4. Drag `huahinexpats-core.php` from Window A into Window B.
5. Finder will ask: **"An item named 'huahinexpats-core.php' already exists. Do you want to keep both, stop, or replace?"** Click **Replace**.
6. The file is now updated.

### Files 2-6 — the inc/ files

1. In **Window A**, double-click into the `inc/` folder. You should see five files:
   - `admin-email-settings.php`
   - `deliverability.php`
   - `email-identity.php`
   - `premium.php`
   - `stripe.php`
2. In **Window B**, double-click into `huahinexpats-core/inc/`. You'll see lots of existing files.
3. Select all five files in Window A: click the first, hold Shift, click the last. (Or press `Cmd + A` to select all five.)
4. Drag them from Window A into Window B's `inc/` folder.
5. Finder asks about replacements for four of them (`deliverability.php`, `email-identity.php`, `premium.php`, `stripe.php`) — click **Replace**. (For "Replace All" press the Option key while clicking Replace, or click Replace four times.)
6. The fifth file, `admin-email-settings.php`, is brand new — it appears alongside the others without prompting.

### Verify the copy worked

After the copies finish, you should see in `Window B → inc/`:

- ✅ A file called `admin-email-settings.php` that did not exist before. You can verify by sorting Window B by Date Modified (View menu → Sort By → Date Modified): the four updated files and the one new file should all show today's date.
- ✅ All the other plugin files (claims.php, importer.php, etc.) untouched.

If any of those files appear missing, **stop** — you may have accidentally dragged them out. Restore from the backup (Section 12).

---

## Section 5 — Verify the LocalWP site still loads

This is the first sanity check.

1. Open **LocalWP**.
2. Click your site `huahinexpats-local`. Confirm the site is **Running** (green dot). If not, click **Start Site**.
3. Click the big **Open Site** button (top-right of the LocalWP site panel). Your browser opens to your local site, something like `http://huahinexpats-local.local/`.
4. The site should load normally — header, menu, homepage content. No error messages, no white screen, no "There has been a critical error" banner.

**If the site loads normally**: proceed to Section 6.

**If the site shows "There has been a critical error" or a blank white page**: stop. Go to Section 12 (rollback). The new files have a problem on your specific PHP version. Do not try to fix it yourself — restore the backup and report back.

---

## Section 6 — Log into WordPress admin

1. Still in LocalWP, click **WP Admin** (the button next to "Open Site"). LocalWP opens your browser at the wp-admin login OR logs you in directly if it has cached credentials.
2. If prompted for a username and password, use your local-site admin credentials. (LocalWP usually sets these to whatever you chose when creating the site, often `admin` / your-chosen-password. If you don't remember: in LocalWP, go to **Tools → Open Site Shell**, type `wp user list`, then `wp user reset-password <username>`. Or: ask for help — local-only password recovery is safe.)
3. You should land at the WordPress Dashboard at `http://huahinexpats-local.local/wp-admin/`.

The Dashboard should look normal. No red error banners, no "Plugin caused unexpected output" warnings. Plugins should all be active.

**Quick health check**: click **Plugins** in the left sidebar. The plugin **HuaHinExpats Core** should appear in the active list. If it shows "Plugin file does not exist" or "Plugin could not be activated because it triggered a fatal error", **stop and roll back** (Section 12).

---

## Section 7 — Find Hua Hin Expats → Email Settings

1. In the WordPress admin left sidebar, look for **Hua Hin Expats** (it has a small storefront/shop icon).
2. Hover or click to expand the submenu. You should see entries including:
   - Reviews
   - Subscriptions
   - Claims
   - Stripe Settings
   - **Email Settings** ← brand new
   - Moderation
   - (and others)
3. Click **Email Settings**.

The page should render with these sections, top to bottom:
- **Transport** (toggle, provider, host, port, encryption, username, password, DKIM selector)
- **Addresses** (from name, info email, reply-to, accounts email, legal email, test recipient)
- **Routing rules** (read-only table)
- **Send test email** (button)
- **Last test result** (empty for now)
- **DNS authentication** (probably all FAIL since LocalWP isn't on a real domain — that's expected, ignore)
- **DNS records to add (Postmark)** (reference table)

**If the Email Settings submenu does not appear**: stop. Go to Section 11.

---

## Section 8 — Get Postmark credentials

You need three things from Postmark before configuring WordPress:

1. A Postmark account
2. A verified Sender Signature (an email address you own)
3. A Server API Token

### 8.1 Sign up for Postmark (free)

1. In your browser, go to **https://postmarkapp.com/sign_up**.
2. Sign up with your own email address (e.g., your Gmail). This is important — Postmark will auto-verify this email as a Sender Signature.
3. Confirm the email via the link Postmark sends.
4. When asked to create a Server (Postmark calls projects "Servers"), name it something like `HuaHinExpats Local Test`. Choose color "Yellow" or whatever — it's just a label.

### 8.2 Capture the Server API Token

1. In Postmark, with your new Server selected, click the **API Tokens** tab (top of the Server page).
2. You'll see a "Server API Token" — a long string like `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`.
3. Click **Show**, then click the **copy** icon to copy it to your clipboard.
4. **Keep this token private.** Don't paste it into chat, email, or git. Treat it like a password.

### 8.3 Verify the Sender Signature

Since LocalWP runs on a fake domain (`huahinexpats-local.local`), you can't send AS `info@huahinexpats.co` from here — Postmark would reject it because `huahinexpats.co` isn't verified in your Postmark account. Instead, you'll send AS your own personal email address.

1. In Postmark, click **Sender Signatures** (top nav).
2. Your sign-up email should already be listed as **Verified** (green check). If not:
   - Click **Add Sender Signature**.
   - Enter your email address (the same one you used to sign up, e.g., `stephen@gmail.com`).
   - Postmark sends a confirmation email. Click the link in your inbox.
   - Return to Postmark — the signature should now show as Verified.

### 8.4 Pick a test recipient

Use a different inbox from the verified sender if you can — that way the test verifies actual delivery to another mailbox. If you only have one inbox, that's fine, you'll just send to yourself.

Examples:
- Verified Postmark sender: `stephen@gmail.com`
- Test recipient: `stephen+postmark-test@gmail.com` (Gmail accepts the `+suffix` trick) or any other inbox you have.

---

## Section 9 — Enter Postmark settings in WordPress

Back in WP Admin → Hua Hin Expats → Email Settings.

### 9.1 Transport section

| Field | What to enter |
|---|---|
| **Transactional mail** | ☑ Check the box (enabled) |
| **Provider** | Select `Postmark` from the dropdown |
| **SMTP host** | `smtp.postmarkapp.com` |
| **SMTP port** | `587` |
| **Encryption** | `TLS (STARTTLS)` |
| **SMTP username / API token** | Paste the Postmark Server API Token (from Section 8.2) |
| **SMTP password / API token** | Paste the **same** Postmark Server API Token (yes, the same value in both fields — that's how Postmark works) |
| **DKIM selector** | Leave blank for now |

### 9.2 Addresses section

| Field | What to enter |
|---|---|
| **From name** | `Hua Hin Expats` |
| **From email (info)** | Your verified Postmark Sender Signature (e.g., `stephen@gmail.com`) — **NOT `info@huahinexpats.co`** for local testing |
| **Default Reply-To** | Same as From email |
| **Accounts email** | Same as From email (for local testing — production will use `accounts@huahinexpats.co`) |
| **Legal email** | Same as From email (for local testing) |
| **Test recipient** | The inbox where you want the test email to arrive (e.g., `stephen+postmark-test@gmail.com`) |

**Why all addresses are your own email**: Postmark rejects any send from an address whose domain or signature isn't verified. On LocalWP we haven't verified `huahinexpats.co`, so we use your verified personal address everywhere. When this code reaches the live site, Lachie verifies the real domain and changes these addresses.

### 9.3 Save

1. Scroll down. Click **Save email settings**.
2. The page reloads with a green banner: **"Email settings saved."**
3. **Now scroll up and look at the SMTP password / API token field.** It should be **empty** with placeholder text `(saved — leave blank to keep)`.
4. **The username field** keeps the value you entered. That's correct — it's the identifier, not the secret.

**If the password field shows your token value back to you**: stop. Go to Section 11.4 (rollback). This shouldn't happen — it would mean the secret-hiding mechanism is broken.

---

## Section 10 — Send the test email

1. Scroll to the **Send test email** section.
2. The button should be enabled (not greyed out). If it's greyed out:
   - Check that **Transactional mail** is ticked at the top.
   - Check that **Test recipient** has an email in it.
   - Save the page again.
3. Click **Send test email**.
4. The page reloads with one of these notices:

### Success
- Green banner: **"Test email sent to `<your-test-recipient>`."**
- A **Last test result** panel appears below the button showing **OK** in green.

### Failure
- Red banner: **"Test email failed."**
- A grey panel below with an error message (credentials redacted).

If success, proceed to Section 11.

If failure, see Section 12 — common causes.

### Check the email actually arrived

1. Open your test recipient inbox.
2. Within about 60 seconds you should receive an email:
   - **From**: `Hua Hin Expats <your-verified-email>`
   - **Subject**: `[Hua Hin Expats Local Test] Test email — Postmark / SMTP verification` (or similar — the bracketed prefix is your site title in LocalWP, which is whatever you set when you created the site)
   - **Body**: contains "If you received it, transactional email transport is configured correctly." and shows the site URL and a UTC timestamp.
3. **If the email is in Spam**: that's normal on LocalWP because `huahinexpats-local.local` isn't a real domain. Move it to Inbox so you can confirm it's the test email. This is not a problem for the local test.

### Cross-check Postmark Activity

1. Back in Postmark, click **Activity** (top nav, or under your Server).
2. Within 30 seconds of the test send, a row should appear:
   - Recipient: your test inbox
   - Subject: the test subject
   - Status: **Sent** or **Delivered**

If the email arrived AND the Activity log shows it, **the test passed**. Move to Section 11.

If the email did not arrive AND the Activity log shows it: it was sent successfully to the recipient mail server but is held up or routed to spam. Check spam folder. Not a code problem.

If neither happened (no inbox arrival AND no Activity row): the send never reached Postmark. Go to Section 12.3.

---

## Section 11 — Quick verifications before declaring victory

Before reporting success, do these three lightweight checks.

### 11.1 Confirm credentials hidden

- Reload the Email Settings page (Cmd+R).
- Scroll to the SMTP password field.
- It must be **empty**, with placeholder `(saved — leave blank to keep)`.
- View the page source (Browser menu → View → Developer → View Source, or Cmd+Option+U). Search for the first 10 characters of your Postmark API token. They should **not** appear anywhere in the page HTML.

**If the token appears anywhere visible**: that's a credential leak. Roll back (Section 12) and rotate the Postmark token immediately (Postmark → Servers → your Server → API Tokens → click "Show", then click "Issue new").

### 11.2 Confirm WordPress core mail still works

Test that password reset still functions — this is the critical "didn't break anything" check.

1. In a new browser tab, open `http://huahinexpats-local.local/wp-login.php?action=lostpassword`.
2. Enter your local admin username.
3. Submit.
4. Check the inbox associated with your local admin user (you can confirm what that is by going to WP Admin → Users → your-admin-user → Email field). On LocalWP this is usually a placeholder email like `admin@huahinexpats-local.test` — which won't actually deliver via Postmark because that domain isn't verified.

**Important caveat for LocalWP**: WordPress core password reset emails will fail to deliver from LocalWP via Postmark IF the admin user's email is set to a fake `*.local` or `*.test` address (LocalWP's default). This is NOT a Phase 1 bug — it's a Postmark rule (verified senders only). To get a clean test:

- Option A: In WP Admin → Users → your admin user → change Email to your verified Postmark sender address (e.g., your Gmail). Save. Then test password reset.
- Option B: Skip the password reset test in LocalWP and rely on the live-site test by Lachie to verify it. Make a note in your report that you skipped it.

If Option A: password reset arrives within 60 seconds → ✅ pass.

### 11.3 Confirm routing rules look right

On the Email Settings page, scroll to the **Routing rules** table. Look at the **Resolved address** column. For LocalWP:

| Mail type | Role | Expected resolved address |
|---|---|---|
| Claim form submission | info | your verified Postmark sender |
| Outreach invitation | info | your verified Postmark sender |
| Stripe upgrade receipt | accounts | your verified Postmark sender (same as info, for local test) |
| Stripe refund admin | accounts | same |
| Premium expiry warning | accounts | same |
| Legal / privacy | legal | same |

All rows should show the address you put in the relevant field at Section 9.2. If any row shows a different address (especially `info@huahinexpats.co` when you set it to your Gmail), refresh the page — there may have been a caching glitch.

---

## Section 12 — Things that can go wrong, and what to do

### 12.1 White screen / "There has been a critical error" after copying files

**What it means**: PHP syntax or runtime error in one of the new files.

**What to do**:
1. Stop everything.
2. Go to Section 13 (Rollback).
3. After rollback confirm the site loads.
4. Report back with: the exact error message if you saw one, and your PHP version (Find it in LocalWP: click the site → "Site Settings" tab → "PHP Version").

### 12.2 Plugins page shows HuaHinExpats Core as "Inactive" or with an error banner

**What it means**: the plugin file is present but PHP couldn't load it.

**What to do**:
1. Roll back per Section 13.
2. Report back with the error banner text.

### 12.3 SMTP test fails

**What it means**: WordPress tried to send to Postmark but something went wrong. Common causes:

- **Wrong API token**: most common. Re-copy the Server API Token from Postmark (Section 8.2). Make sure no leading/trailing spaces. Paste in BOTH the username and password fields. Save. Retry.
- **From email isn't a verified Sender Signature**: Postmark rejects sends from unverified addresses. Confirm Section 8.3 — the email in your "From email" field on the WordPress page **must** match a verified Sender Signature in Postmark. Postmark's error message in this case says something like "The 'From' address is not a Sender Signature on this account." Update the From email on the WordPress page to match your Postmark-verified address.
- **Network blocked**: open Terminal and run `nc -vz smtp.postmarkapp.com 587`. If it doesn't say "succeeded" or "open", your network or firewall is blocking. Try a different network.
- **Wrong port or encryption**: confirm `587` and `TLS (STARTTLS)`.

If none of these resolves it after three retries, roll back (Section 13) and report the exact error text from the "Last test result" panel.

### 12.4 Credentials visible on screen after save

**What it means**: serious — the password field is rendering the token back to the page.

**What to do**:
1. Immediately roll back (Section 13).
2. Go to Postmark → your Server → API Tokens → **revoke this token** and issue a new one. The token in clipboard / browser history may be compromised.
3. Report back urgently — do not retry.

### 12.5 Email Settings submenu missing

**What it means**: the new admin file `inc/admin-email-settings.php` didn't load.

**What to do**:
1. Verify in Finder that the file IS present in `wp-content/plugins/huahinexpats-core/inc/admin-email-settings.php`.
2. If it's not there: redo Section 4.
3. If it IS there: there may be a load order or PHP version issue. Roll back (Section 13) and report.

### 12.6 Password reset email doesn't arrive (after changing admin email to verified)

**What it means**: WordPress core mail isn't routing through the SMTP relay properly.

**What to do**:
1. Check Postmark Activity log — was the password reset send recorded?
   - If YES, recorded as Bounced or Spam: not a code issue, it's the recipient mailbox routing.
   - If YES, recorded as Sent / Delivered but not in your inbox: check spam.
   - If NO record at all: WordPress core didn't route through SMTP. This is a Phase 1 problem. Roll back (Section 13) and report.
2. Check the `wp_hhe_email_log` table: in LocalWP, click **Database** (or "Open Adminer") in the site panel. Look in the `wp_hhe_email_log` table for a row with `category` containing `password` or `wp_core` — actually password resets won't appear there because they bypass the send wrapper.

---

## Section 13 — Rollback (restore the backup)

Use this when ANY of Section 12 fires.

### Easy way (Finder)

1. In Finder, navigate to the plugins folder:
   ```
   /Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/
   ```
2. You should see:
   - `huahinexpats-core` (the broken one)
   - `huahinexpats-core.backup-<your-timestamp>` (your safety net from Section 2)
3. **Rename the broken folder out of the way** (don't delete it yet — keep it for diagnosis):
   - Click `huahinexpats-core` once to select.
   - Press Return.
   - Rename to `huahinexpats-core.broken-2026-05-17-<current-time>`.
4. **Rename the backup folder to take its place**:
   - Click `huahinexpats-core.backup-2026-05-17-1432` once to select.
   - Press Return.
   - Rename to `huahinexpats-core` (the original name).

You now have:
- `huahinexpats-core` — restored, working
- `huahinexpats-core.broken-...` — kept for diagnosis

### Verify the rollback worked

1. In LocalWP, click your site. If it shows "Stopped", click **Start Site**.
2. Click **Open Site**. The frontend should load normally.
3. Click **WP Admin**. The admin should load normally.
4. Plugins page → HuaHinExpats Core should be Active with no errors.
5. The "Email Settings" submenu under Hua Hin Expats should be **gone** — confirming you're back on the pre-Phase-1 code.

### After rollback

1. Report back with:
   - What step in this guide triggered the rollback (e.g., "Section 5 — white screen after copy")
   - The exact error message you saw, if any
   - Your PHP version (LocalWP → site → Site Settings → PHP Version)
   - Whether the backup restored cleanly (it should)
2. Don't try to apply Phase 1 again until we look at what went wrong.

---

## Section 14 — When success

If you got all the way through Sections 5–11 with no Section 12 conditions firing, **Phase 1 works in your LocalWP**.

Send a short note back covering:

1. ✅ **Applied to LocalWP**: yes
2. ✅ **Backup folder**: `huahinexpats-core.backup-<your-timestamp>` (left in place — leave it for now)
3. ✅ **Test email**: delivered to `<recipient>` via Postmark
4. ✅ **Postmark Activity log**: shows the send as Sent / Delivered
5. ✅ **Credentials hidden after save**: confirmed
6. ⚠️ **WordPress password reset test**: passed / skipped (Option B) / failed — explain
7. **Postmark Server name**: `HuaHinExpats Local Test` (or whatever you named it)
8. **Confidence to proceed to live deployment**: yes / want another round of testing

---

## Section 15 — What's next (and what isn't)

After LocalWP success:

- ✅ Lachie (server admin) follows the live-server deployment checklist (`docs/POSTMARK_PHASE_1_APPLY_CHECKLIST.md`) and the handover note (`docs/POSTMARK_PHASE_1_HANDOVER_FOR_LACHIE.md`) on the production site.
- ✅ DNS records get placed at the registrar.
- ✅ Postmark verifies the real `huahinexpats.co` domain (full DKIM setup).
- ✅ Lachie reconfigures the live Email Settings page with `info@huahinexpats.co` etc. instead of your personal email.

**What's NOT happening yet**:

- ❌ No Airwallex work yet. Wait for live-site email confirmation first.
- ❌ No enrichment / Google Places work. Same.
- ❌ No Postmark Phase 2 (per-Server SMTP isolation). That's a later sprint.
- ❌ No deploying your LocalWP database to live. LocalWP is for testing only — production has its own state.

---

## Section 16 — Quick reference card

Keep these next to you while applying:

```
Plugin folder:
  /Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/huahinexpats-core

Backup folder (Section 2):
  /Users/stephenmgray/Local Sites/huahinexpats-local/app/public/wp-content/plugins/huahinexpats-core.backup-YYYY-MM-DD-HHMM

LocalWP buttons used:
  - "Go to site folder" → opens Finder at the site path
  - "Open Site"         → opens browser at the local frontend
  - "WP Admin"          → opens browser at the admin login
  - "Site Settings"     → shows PHP version, paths, etc.

Browser URLs used:
  http://huahinexpats-local.local/         — frontend
  http://huahinexpats-local.local/wp-admin — admin
  https://account.postmarkapp.com/         — Postmark dashboard

Postmark Email Settings field values:
  Provider:    Postmark
  SMTP host:   smtp.postmarkapp.com
  SMTP port:   587
  Encryption:  TLS (STARTTLS)
  Username:    <Server API token from Postmark>
  Password:    <same Server API token>
  From email:  <your verified Postmark sender, e.g. stephen@gmail.com>

Roll back command (Section 13):
  Finder: rename broken folder → broken-<timestamp>, rename backup → huahinexpats-core
```

---

## One last thing

This guide assumes things work the first time. They often don't on the first attempt — that's normal. If the test email doesn't arrive on your first try, **don't panic and don't rush to roll back unless Section 12 says to**. Re-read Section 12.3, check the things in order, and try again.

If you genuinely get stuck for more than 15 minutes, **stop and ask**. It's much cheaper to ask than to push through.

Good luck.

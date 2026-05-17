# Email Deliverability Architecture — Postmark + per-role SMTP

Status: design — not yet implemented.
Scope: DNS records at the `huahinexpats.co` registrar, Postmark account configuration, and WordPress code changes in `wp-content/plugins/huahinexpats-core/inc/`.
Provider chosen: **Postmark** (selected for transactional-focused deliverability, per-Server reputation isolation, and clean DKIM model).
Audit basis: full read of `inc/email-identity.php`, `inc/deliverability.php`, and the central send wrapper in `inc/email-outreach.php`. Every file/line reference points into the audited tree.

---

## 1. Decision summary

**Three Postmark Servers, one DNS-records set on `huahinexpats.co`, role-aware SMTP credential selection in `phpmailer_init`. The existing `hhec_email_send()` wrapper already routes by `from_role` — we extend the SMTP layer to honour the role, not the send wrapper.**

What this gives:
- Independent send reputation per mailbox (info@ outreach problems don't taint accounts@ billing receipts).
- Independent suppression lists per Server (an unsubscribe from outreach doesn't block a billing email).
- Independent Activity / bounce dashboards per Server.
- One shared DKIM record (Postmark signs with one key per domain, regardless of how many Servers use it).
- DMARC alignment via a Return-Path CNAME (envelope-from on `huahinexpats.co` instead of `pm.mtasv.net`).
- Backward compatibility: the existing single-account SMTP config keeps working as the fallback path until per-role tokens are configured.

What this does NOT do:
- Inbound mail routing (replies to info@/accounts@/legal@ landing in actual mailboxes) — that lives at the mail-host (Google Workspace / Fastmail / similar). §10 covers the MX records and the operator decision; no code changes.
- Marketing email (newsletters). Out of scope — the existing `inc/newsletter.php` (226 lines) is its own subsystem and uses the same SMTP relay; it inherits the new per-role routing transparently if/when its From: address is changed, but no other changes are required.

---

## 2. What the existing code already does (grounded in audit)

| Concern | File:line | Current behaviour |
|---|---|---|
| Canonical addresses | `inc/email-identity.php:24-27` | `HHE_EMAIL_INFO`, `HHE_EMAIL_ACCOUNTS`, `HHE_EMAIL_LEGAL`, `HHE_EMAIL_FROM_NAME` defined as constants, overridable via `wp-config.php` |
| Role → address resolver | `inc/email-identity.php:35-41` | `hhec_email_address($role)` returns the canonical address |
| Default From: filter | `inc/email-identity.php:164-180` | `wp_mail_from` and `wp_mail_from_name` filters — only override the WordPress default ("wordpress@host" / "WordPress"). Leave alone if another plugin set custom |
| Admin email sync | `inc/email-identity.php:67-72` | Forces `get_option('admin_email')` to `info@huahinexpats.co` on `admin_init` |
| SMTP settings store | `inc/deliverability.php:25-35` | Single account: `hhe_smtp_enabled`, `hhe_smtp_provider`, `hhe_smtp_host`, `hhe_smtp_port`, `hhe_smtp_encryption`, `hhe_smtp_username`, `hhe_smtp_password` |
| Provider presets | `inc/deliverability.php:42-49` | sendgrid / ses / mailgun / custom. **No Postmark preset** |
| PHPMailer hook | `inc/deliverability.php:55-71` | One `phpmailer_init` action. Reads single credential set. Skips if disabled or any field empty |
| DNS auth checks | `inc/deliverability.php:96-115` | Cached 6h. Probes SPF (TXT on root), DKIM (selector list), DMARC (TXT on `_dmarc.<domain>`) |
| DKIM selector probe list | `inc/deliverability.php:148-156` | sendgrid (`sg`, `s1.sg`), mailgun (`s1.smtp`), SES (`mte1`), default/`s1`/`k1`, plus operator-supplied `hhe_smtp_dkim_selector`. **No Postmark selector pattern** (`*pm._domainkey` or auto-generated dated selectors) |
| Send wrapper | `inc/email-outreach.php:324-408` | `hhec_email_send($args)` — accepts `from_role` (default `info`); resolves via `hhec_email_address($a['from_role'])`; sets per-send `wp_mail_from`/`wp_mail_from_name` filters at priority 99; passes to `wp_mail()`; logs to `wp_hhe_email_log` |
| List-Unsubscribe header | `inc/email-outreach.php:366-367` | RFC 8058 compliant — `List-Unsubscribe: <url>` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click` |
| Reply tracking | `inc/deliverability.php:202-280` | REST route `hhe/v1/email-reply` accepts provider-agnostic JSON. Suppressed by shared-secret query param |
| Click tracking | `inc/deliverability.php:183-234` | REST route `hhe/v1/email-click` with HMAC token. 302 redirect after logging |
| Suppression list | `inc/email-outreach.php:80-112` | `wp_hhe_email_suppression` table; `hhec_email_is_suppressed()` gate inside `hhec_email_send()` |
| Bounce auto-suppress | `inc/data-quality.php:210-231` | After ≥3 `wp_mail_failed` events for a recipient, auto-suppresses |
| Test mode | `inc/email-outreach.php:351-360, :448` | Option `hhec_email_test_mode` (default '1'). When on, outreach mail redirects to admin_email with subject prefix `[TEST → original@addr]`. Transactional mail (claim approval, enquiry replies) is exempt by design |
| Reply-to per role | `inc/email-outreach.php:651, :708` | Claim confirmation sets `reply_to => info@`; upgrade promotion sets `reply_to => accounts@` |

**Read of the architecture**: From: address routing by role is fully wired. SMTP is a single shared bottleneck. DNS authentication probing exists but doesn't know Postmark. The send wrapper is provider-agnostic and stays that way.

---

## 3. Decision: provider = Postmark

Rationale weighed against the alternatives the form offered (SES, SendGrid, Mailgun):

| Criterion | Postmark | SES | SendGrid | Mailgun |
|---|---|---|---|---|
| Transactional reputation isolation per Server | **yes — first-class** | Configuration Sets (manual) | Subusers (paid tier) | Domains (basic) |
| DKIM model | One CNAME per domain, auto-rotates | Three CNAMEs (Easy DKIM) | CNAMEs under em*.subdomain | TXT record |
| Sandbox / approval | Account starts unrestricted | Sandbox until approval | OK | OK |
| Activity log | 45 days per message, full body | 14 days metadata only | Variable | Variable |
| SMTP simplicity | Username = password = Server API token | IAM SMTP credentials | apikey literal + key | postmaster@domain + key |
| Per-message price | ~$0.001 (10k/mo plan) | Cheapest (~$0.0001) | Tiered monthly | Tiered monthly |
| Thai deliverability | Strong (US infra, well-warmed) | Strong | Strong | Strong |

Postmark wins on Server-level isolation (exactly what "separate SMTP accounts per role" needs), Activity introspection during incidents, and operational simplicity (one CNAME). Loss on per-message cost is small at projected volume (transactional only, well under 10k/month).

This decision can be reversed without code rewrite — the SMTP layer is provider-agnostic. Only the DNS records and the Postmark-specific selectors in `inc/deliverability.php:148-156` would change.

---

## 4. DNS records to set at the registrar

All records on `huahinexpats.co`. **Exact values for SPF and Return-Path are deterministic; the DKIM record value is auto-generated by Postmark and must be copied from the Postmark dashboard (Sender Signatures → huahinexpats.co → DNS).**

### 4.1 SPF — one TXT record on the root

Record:
```
Type:  TXT
Name:  @  (or huahinexpats.co — depends on registrar UI)
Value: v=spf1 include:spf.mtasv.net ~all
TTL:   3600
```

`spf.mtasv.net` is Postmark's published include. `~all` (softfail) for the first 14 days while we monitor DMARC aggregate reports for unexpected senders, then move to `-all` (hardfail). See §6.

If `huahinexpats.co` also sends from Google Workspace (mailbox-host replies, calendar invites), the SPF must list both:
```
v=spf1 include:_spf.google.com include:spf.mtasv.net ~all
```

Order does not matter for SPF semantics. The 10-DNS-lookup limit applies — each `include:` costs lookups. Google + Postmark together is ~4 lookups; safe.

**Do not** create more than one `v=spf1` TXT record on the root. Multiple SPF records is an RFC violation and most receivers will fail SPF outright when they see two. The check function in `inc/deliverability.php:117-128` only reads the first matching record, so a second one would silently misbehave.

### 4.2 DKIM — one CNAME per domain (Postmark auto-generates the selector)

Postmark issues a rotation-capable DKIM selector when you add a Sender Signature for `huahinexpats.co`. The selector format is `<timestamp>pm` (e.g. `20240115160500pm`). The CNAME shape:

```
Type:  CNAME
Name:  <selector>._domainkey
Value: <selector>.dkim.postmarkapp.com
TTL:   3600
```

After the record propagates, Postmark "verifies" it in the dashboard and starts signing all outbound mail with this selector. The same selector is used by all three Servers (info, accounts, legal) because Postmark signs at the domain level, not the Server level.

Rotation: Postmark supports key rotation by adding a new CNAME with a new selector, verifying it, then removing the old one. Operator-managed; not automated.

**Update the DKIM probe in `inc/deliverability.php:148-156`** — see §7.4.

### 4.3 Return-Path / custom bounce — one CNAME for DMARC alignment

Without this, Postmark uses `*.pm.mtasv.net` as the SMTP envelope sender (Return-Path). DMARC then evaluates SPF against `pm.mtasv.net`, not `huahinexpats.co`, which fails SPF alignment. DKIM alignment alone is enough for DMARC pass, but we want both.

Record:
```
Type:  CNAME
Name:  pm-bounces
Value: pm.mtasv.net
TTL:   3600
```

Then in each Postmark Server's settings, enable "Return-Path domain" and select `pm-bounces.huahinexpats.co`. Postmark's outbound envelope-from becomes `<random>@pm-bounces.huahinexpats.co`, and the SPF check on that domain hits `spf.mtasv.net` via the parent's SPF record — alignment achieved.

### 4.4 DMARC — one TXT record at `_dmarc.huahinexpats.co`

Three-phase rollout. Start at `p=none` (monitor only), progress to `p=quarantine`, finally `p=reject`. Each phase requires aggregate reports to confirm legitimate senders before tightening.

**Phase A — first 14 days (monitor-only):**
```
Type:  TXT
Name:  _dmarc
Value: v=DMARC1; p=none; rua=mailto:dmarc-aggregate@huahinexpats.co; ruf=mailto:dmarc-forensic@huahinexpats.co; fo=1; aspf=r; adkim=r; pct=100
TTL:   3600
```

`p=none` means no enforcement. `rua` collects daily aggregate XML reports (volume, alignment, sources). `ruf` collects forensic per-message reports (each failure). `fo=1` requests forensic on any auth failure. `aspf=r` / `adkim=r` allow relaxed alignment (subdomain matches root) — strict alignment is rarely needed for our shape.

The mailbox `dmarc-aggregate@huahinexpats.co` is set up as an alias to a real inbox you read weekly. Aggregate XML reports are dense; a free parser like `dmarcian.com` or `valimail.com` ingests them. We do not store DMARC reports in WordPress.

**Phase B — after 14 days, if reports show 100% pass for huahinexpats.co-originated mail:**
```
v=DMARC1; p=quarantine; rua=mailto:dmarc-aggregate@huahinexpats.co; ruf=mailto:dmarc-forensic@huahinexpats.co; fo=1; aspf=r; adkim=r; pct=10
```

`pct=10` — only 10% of failing mail is quarantined; staged rollout. Increase to 50, then 100 over the next 14 days.

**Phase C — once `p=quarantine; pct=100` has been stable for ≥14 days:**
```
v=DMARC1; p=reject; rua=mailto:dmarc-aggregate@huahinexpats.co; ruf=mailto:dmarc-forensic@huahinexpats.co; fo=1; aspf=r; adkim=r; pct=100
```

Failing mail is rejected by recipient servers. This is the destination.

The operator is responsible for the timeline. The architecture doc does not advance phases automatically; each phase change is a manual TXT record edit at the registrar. A new admin notice in §7.5 surfaces the current `p=` value as a reminder.

### 4.5 Optional — MTA-STS and TLS-RPT (Phase 6 hardening)

`mta-sts.huahinexpats.co` policy host + `_smtp._tls.huahinexpats.co` TXT for TLS-RPT. Both improve TLS-in-transit guarantees. Out of scope for first rollout; revisited in §11 Phase 4.

### 4.6 BIMI (Phase 6+)

BIMI ("brand indicators") requires DMARC at `p=quarantine` or `p=reject` and a verified SVG logo. Visible benefit (your logo next to From: in Gmail) is operator preference. Not in scope for first rollout.

---

## 5. Postmark account configuration

Done once in the Postmark dashboard. No automation — this is a UI walkthrough captured in the cutover checklist.

### 5.1 Sender Signature (the domain)

Postmark → Sender Signatures → Add Domain → `huahinexpats.co`.

After creation, Postmark shows:
- DKIM CNAME (the selector-named record from §4.2)
- Return-Path CNAME (the `pm-bounces` record from §4.3)

Copy values to the registrar. Wait for verification (typically <10 minutes after propagation).

Then verify each individual sender address as a Sender Signature:
- `info@huahinexpats.co`
- `accounts@huahinexpats.co`
- `legal@huahinexpats.co`

Each verification sends a one-click confirmation email to that address — the operator must have inbound mail routing for these addresses working before this step (§10).

### 5.2 Servers (one per role)

Postmark → Servers → Create Server.

| Server name | Purpose | Stream |
|---|---|---|
| `HHE Info` | Outreach, newsletters, claim confirmations, all "general system mail" | Default Transactional (`outbound`) |
| `HHE Accounts` | Billing / upgrade / receipt / dunning | Default Transactional (`outbound`) |
| `HHE Legal` | Privacy requests, data export confirmations, takedown notices | Default Transactional (`outbound`) |

Each Server gets its own Server API Token (Postmark generates on creation). The token is shown in the Server's "API Tokens" tab. **Capture all three immediately** — they are not retrievable later (only revocable and re-issuable).

For each Server:
- Settings → Sending domain → select `huahinexpats.co` (already verified at the account level).
- Settings → Return-Path → enable, select `pm-bounces.huahinexpats.co`.
- Settings → Allowed Sender Signatures → check the matching address(es):
  - HHE Info → `info@huahinexpats.co`
  - HHE Accounts → `accounts@huahinexpats.co`
  - HHE Legal → `legal@huahinexpats.co`
  - This stops Server X from accidentally sending as Server Y's role.

### 5.3 Suppression lists

Each Server has its own suppression list — Postmark does NOT share suppression between Servers. That's the feature we want (an "unsubscribe from outreach" must not block "your invoice is ready").

Postmark suppresses on:
- Hard bounce (automatic)
- Spam complaint (automatic)
- Manual addition via API or UI
- Unsubscribe webhook (if configured)

Our existing `wp_hhe_email_suppression` (`inc/email-outreach.php:55-65`) is the WordPress-side suppression and remains the authoritative source for **operational** suppression (driven by `hhec_email_suppress()` at `inc/email-outreach.php:97-112`). Postmark's per-Server suppression is the **transport-side** suppression that prevents accidental retries.

The two lists are not synced automatically. Phase 2 introduces a sync job — see §11 Phase 2.

### 5.4 Webhooks (Phase 2)

Each Server can post webhooks for `Delivery`, `Bounce`, `SpamComplaint`, `Open`, `Click`, `SubscriptionChange`. We do not wire all of these immediately.

Phase 1 — enable Bounce + SpamComplaint webhooks on each Server, pointing at the existing reply-webhook endpoint shape:
```
https://huahinexpats.co/wp-json/hhe/v1/email-bounce?secret=<shared-secret>&server=info
```

New REST route (parallel to the existing `hhe/v1/email-reply` at `inc/deliverability.php:202-206`). Adds the bounced address to `wp_hhe_email_suppression` with reason='bounce' and source='postmark_<server>'. See §7.6.

Phase 2 — Inbound webhook for replies, replacing the current provider-agnostic `hhe/v1/email-reply` shape with Postmark's specific payload. The route stays generic — the parser already accepts multiple shapes (`inc/deliverability.php:251-256` reads `from|sender` and `text|body-plain|stripped-text`). Postmark posts `From`, `TextBody`, `HtmlBody` — small parser addition.

### 5.5 Message Streams (kept default)

Postmark allows multiple Streams per Server (one Transactional, one Broadcast, custom Inbound). For Phase 1 each Server uses only the default `outbound` stream. Newsletters in Phase 2 may move to a Broadcast stream on the `HHE Info` Server — but only after newsletter volume crosses a level where it makes deliverability sense to split.

---

## 6. SPF/DKIM/DMARC verification flow

Once §4 records are set and §5 Postmark setup complete, the existing checker at `inc/deliverability.php:96-115` (`hhec_deliverability_checks()`) confirms:

1. SPF row found → `pass=true, value='v=spf1 include:spf.mtasv.net ~all'`.
2. DKIM row found → with the updated selector list (§7.4), the Postmark dated selector matches → `pass=true, selector='20240115160500pm'`.
3. DMARC row found → `pass=true, value='v=DMARC1; p=none; ...'`.

The admin "Deliverability" page renders the three lights. All green = ready for live cutover.

Manual verification commands (for the operator) are documented in the cutover checklist:
```
dig +short TXT huahinexpats.co
dig +short CNAME 20240115160500pm._domainkey.huahinexpats.co
dig +short CNAME pm-bounces.huahinexpats.co
dig +short TXT _dmarc.huahinexpats.co
```

Plus a single live send through Postmark's "Send a Test Email" UI inside each Server, with the recipient being an account at gmail.com / outlook.com / fastmail.com. Reading the full headers in the received mail confirms:
- `Authentication-Results:` shows `spf=pass`, `dkim=pass`, `dmarc=pass`.
- `Return-Path:` is `<random>@pm-bounces.huahinexpats.co`, not `pm.mtasv.net`.

---

## 7. WordPress code changes

### 7.1 New option keys for per-role SMTP

| Option key | Purpose | Storage shape |
|---|---|---|
| `hhe_smtp_info_token` | Postmark API token for HHE Info Server | string, autoloaded |
| `hhe_smtp_accounts_token` | Postmark API token for HHE Accounts Server | string, autoloaded |
| `hhe_smtp_legal_token` | Postmark API token for HHE Legal Server | string, autoloaded |
| `hhe_smtp_message_stream` | Optional Postmark Message Stream override (default `outbound`) | string, autoloaded |
| `hhe_smtp_bounce_webhook_secret` | Shared secret for the Postmark bounce webhook | string, autoloaded |
| `hhe_smtp_host` | Shared. Stays `smtp.postmarkapp.com` for Postmark | string |
| `hhe_smtp_port` | Shared. Stays `587` | int |
| `hhe_smtp_encryption` | Shared. Stays `tls` | string |
| `hhe_smtp_enabled` | Shared (existing) | bool |
| `hhe_smtp_provider` | Shared (existing) — becomes `postmark` | string |
| `hhe_smtp_dkim_selector` | Shared (existing) — operator pastes the Postmark-issued selector for the DNS probe | string |

The legacy options (`hhe_smtp_username`, `hhe_smtp_password`) are retained as a fallback for the case where per-role tokens are not yet configured. Backwards compatible.

### 7.2 Role-aware PHPMailer handler

Replace `hhec_smtp_setup_phpmailer()` at `inc/deliverability.php:55-71` with a role-aware version that inspects the PHPMailer instance's `From` field and selects credentials accordingly.

```php
add_action( 'phpmailer_init', 'hhec_smtp_setup_phpmailer' );

function hhec_smtp_setup_phpmailer( $phpmailer ) {
    $shared = hhec_smtp_settings();   // existing — provides host/port/encryption/enabled
    if ( ! $shared['enabled'] || ! $shared['host'] ) {
        return;
    }

    // Resolve role from PHPMailer's From address.
    $from_addr = strtolower( (string) $phpmailer->From );
    $role      = hhec_smtp_role_from_address( $from_addr );  // 'info' | 'accounts' | 'legal' | 'fallback'

    $creds = hhec_smtp_credentials_for_role( $role );
    if ( ! $creds['username'] || ! $creds['password'] ) {
        // No per-role token configured. Fall through to legacy single-account if present.
        if ( ! $shared['username'] || ! $shared['password'] ) return;
        $creds = [ 'username' => $shared['username'], 'password' => $shared['password'] ];
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = $shared['host'];
    $phpmailer->Port       = (int) $shared['port'];
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = $creds['username'];
    $phpmailer->Password   = $creds['password'];
    $phpmailer->SMTPSecure = ( 'ssl' === $shared['encryption'] ) ? 'ssl' : ( 'tls' === $shared['encryption'] ? 'tls' : '' );
    if ( '' === $phpmailer->SMTPSecure ) {
        $phpmailer->SMTPAutoTLS = false;
    }

    // Optional Postmark message stream header.
    $stream = (string) get_option( 'hhe_smtp_message_stream', 'outbound' );
    if ( $stream !== '' ) {
        $phpmailer->addCustomHeader( 'X-PM-Message-Stream', $stream );
    }
}
```

New helpers (same file, `inc/deliverability.php`, after `hhec_smtp_settings()`):

```php
function hhec_smtp_role_from_address( $from_addr ) {
    if ( ! $from_addr || strpos( $from_addr, '@' ) === false ) return 'fallback';

    // Use the canonical resolvers from email-identity.php to compare
    // against the configured role addresses — so future domain changes
    // via wp-config defines are honoured automatically.
    $info     = strtolower( hhec_email_address( 'info' ) );
    $accounts = strtolower( hhec_email_address( 'accounts' ) );
    $legal    = strtolower( hhec_email_address( 'legal' ) );

    if ( $from_addr === $accounts ) return 'accounts';
    if ( $from_addr === $legal )    return 'legal';
    if ( $from_addr === $info )     return 'info';
    return 'fallback';
}

function hhec_smtp_credentials_for_role( $role ) {
    $token_options = [
        'info'     => 'hhe_smtp_info_token',
        'accounts' => 'hhe_smtp_accounts_token',
        'legal'    => 'hhe_smtp_legal_token',
    ];
    if ( ! isset( $token_options[ $role ] ) ) {
        return [ 'username' => '', 'password' => '' ];
    }
    $token = (string) get_option( $token_options[ $role ], '' );
    if ( $token === '' ) {
        return [ 'username' => '', 'password' => '' ];
    }
    // Postmark idiom: username = password = Server API token.
    return [ 'username' => $token, 'password' => $token ];
}
```

Why inspect `$phpmailer->From` instead of intercepting in the send wrapper: WordPress mail can originate from many places (`wp_new_user_notification_email`, theme contact forms, third-party plugins), not just `hhec_email_send()`. The PHPMailer hook is the single chokepoint where the From: is already resolved. The send wrapper's existing role-routing (`inc/email-outreach.php:386-389`) sets the From: filter to the role's address, which is what PHPMailer reads.

### 7.3 Add Postmark to provider presets

Extend `hhec_smtp_provider_presets()` at `inc/deliverability.php:42-49`:

```php
function hhec_smtp_provider_presets() {
    return array(
        'postmark' => array(
            'host'          => 'smtp.postmarkapp.com',
            'port'          => 587,
            'encryption'    => 'tls',
            'username_hint' => __( 'Server API token (same value goes in password)', 'huahinexpats-core' ),
        ),
        'sendgrid' => array( 'host' => 'smtp.sendgrid.net',                  'port' => 587, 'encryption' => 'tls', 'username_hint' => __( 'apikey (literal string)', 'huahinexpats-core' ) ),
        'ses'      => array( 'host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'encryption' => 'tls', 'username_hint' => __( 'SES SMTP credentials user', 'huahinexpats-core' ) ),
        'mailgun'  => array( 'host' => 'smtp.mailgun.org',                   'port' => 587, 'encryption' => 'tls', 'username_hint' => __( 'postmaster@your.domain', 'huahinexpats-core' ) ),
        'custom'   => array( 'host' => '',                                   'port' => 587, 'encryption' => 'tls', 'username_hint' => '' ),
    );
}
```

Postmark goes first so it's the default in the dropdown.

### 7.4 Update DKIM selector probe

Extend the selector list in `hhec_dns_check_dkim()` at `inc/deliverability.php:148-156` to recognise Postmark's dated-selector pattern:

```php
function hhec_dns_check_dkim( $domain ) {
    $selectors = array(
        (string) get_option( 'hhe_smtp_dkim_selector', '' ),
        'default', 's1', 'k1',
        'mte1', // SES default
        's1.smtp', 's2.smtp', // Mailgun
        'sg', 's1.sg', 's2.sg', // SendGrid
        // Postmark uses operator-issued dated selectors; the operator pastes
        // theirs into hhe_smtp_dkim_selector. We do not heuristic-match all
        // possible Postmark selectors — there is no fixed pattern.
    );
    $selectors = array_filter( array_unique( $selectors ) );
    // ... rest unchanged
}
```

The probe relies on the operator pasting the Postmark-issued selector (visible in the Postmark dashboard) into the `hhe_smtp_dkim_selector` option. The settings UI in §7.5 surfaces this field with help text.

### 7.5 Settings page changes

Reuse the existing Deliverability settings admin page (registered elsewhere in `inc/admin-outreach.php` per the audit, not directly read here — but the `hhe_smtp_*` options are surfaced there). Additions:

- **Provider field**: dropdown now includes Postmark as the recommended option.
- **Shared SMTP block**: Host (locked to `smtp.postmarkapp.com` when provider=postmark), Port (587), Encryption (tls). These auto-fill when Postmark is selected.
- **Per-role tokens** — three new password fields:
  - "HHE Info Server API token" → `hhe_smtp_info_token`
  - "HHE Accounts Server API token" → `hhe_smtp_accounts_token`
  - "HHE Legal Server API token" → `hhe_smtp_legal_token`
  - Same "leave blank to keep existing" save semantics as the existing Stripe secret fields (`inc/stripe.php:1124-1132`).
- **DKIM selector input** — `hhe_smtp_dkim_selector` field. Help text: "Paste the selector Postmark generates for your domain (Postmark → Sender Signatures → huahinexpats.co → DNS → DKIM record name, the part before `._domainkey`)."
- **Bounce webhook**: read-only display of the bounce-webhook URL with the configured secret query param. Operator copy-pastes into Postmark's webhook config.
- **DMARC status panel**: enhances the existing DNS check rendering (`hhec_deliverability_checks()` at `inc/deliverability.php:96-115`) by parsing the DMARC `p=` value and rendering a phase indicator:
  - `p=none` → "Phase A: monitoring only (no enforcement). Move to Phase B once you've verified no legitimate senders are failing."
  - `p=quarantine` → "Phase B: failing mail goes to spam. Verify reports for ≥14 days before Phase C."
  - `p=reject` → "Phase C: full enforcement."

### 7.6 Bounce webhook endpoint

New REST route in `inc/deliverability.php` (after the existing `hhe/v1/email-reply` registration at `:202-206`):

```php
// Add inside the existing rest_api_init action (or add another)
register_rest_route( 'hhe/v1', '/email-bounce', array(
    'methods'             => 'POST',
    'callback'            => 'hhec_handle_email_bounce',
    'permission_callback' => '__return_true',  // shared-secret check inside
) );

function hhec_handle_email_bounce( WP_REST_Request $req ) {
    $expected = (string) get_option( 'hhe_smtp_bounce_webhook_secret', '' );
    $got      = (string) $req->get_param( 'secret' );
    if ( '' === $expected || ! hash_equals( $expected, $got ) ) {
        return new WP_REST_Response( [ 'error' => 'bad_secret' ], 403 );
    }

    $body = $req->get_json_params();
    if ( ! is_array( $body ) ) {
        return new WP_REST_Response( [ 'error' => 'bad_payload' ], 400 );
    }

    // Postmark Bounce webhook shape — documented at postmarkapp.com.
    // Critical fields: Email (recipient), Type (HardBounce / SoftBounce /
    // SpamComplaint / SpamNotification / ...), MessageStream, ServerID.
    $email = isset( $body['Email'] )       ? sanitize_email( $body['Email'] ) : '';
    $type  = isset( $body['Type'] )        ? sanitize_key( (string) $body['Type'] ) : '';
    $server_name = isset( $body['Server'] ) ? sanitize_text_field( (string) $body['Server'] ) : '';

    if ( ! is_email( $email ) ) {
        return new WP_REST_Response( [ 'error' => 'bad_email' ], 400 );
    }

    // Only auto-suppress on hard categories.
    $hard_types = [ 'hardbounce', 'spamcomplaint', 'manuallydeactivated', 'badwebmail' ];
    if ( in_array( strtolower( $type ), $hard_types, true ) ) {
        if ( function_exists( 'hhec_email_suppress' ) ) {
            hhec_email_suppress( $email, 'bounce', 'postmark_' . $server_name );
        }
    }

    // Soft bounce: log to email_log but don't suppress. Recipient may
    // recover (transient relay failure). Three soft bounces inside
    // hhec_dq_consider_auto_suppress at inc/data-quality.php:210
    // already handles cumulative suppression.

    return new WP_REST_Response( [ 'ok' => true ], 200 );
}
```

The `?server=info` query param the webhook URL carries (e.g. `?server=info&secret=...`) is informational — captured in the `source` field of the suppression row for diagnostic clarity. Each of the three Postmark Servers gets a distinct webhook URL so we can see which Server's traffic produced which bounce.

The shared-secret auth model mirrors the existing reply-webhook at `inc/deliverability.php:244-249`. Same pattern.

### 7.7 Reply webhook — Postmark inbound parsing

The existing reply-webhook at `inc/deliverability.php:244-280` already accepts multiple payload shapes. Postmark's inbound shape uses fields `From`, `TextBody`, `HtmlBody`. Add to the existing fallback chain at `:255-256`:

```php
$from = sanitize_email(
    $body['from']     ??
    $body['sender']   ??
    $body['From']     ?? ''  // Postmark
);
$msg = sanitize_textarea_field( (string) (
    $body['text']          ??
    $body['body-plain']    ??
    $body['stripped-text'] ??
    $body['TextBody']      ?? ''  // Postmark
) );
```

Backwards compatible — providers that send `from`/`text` (Mailgun, SendGrid Inbound Parse) keep working.

### 7.8 No changes to `hhec_email_send()`

The send wrapper at `inc/email-outreach.php:324-408` is correct as-is. It sets the per-send From: filter at priority 99, which is what PHPMailer reads at the `phpmailer_init` hook. Our new role-aware credential selector reads from `$phpmailer->From` after WordPress has resolved it. No edit needed.

Same for `hhec_email_outreach_invitation()`, `_reminder()`, `_final_reminder()`, `hhec_email_claim_confirmation()` (`inc/email-outreach.php:618`), `hhec_email_upgrade_promotion()` (`:676`). They already declare `from_role`. The plumbing extends transparently.

---

## 8. Per-mail-type routing inventory (which role sends which mail)

Audit of `inc/email-outreach.php` and the wider codebase, with the role each mail type sends from. Today's behaviour and the new credential routing are identical at the From: layer — the change is which Postmark Server transports them.

| Mail type | Send function | From role | Reply-To | Postmark Server |
|---|---|---|---|---|
| Outreach invitation (Email 1) | `hhec_email_outreach_invitation()` `:497` | info | info | HHE Info |
| Outreach reminder (Email 2) | `hhec_email_outreach_reminder()` `:512` | info | info | HHE Info |
| Outreach final reminder | `hhec_email_outreach_final_reminder()` `:527` | info | info | HHE Info |
| Claim confirmation | `hhec_email_claim_confirmation()` `:618` | info | info | HHE Info |
| Upgrade promotion | `hhec_email_upgrade_promotion()` `:676` | accounts | accounts | HHE Accounts |
| Stripe upgrade success email | `hhec_send_upgrade_success_email()` `inc/stripe.php:629` | (uses default — currently info) | (uses default) | **HHE Info today, should be HHE Accounts** — see §8.1 |
| Stripe refund admin email | `hhec_send_admin_notice_refund()` `inc/stripe.php:950` | info (default) | info | **HHE Info today, should be HHE Accounts** — §8.1 |
| Stripe dispute admin email | `hhec_send_admin_notice_dispute()` `inc/stripe.php:985` | info (default) | info | **HHE Info today, should be HHE Accounts** — §8.1 |
| Stripe unmapped event admin email | `hhec_send_admin_notice_unmapped_event()` `inc/stripe.php:1017` | info (default) | info | HHE Info (operationally OK — it's a system alert) |
| Premium expiry warning | `inc/premium.php:479-519` | info (default) | info | **HHE Info today, should be HHE Accounts** — §8.1 |
| Listing claim admin notice | `inc/claims.php:54-162` (admin_email send) | info (default) | info | HHE Info (correct — admin operational) |
| Reader review submit notice | `inc/reviews.php` (audit didn't deep-read) | likely info (default) | info | HHE Info |
| Unsubscribe confirmation page | `hhec_handle_unsubscribe()` `inc/email-outreach.php:145` | — (no email sent; HTML page) | — | — |
| Newsletter | `inc/newsletter.php` (audit didn't deep-read) | likely info (default) | info | HHE Info (until newsletter volume justifies Broadcast stream) |

### 8.1 Billing-adjacent emails currently routed through `info@`

The Stripe layer at `inc/stripe.php:629-1035` builds emails using `wp_mail()` directly with the default From: (resolved by `inc/email-identity.php:164-180` to `info@`). They should send from `accounts@` so:
- Replies to receipts / dunning go to the billing inbox, not the operations inbox.
- The accounts@ Postmark Server captures the reputation history for transactional billing mail separately from outreach.

Fix is to wrap those `wp_mail()` calls in `hhec_email_send()` with `from_role=accounts`:

| Current call site | Change |
|---|---|
| `inc/stripe.php:629-695` (`hhec_send_upgrade_success_email`) | Replace `wp_mail( $to, $subject, $body, $headers )` with `hhec_email_send( [ 'to' => $to, 'subject' => $subject, 'body_text' => $body, 'from_role' => 'accounts', 'category' => 'billing_upgrade_success', 'listing_id' => $listing_id, 'respects_test' => false ] )` |
| `inc/stripe.php:950-983` (`hhec_send_admin_notice_refund`) | Same wrap, `from_role => 'accounts'`, `category => 'billing_refund_admin'`, `respects_test => false` |
| `inc/stripe.php:985-1015` (`hhec_send_admin_notice_dispute`) | Same wrap, `from_role => 'accounts'`, `category => 'billing_dispute_admin'`, `respects_test => false` |
| `inc/premium.php:479-519` (`hhec_run_premium_expiry_warnings`) | Same wrap, `from_role => 'accounts'`, `category => 'billing_expiry_warning'`, `respects_test => false` |

`respects_test => false` keeps the billing-critical sends real even when test mode is on (matches the existing comment at `inc/email-outreach.php:17-22`: "Transactional mail … is never redirected").

These changes also flow through the per-Server suppression list — a recipient who unsubscribed from outreach (HHE Info Server) still receives billing receipts (HHE Accounts Server). This is the intended behaviour and explicitly allowed by CAN-SPAM and similar regulations: transactional mail required for an active commercial relationship is not subject to opt-out.

### 8.2 Legal-routed mail

Nothing today sends from `legal@`. The `HHE Legal` Server is provisioned now and used in:
- Phase 3: GDPR-style data-export request confirmations.
- Phase 3: account-deletion confirmations.
- Phase 3: takedown / DMCA notice acknowledgements.

These flows don't exist as code today. The Server is created on day one so we don't scramble to provision it under a deadline. Sending zero mail from `HHE Legal` for months is fine — Postmark Servers are free up to the included monthly volume.

---

## 9. Security model

### 9.1 SMTP credentials at rest

Per-role tokens stored as plain autoloaded `wp_options` rows. Matches the existing Stripe secret-storage pattern (`inc/stripe.php:1126-1141`). Settings UI uses the "leave blank to keep" save semantics so the rendered form never echoes the secret back to the page.

Token rotation: revoke the old token in Postmark dashboard, generate a new one, paste into WordPress settings page, save. No code change. Old token is invalidated immediately upon revocation — there is no overlap window. If wanted, the operator can use Postmark's token API to create a new token before revoking the old, run a brief overlap, then revoke. Not automated.

### 9.2 Webhook authenticity

Bounce webhook auth = shared secret query param matched via `hash_equals` (constant-time). Mirrors the existing reply-webhook at `inc/deliverability.php:244-249`. Posting from anywhere with the correct secret is accepted — Postmark does not sign outbound webhooks.

This is weaker than Stripe's HMAC-signed webhooks but matches Postmark's actual webhook contract. The mitigation: the secret is long, never logged, never echoed in URLs that are reused (the webhook URL itself is a secret).

If the secret leaks, rotate by changing the option and updating each Postmark Server's webhook URL. Three edits in the Postmark dashboard. There is no rolling-window grace period — old secret stops working immediately.

A stronger Phase 4+ option: switch to Postmark's signed webhooks (HMAC-SHA256 over body, header `X-Postmark-Webhook-Token`). Requires verifying against the per-Server signing secret. Out of scope for the initial rollout.

### 9.3 DKIM key custody

Postmark holds the DKIM private key. We hold only the public-key CNAME pointer. If Postmark is compromised, an attacker can sign mail as `huahinexpats.co`. Mitigations:
- Rotate keys when Postmark's annual rotation cycle fires (or sooner via manual rotation).
- Keep `p=reject` in DMARC so any non-DKIM-signed or non-aligned mail is dropped — reduces blast radius if an attacker tries to spoof without compromising Postmark.

This is the same trust model as every managed-SMTP provider. Self-hosting DKIM would mean self-hosting SMTP, which is not what we want.

### 9.4 List-Unsubscribe headers

Already RFC 8058 compliant (`inc/email-outreach.php:366-367`). The token at `:118-125` is HMAC-keyed with `wp_salt('auth')`, which is host-specific and rotates only if `wp-config.php` is regenerated. No code change.

One-click unsubscribe requirements (Gmail 2024+, Yahoo 2024+) are met:
- `List-Unsubscribe-Post: List-Unsubscribe=One-Click` ✓
- The unsubscribe URL accepts POST in addition to GET — currently the handler at `inc/email-outreach.php:145` doesn't check the verb, accepting both. ✓
- The unsubscribe is irreversible per request (idempotent insert into `wp_hhe_email_suppression`). ✓

### 9.5 DMARC reporting mailboxes

`dmarc-aggregate@huahinexpats.co` and `dmarc-forensic@huahinexpats.co` receive DMARC reports. These should be:
- Real aliases at the mail host (Google Workspace / Fastmail).
- Routed to a folder that an operator reviews weekly.
- NOT in WordPress — DMARC reports are XML and high-volume; storing them in `wp_options` or `wp_hhe_email_log` is the wrong shape.

If the operator wants in-product reporting, Phase 4 adds a paid integration with dmarcian or valimail that parses reports and surfaces a digest. Not in scope for initial rollout.

### 9.6 What we don't claim to protect against

- Compromised legitimate sender accounts (info@, accounts@, legal@). DMARC doesn't protect against authorised users sending bad mail — it only protects against unauthorised senders forging your domain.
- Display-name spoofing. `info@evil.example.com` displays as "info" in many clients. DMARC does nothing about this — it's a recipient-side UI concern.
- Subdomain spoofing. `bills.huahinexpats.co` is not protected by the root DMARC unless we add `sp=reject;` to the DMARC record (which we should — see §11 Phase 2).

---

## 10. Inbound mail routing (mail host, not WordPress)

The mail-host configuration is operator-owned and does not involve code changes. Sketched here so the architecture is complete.

### 10.1 MX records

```
Type:  MX
Name:  @
Value: <mail host MX target>
TTL:   3600
Priority: <mail host MX priority>
```

If using Google Workspace: 5 MX records pointing to `smtp.google.com` (or the older `aspmx.l.google.com` cluster).
If using Fastmail: 2 MX records pointing to `in1-smtp.messagingengine.com` and `in2-smtp.messagingengine.com`.

Whichever mail host is chosen, the MX records must NOT point to Postmark — Postmark is outbound-only (its inbound API is for parsing replies and is configured per-Server, not via MX).

### 10.2 Mailbox configuration

At the mail host, set up the three role addresses:
- `info@huahinexpats.co` — primary operator mailbox or distribution group.
- `accounts@huahinexpats.co` — billing operator mailbox or distribution group.
- `legal@huahinexpats.co` — compliance operator mailbox or distribution group.

Each can be an individual mailbox or a distribution group / alias forwarding to multiple operator inboxes. The choice is operational, not architectural.

Also create:
- `dmarc-aggregate@huahinexpats.co` — operator-readable mailbox or alias.
- `dmarc-forensic@huahinexpats.co` — operator-readable mailbox or alias.
- `pm-bounces@huahinexpats.co` — **do NOT create a mailbox** for this. The CNAME points to Postmark's bounce infrastructure; receiving inbound mail at this address would conflict with Postmark's reverse-path handling. The CNAME-at-subdomain pattern means there is no MX for `pm-bounces.huahinexpats.co` (CNAME and MX cannot coexist on the same name per RFC 1034).

### 10.3 Reply routing to WordPress (Phase 2)

Optional. If we want recipient replies to outreach emails to flow into the `hhe/v1/email-reply` webhook (existing, `inc/deliverability.php:202-280`), the operator configures Postmark Inbound on each Server:

Postmark → HHE Info Server → Settings → Inbound → Inbound webhook URL: `https://huahinexpats.co/wp-json/hhe/v1/email-reply?secret=<shared-secret>`.

Then in the mail host, set up a forwarding rule: replies to `info@huahinexpats.co` forward to a Postmark-issued inbound address (something like `<unique-id>@inbound.postmarkapp.com`). Postmark parses the reply and POSTs to our webhook.

This is an architectural option, not a requirement. The simpler path is for operators to read replies in their mail-host inbox manually and tag listings as "responded" via wp-admin. The webhook is the automation upgrade.

---

## 11. Implementation phases

### Phase 1 — DNS records + Postmark account setup + WordPress per-role SMTP

**Goal**: outbound mail from huahinexpats.co authenticates correctly (SPF+DKIM+DMARC pass) via Postmark, with each role mailbox transported by its own Postmark Server.

**Deliverables**:
1. DNS records at the registrar:
   - SPF TXT at `@` — `v=spf1 include:spf.mtasv.net ~all` (or combined with `_spf.google.com` if mail host is Google Workspace).
   - DKIM CNAME at `<selector>._domainkey` — value copied from Postmark dashboard.
   - Return-Path CNAME at `pm-bounces` — `pm.mtasv.net`.
   - DMARC TXT at `_dmarc` — `v=DMARC1; p=none; rua=mailto:dmarc-aggregate@huahinexpats.co; ruf=mailto:dmarc-forensic@huahinexpats.co; fo=1; aspf=r; adkim=r; pct=100`.
2. Postmark account: domain Sender Signature for `huahinexpats.co`, address signatures for each role, three Servers (HHE Info / HHE Accounts / HHE Legal), Return-Path enabled per Server, Allowed Sender Signatures restricted per Server, three Server API tokens captured.
3. Mail host: MX records, role mailboxes, dmarc-* mailboxes.
4. WordPress code:
   - `inc/deliverability.php:42-49` — add Postmark to `hhec_smtp_provider_presets()`.
   - `inc/deliverability.php:55-71` — replace `hhec_smtp_setup_phpmailer()` with the role-aware version (§7.2).
   - `inc/deliverability.php:148-156` — DKIM selector probe updated to read operator-supplied Postmark selector (§7.4).
   - Settings page in `inc/admin-outreach.php` (or wherever the Deliverability settings render — confirm via re-audit) — add Postmark preset + three per-role token fields + DKIM selector field (§7.5).
   - Backward compat: `hhec_smtp_settings()` keeps reading `hhe_smtp_username` / `hhe_smtp_password`; these are the fallback when no per-role token is configured. No data migration; the legacy single-account configuration continues to work.

**Acceptance**:
- The Deliverability admin page shows green for SPF, DKIM, DMARC.
- A test send from each role (via Postmark's "Send Test Email") arrives at gmail.com with full Authentication-Results pass.
- A test send from WordPress (trigger an outreach invitation via the Outreach admin page) arrives via the HHE Info Server (verifiable in Postmark's Activity tab — only HHE Info shows the message).
- A test send from `hhec_email_upgrade_promotion()` arrives via the HHE Accounts Server.
- Existing single-account SMTP configuration (legacy `hhe_smtp_username` + `_password`) remains functional if per-role tokens are blank — verified via temporary rollback.

**Out of scope for Phase 1**: bounce webhook, reply webhook (Postmark-specific shape), DMARC progression to quarantine, billing-email re-routing to accounts@ (§8.1 fixes), legal-mail flows.

### Phase 2 — Bounce webhook + billing-mail re-routing + DMARC progression

**Goal**: hard bounces from any Server auto-suppress. Billing-adjacent mail correctly attributes to the Accounts Server.

**Deliverables**:
1. Bounce webhook (§7.6) at `hhe/v1/email-bounce`. Three URLs configured in Postmark, one per Server, each with `?server=<role>` for diagnostic clarity.
2. Re-route billing mail through `hhec_email_send()` with `from_role=accounts` (§8.1) — four function edits in `inc/stripe.php` and `inc/premium.php`.
3. Reply webhook parser extension (§7.7) — Postmark inbound payload fields added to the existing accept list.
4. DMARC progression: monitor aggregate reports for 14 days at `p=none`. If clean → move to `p=quarantine; pct=10`. After 14 more days at clean → `pct=50`. After 14 more days → `pct=100`. After 14 more days → `p=reject`. Operator-managed; the DMARC status panel in §7.5 shows current phase.
5. Suppression sync (optional): a daily cron that pulls Postmark's per-Server suppression list via API and mirrors into `wp_hhe_email_suppression`. Reverse direction NOT done — Postmark's suppression is authoritative.

**Acceptance**:
- A test send to a known bouncing address (e.g. `bounce@simulator.postmarkapp.com`) results in a `wp_hhe_email_suppression` row with reason='bounce' source='postmark_info' within 60 seconds.
- A test upgrade-success email from `hhec_send_upgrade_success_email()` shows in HHE Accounts Server Activity, not HHE Info.
- DMARC record at registrar shows `p=quarantine; pct=10` (or whatever phase the operator advanced to).
- The DMARC status panel in WordPress reflects the live record.

### Phase 3 — Legal mailbox flows

**Goal**: legal/compliance mail (data export, account deletion, takedown ack) flows from `legal@` via the HHE Legal Server.

**Deliverables**:
1. Data-export request confirmation email — new function `hhec_email_legal_dataexport_confirmation()` in `inc/email-outreach.php` (or a new `inc/email-legal.php`). Sends via `hhec_email_send()` with `from_role=legal`, `category=legal_dataexport`, `respects_test=false`.
2. Account-deletion confirmation email — same shape.
3. Takedown / DMCA acknowledgement email — same shape.
4. Wire to existing privacy / data-export endpoints in WordPress core (`wp_create_user_request`, etc.) if those are used by the site.

**Acceptance**:
- A test data-export request triggers a confirmation email visible in HHE Legal Server Activity.
- Bounce webhook from HHE Legal correctly suppresses with source='postmark_legal'.

### Phase 4 — TLS-RPT, MTA-STS, and Postmark signed webhooks (hardening)

1. MTA-STS policy at `mta-sts.huahinexpats.co` + `_mta-sts.huahinexpats.co` TXT.
2. TLS-RPT TXT at `_smtp._tls.huahinexpats.co`.
3. Switch bounce / reply webhooks to Postmark's signed-webhook model (verify `X-Postmark-Webhook-Token` HMAC) — replaces the shared-secret query param. More secure; requires per-Server signing secret in WP options.
4. `sp=reject` added to DMARC record (subdomain policy).
5. DMARC report ingestion via a third-party parser (dmarcian / valimail) — operator-procured; integration is just configuring the report mailbox to forward to the parser.

### Phase 5 — BIMI (optional)

Requires DMARC at `p=quarantine` or higher (achieved at end of Phase 2). Operator decides if branded inbox display is worth the SVG + VMC certificate cost. Pure presentation; no security improvement.

### Phase 6 — Newsletter Broadcast stream (if/when volume justifies)

Move `inc/newsletter.php` sends to a dedicated Broadcast stream on the HHE Info Server (or a fourth Server "HHE Marketing"). Trigger: newsletter volume exceeds ~1000/month or recipient unsubscribe rate diverges from transactional baseline. Not architecturally needed for initial rollout.

---

## 12. Master file index

### Files modified

| Path | Lines | Change |
|---|---|---|
| `inc/deliverability.php` | 42-49 | Add `postmark` preset to `hhec_smtp_provider_presets()` |
| `inc/deliverability.php` | 55-71 | Replace `hhec_smtp_setup_phpmailer()` with role-aware version (§7.2). Calls new helpers `hhec_smtp_role_from_address()`, `hhec_smtp_credentials_for_role()` |
| `inc/deliverability.php` | (new) | Add helpers `hhec_smtp_role_from_address()` and `hhec_smtp_credentials_for_role()` after `hhec_smtp_settings()` (§7.2) |
| `inc/deliverability.php` | 148-156 | Note in `hhec_dns_check_dkim()` that Postmark uses operator-supplied selector via `hhe_smtp_dkim_selector` option — no new selectors auto-probed (§7.4) |
| `inc/deliverability.php` | 195-207 | Add `hhe/v1/email-bounce` REST route registration alongside existing `email-click` and `email-reply` (§7.6) |
| `inc/deliverability.php` | (new) | Add `hhec_handle_email_bounce()` handler (§7.6) |
| `inc/deliverability.php` | 251-256 | Extend the from/text fallback chain in `hhec_handle_email_reply()` with Postmark's `From` / `TextBody` keys (§7.7) |
| `inc/stripe.php` | 629-695 | Wrap `wp_mail()` call in `hhec_email_send()` with `from_role=accounts` (§8.1) |
| `inc/stripe.php` | 950-983 | Same — refund admin notice (§8.1) |
| `inc/stripe.php` | 985-1015 | Same — dispute admin notice (§8.1) |
| `inc/premium.php` | 479-519 | Same — expiry warning (§8.1) |
| `inc/admin-outreach.php` | (settings panel rendering — line range to confirm via re-audit) | Add UI for Postmark preset, three per-role token fields, DKIM selector field, bounce webhook URL display, DMARC phase panel (§7.5) |

### New options

| Option key | Phase | Type |
|---|---|---|
| `hhe_smtp_info_token` | 1 | string |
| `hhe_smtp_accounts_token` | 1 | string |
| `hhe_smtp_legal_token` | 1 | string |
| `hhe_smtp_message_stream` | 1 | string (default `outbound`) |
| `hhe_smtp_bounce_webhook_secret` | 2 | string |

### New REST routes

| Namespace | Route | Methods | Phase |
|---|---|---|---|
| `hhe/v1` | `/email-bounce` | POST | 2 |

### New functions

| Function | File | Phase |
|---|---|---|
| `hhec_smtp_role_from_address( string $from_addr ): string` | `inc/deliverability.php` | 1 |
| `hhec_smtp_credentials_for_role( string $role ): array` | `inc/deliverability.php` | 1 |
| `hhec_handle_email_bounce( WP_REST_Request $req ): WP_REST_Response` | `inc/deliverability.php` | 2 |
| `hhec_email_legal_dataexport_confirmation()` (and siblings) | `inc/email-outreach.php` or new `inc/email-legal.php` | 3 |

### New constants — none

The existing `HHE_EMAIL_*` constants in `inc/email-identity.php:24-27` cover the role addresses. No new constants needed.

---

## 13. Open questions to confirm before Phase 1 starts

1. **Mail host**: which provider hosts inbound for `huahinexpats.co` (Google Workspace, Fastmail, other)? Determines the MX records and the SPF `include:` chain. The doc assumes "to be confirmed by the operator" — actual records depend on the answer.
2. **Existing inbound flows**: are there already mailboxes for info@/accounts@/legal@? If yes, no MX action needed beyond verifying. If no, this is operator setup at the mail host before Postmark Sender Signature verification can complete (because Postmark sends a confirmation email to each verified address).
3. **DMARC reporting mailboxes**: do we set up `dmarc-aggregate@` / `dmarc-forensic@` as full mailboxes or as forwarding aliases? Either works; aliases are cheaper.
4. **Suppression sync direction**: confirm we want Postmark → WordPress one-way sync (Postmark is the authoritative bounce/complaint source) and not bidirectional. Bidirectional risks accidentally un-suppressing on either side.
5. **Test mode default**: the existing `hhec_email_test_mode` option defaults to `'1'` (on) per `inc/email-outreach.php:448`. Before Phase 1 cutover, confirm whether to flip this to off when per-role tokens are configured, or keep the operator-managed status quo. Recommend keep operator-managed — gives a kill switch during incident response.
6. **Token rotation cadence**: do we set a calendar reminder for, say, 90-day rotation? Or rotate only on incident? Recommend "rotate only on incident" plus an annual baseline rotation — same posture as the existing Stripe keys.

These belong in `docs/ops/email-cutover-checklist.md` once Phase 1 begins.

---

## 14. Not in scope

- WordPress newsletter migration to a dedicated Broadcast stream. Newsletter sends inherit the HHE Info Server until Phase 6 (or never).
- Email content rewrites. The audit shows the outreach templates at `inc/email-outreach.php:497-700` already include personalisation, footer + unsubscribe link, click tracking. No content changes are required for deliverability.
- DMARC report parsing inside WordPress. Aggregate XML is high-volume and best handled by a dedicated parser service.
- Per-mailbox SMTP credentials at the mail host (info@'s outbound Google Workspace SMTP if humans send manually). Mail-host outbound is irrelevant to this design — only WordPress-originated mail goes through Postmark.
- Encrypted-at-rest secret storage. Same posture as Stripe keys — plain autoloaded options. Phase 6 hardening for both gateways and SMTP tokens together.
- BIMI VMC certificate procurement. Operator decision.
- Postmark Smart Send / Streams advanced routing. The simple 1-Server-1-Stream model is sufficient for the foreseeable volume.

---

## 15. Acceptance criteria for this design doc

- **DNS records are precise**: SPF and Return-Path values are deterministic and copy-pastable. DKIM is documented as operator-pasted-from-Postmark with the exact resolution path (Postmark dashboard → Sender Signatures → huahinexpats.co → DNS). DMARC three-phase progression is documented with exact record values.
- **Postmark setup is precise**: three Servers, role-to-Server map, Sender Signature lockdown, API token capture sequence.
- **WordPress code changes are file/line-precise**: every modification cites the audited file and line range. Backward compatibility with the existing single-account SMTP configuration is preserved during cutover.
- **Per-role separation works end-to-end**: a mail from `accounts@` provably traverses HHE Accounts Server (not Info), via the From-address inspection at `phpmailer_init`. The send wrapper is unchanged — only the SMTP layer became role-aware.
- **Billing-mail attribution is corrected**: §8.1 explicitly lists the four billing-adjacent `wp_mail()` calls that currently route through info@ and the wrap-in-`hhec_email_send()` change to route them through accounts@.
- **Security model is honest**: §9 acknowledges DKIM key custody is at Postmark, bounce webhook is shared-secret (not signed) in Phase 2, DMARC report mailboxes are out-of-WordPress.
- **No production changes occur yet**: this document is design only.

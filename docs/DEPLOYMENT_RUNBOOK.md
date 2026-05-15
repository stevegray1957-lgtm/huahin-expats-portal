# Deployment Runbook — HuaHin ExpatsPortal (Node/Express)

This document covers deploying the `huahin-expats-portal` Node app behind a public HTTPS URL.

**Scope:** This runbook is for `HuaHinExpatsPortal.com` (the Node/Express app in this repo). It is **not** applicable to `HuaHinExpats.Co` if that is a separate WordPress site — see `docs/STACK_RECONCILIATION_REPORT.md`.

**Pre-flight gate:** Do not deploy until the stack reconciliation question is settled (`STACK_RECONCILIATION_REPORT.md`).

---

## 1. One-time prerequisites

### 1.1 Postgres

You need a Postgres instance reachable from your hosting provider. Recommended managed options:

- **Neon** (https://neon.tech) — free tier covers a small site.
- **Supabase** (https://supabase.com) — Postgres + nice dashboard.
- **Railway** / **Render** — easy to provision.

Capture the connection string (`postgres://user:pass@host:5432/dbname?sslmode=require`).

### 1.2 Secrets

Generate two strong secrets:

```bash
openssl rand -hex 32   # → SESSION_SECRET / JWT_SECRET
openssl rand -hex 32   # → ADMIN_PASSWORD (or pick a passphrase you remember)
```

### 1.3 Airwallex (sandbox first)

- Sign up at https://www.airwallex.com/.
- Switch the dashboard to **Demo** mode.
- API → API keys: copy Client ID + API key.
- API → Webhooks: register `https://<deployed-host>/api/webhooks/airwallex`. Copy the signing secret.

### 1.4 Google Places (optional, for enrichment)

- Google Cloud project with billing enabled.
- Enable the **Places API (New)**.
- Create an API key, restrict it to **IP addresses** (your hosting egress) and to the **Places API (New)**.

---

## 2. Run migrations

The schema is defined in `database.js#initSchema()` and is idempotent (all `CREATE TABLE IF NOT EXISTS` / `ALTER ... ADD COLUMN IF NOT EXISTS`).

```bash
DATABASE_URL='postgres://...' npm run migrate
```

What this does:

- Creates: `listings`, `reviews`, `edit_suggestions`, `settings`, `audit_log`, `payments`, `payment_events`, `discovery_jobs`, `candidates`, `candidate_conflicts`, `import_logs`, `api_usage`.
- Adds columns to `listings`: `premium_level`, `premium_expires_at`, `verified`, `featured`, `admin_notes`, `owner_email`.
- Creates the supporting indexes.

Migrations re-run safely on every server start because `start()` in `server.js` calls `initSchema()` first.

To seed the directory (one-off):

```bash
DATABASE_URL='postgres://...' npm run seed
```

---

## 3. Start the server

### 3.1 Vercel (matches `vercel.json` in this repo)

```bash
npm i -g vercel
vercel login
vercel link              # bind this repo to the Vercel project
vercel env add DATABASE_URL production
vercel env add ADMIN_PASSWORD production
vercel env add SESSION_SECRET production
vercel env add BASE_URL production
# Optional Airwallex/Places bootstrap:
vercel env add AIRWALLEX_MODE production
vercel env add AIRWALLEX_CLIENT_ID production
vercel env add AIRWALLEX_API_KEY production
vercel env add AIRWALLEX_WEBHOOK_SECRET production
vercel env add GOOGLE_PLACES_API_KEY production
vercel --prod
```

The `vercel.json` already routes:
- Static assets → `public/`
- `/api/*` → `server.js`

Vercel handles TLS for the configured custom domain.

### 3.2 VPS with systemd + Nginx (alternative)

Filesystem layout:

```
/srv/huahin-expats-portal/
  ├── (the repo, checked out at the desired tag/branch)
  └── .env
```

Install Node 20+ and run:

```bash
cd /srv/huahin-expats-portal
npm ci --production
npm run migrate
```

Create `/etc/systemd/system/huahin.service`:

```ini
[Unit]
Description=HuaHin ExpatsPortal
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/srv/huahin-expats-portal
EnvironmentFile=/srv/huahin-expats-portal/.env
ExecStart=/usr/bin/node server.js
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=/srv/huahin-expats-portal

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now huahin
sudo systemctl status huahin
```

Logs:

```bash
sudo journalctl -u huahin -f
```

### 3.3 Reverse proxy (Nginx) for VPS

```nginx
server {
    server_name huahinexpatsportal.com;

    # Body-faithful proxy is required for /api/webhooks/airwallex (HMAC verifies raw body).
    # Nginx does not modify the body when proxy_pass'ing, so this is the default. Do not enable
    # mod_pagespeed, ngx_pagespeed, or any body-rewriting filter.
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_read_timeout 60s;
    }

    listen 443 ssl http2;
    ssl_certificate     /etc/letsencrypt/live/huahinexpatsportal.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/huahinexpatsportal.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
}

server {
    server_name huahinexpatsportal.com;
    listen 80;
    return 301 https://$host$request_uri;
}
```

TLS via Let's Encrypt: `sudo certbot --nginx -d huahinexpatsportal.com -d www.huahinexpatsportal.com`.

### 3.4 SSL assumptions

- Inbound HTTPS: terminated by Vercel or Nginx + certbot (renewed automatically).
- Outbound to Airwallex: uses Node's default CA bundle; no custom config.
- Outbound to Google Places: same.
- Postgres connection: `database.js` sets `ssl: { rejectUnauthorized: false }` when `NODE_ENV=production`. Managed Postgres providers terminate TLS with their own cert; this is fine for them. **If you self-host Postgres on the same VPS, prefer a Unix socket or pin the CA.**

---

## 4. Backup and restore

### 4.1 Backups

Daily logical backup of the Postgres database is sufficient for this app — there is no local state on the app server.

Managed providers (Neon, Supabase, Railway) all offer point-in-time recovery; enable it.

Manual cron-driven backup (VPS):

```bash
0 3 * * * pg_dump "$DATABASE_URL" -Fc -f /var/backups/huahin-$(date +\%F).dump
0 4 * * * find /var/backups -name 'huahin-*.dump' -mtime +14 -delete
```

### 4.2 Restore

```bash
# Standby DB, then test:
createdb huahin_restore
pg_restore -d "postgres://.../huahin_restore" -Fc -j 4 /var/backups/huahin-2026-05-15.dump

# Validate
psql "postgres://.../huahin_restore" -c "SELECT COUNT(*) FROM listings; SELECT COUNT(*) FROM payments;"

# Cutover (only after a successful smoke test on the restore):
# 1. Pause writes (drain checkouts: temporarily set settings.airwallex.enabled = false).
# 2. Repoint DATABASE_URL to the restored DB.
# 3. Restart server. `npm run migrate` is idempotent and will reconcile schema.
```

### 4.3 What is NOT backed up

- Vercel build artefacts (rebuilt on each deploy).
- Static assets in `public/` (live in git).
- Secrets (live in env / Vercel env management — back those up separately, e.g. in a password manager).

---

## 5. Rollback procedure

This app has two sources of state: code (git) and DB (Postgres). Each has its own rollback.

### 5.1 Code rollback

**Vercel:** in the Vercel dashboard, choose the previous successful deploy and click **Promote to Production**. Done.

**VPS:**

```bash
cd /srv/huahin-expats-portal
git fetch --tags
git checkout <previous-tag>
npm ci --production
sudo systemctl restart huahin
```

### 5.2 Schema rollback

This branch only **adds** tables and columns. None of the changes drop or rename existing structures, so a code rollback alone is safe — the older code simply ignores the extra columns/tables. There is no need to roll the schema back.

If a schema rollback is somehow required:

```sql
-- (Only run this after confirming no rows depend on the new tables.)
DROP TABLE IF EXISTS api_usage, import_logs, candidate_conflicts, candidates,
                     discovery_jobs, payment_events, payments, audit_log, settings CASCADE;
ALTER TABLE listings DROP COLUMN IF EXISTS premium_level;
ALTER TABLE listings DROP COLUMN IF EXISTS premium_expires_at;
ALTER TABLE listings DROP COLUMN IF EXISTS verified;
ALTER TABLE listings DROP COLUMN IF EXISTS featured;
ALTER TABLE listings DROP COLUMN IF EXISTS admin_notes;
ALTER TABLE listings DROP COLUMN IF EXISTS owner_email;
```

**Warning:** this loses all payment records, audit history, and candidate review work.

### 5.3 Disabling Airwallex without rolling back

If something is wrong with payments but the rest of the site is fine:

1. Open `/admin/airwallex-settings.html`.
2. Untick **Enabled**.
3. Save.

This causes `/api/payments/tiers` → 503 and `/api/payments/checkout` → 503, leaving the rest of the site untouched.

---

## 6. Production smoke test

Run the local harness against the deployed URL. Because the app stores secrets in the DB, the test below is structured to work without that DB writes path — it only exercises public endpoints.

```bash
# Replace HOST with your real deployed origin.
HOST=https://huahinexpatsportal.com

echo "--- 1. Static page ---"
curl -sSI $HOST/ | head -3

echo "--- 2. Public listings ---"
curl -sS $HOST/api/listings | jq 'length'                       # should be > 0

echo "--- 3. Tiers endpoint ---"
curl -sS $HOST/api/payments/tiers                               # 503 if disabled; JSON with tiers if enabled

echo "--- 4. Admin endpoint requires auth ---"
curl -sS -o /dev/null -w "%{http_code}\n" $HOST/api/admin/payments  # expect 401

echo "--- 5. Webhook rejects unsigned request ---"
curl -sS -o /dev/null -w "%{http_code}\n" -XPOST $HOST/api/webhooks/airwallex -H 'content-type: application/json' --data '{}'
# expect 401

echo "--- 6. Webhook URL endpoint reachable ---"
curl -sS -o /dev/null -w "%{http_code}\n" $HOST/admin/airwallex-settings.html   # expect 200
```

Expected output (all green):

```
HTTP/2 200
<number> > 0
{...tiers JSON...} or {"error":"Payments not enabled"}
401
401
200
```

For a deeper, DB-mutating test you can run `npm test` locally against a staging DB:

```bash
TEST_DATABASE_URL='postgres://...staging' npm test
```

The local harness expects to TRUNCATE its tables, so **point it at a staging DB only, never production**.

---

## 7. First-time configuration after deploy

Once the server is up:

1. Sign in at `https://<host>/admin.html` using `ADMIN_PASSWORD`.
2. Open **Airwallex Settings** (`/admin/airwallex-settings.html`):
   - Set Mode = `sandbox`.
   - Paste Client ID, API key, Webhook secret.
   - Paste Success URL / Cancel URL.
   - **Test connection** (expect green tick).
   - Toggle **Enabled**, save.
3. Copy the displayed webhook URL into Airwallex's webhook settings.
4. Run a sandbox payment through `https://<host>/upgrade.html?listing_id=<known-listing>`.
5. Confirm:
   - `/admin/payments.html` shows the payment.
   - Status transitions `pending` → `succeeded` after webhook delivery.
   - `SELECT premium_level, premium_expires_at FROM listings WHERE id='<known-listing>';` shows the upgrade applied.
6. When everything checks out: flip Airwallex mode to **live** with live credentials. Re-register the webhook in the live Airwallex dashboard. Repeat the smoke test with a small real-money transaction.
7. For enrichment: open `/admin/enrichment.html` → Source Settings, paste the Google Places API key, save, create a low-volume Discovery Job to validate the path.

---

## 8. Health checks and monitoring

- Vercel/Render dashboards already show request volume and error rates. Use them.
- The app does not yet expose a `/healthz`. For a VPS deployment, add an Nginx healthcheck on `/api/listings` (which is cheap and DB-touching).
- For payments, set up an external alarm on the count of `payments WHERE status='succeeded' AND paid_at > now() - interval '1 hour'` — a sudden drop to zero means something broke.

---

## 9. Do-not-deploy checklist (must all be true)

- [ ] `STACK_RECONCILIATION_REPORT.md` has been read.
- [ ] Steve has confirmed this code is intended for the deployment target.
- [ ] `npm test` is green on the deployment branch.
- [ ] Real `SESSION_SECRET` / `JWT_SECRET` is set in env (not the default).
- [ ] `ADMIN_PASSWORD` is rotated and recorded in a password manager.
- [ ] `DATABASE_URL` points to the right database (prod vs staging confirmed).
- [ ] If using Airwallex: webhook URL registered in the Airwallex dashboard, signing secret matches the value saved in DB.
- [ ] If using enrichment: Google Places key is IP-restricted to the deployed host.
- [ ] Backup schedule for the database is active.

# Stack Reconciliation Report

**Branch:** `claude/integrate-airwallex-payments-pcMOu`
**Repository:** `stevegray1957-lgtm/huahin-expats-portal`
**Author of report:** Claude Code (after audit on this branch)

## TL;DR

> **This repo (`huahin-expats-portal`, deployed as `HuaHinExpatsPortal.com`) is NOT the same site as `HuaHinExpats.Co`.**
>
> They have different domains (`.com` vs `.co`), different stacks (Node/Express vs the WordPress site described in the brief), and almost certainly different codebases. The Airwallex + enrichment work on this branch can ship to **HuaHinExpatsPortal.com** but **must NOT be merged or pushed to HuaHinExpats.Co** — it physically cannot run there (no PHP, no WP hooks, no `huahinexpats-core` plugin, no `_hhe_*` meta).
>
> **Do not merge to `main` until Steve confirms which property this work is for and which codebase actually powers `HuaHinExpats.Co`.**

## How this conclusion was reached

I do not have outbound network access in this remote-execution environment (`x-deny-reason: host_not_allowed` from the egress proxy for both domains). I could not fetch either site directly. The conclusion below is built from in-repo evidence.

### Evidence collected from this repo

| Evidence | Where | What it says |
|---|---|---|
| Repository name | `git remote -v` → `stevegray1957-lgtm/huahin-expats-portal` | The repo is named for the **Portal** (suffix `-portal`), not for `huahinexpats.co`. |
| Branding in HTML | `public/index.html` line 6 | `<title>HuaHin ExpatsPortal — Your Complete Guide ...</title>` |
| Canonical URLs | every `public/*.html` | All point at `https://HuaHinExpatsPortal.com/...` (zero references to `huahinexpats.co`). |
| OG / JSON-LD `url` | `public/index.html` lines 12, 22 | `"url": "https://HuaHinExpatsPortal.com"` |
| Logo file | `public/images/logo-huahin-expatsportal.png` | The logo asset is named `-expatsportal`, not `-co`. |
| `.env.example` | line 14 (pre-existing) | `SITE_URL=https://HuaHinExpatsPortal.com`. Predates this branch. |
| Hosting hint | `vercel.json` | Built for Vercel via `@vercel/node` (Node serverless), not Apache/Nginx + PHP-FPM. |
| Stack | `package.json` | `express`, `pg`, `jsonwebtoken`, `express-rate-limit`. No PHP, no Composer, no WordPress dependencies. |
| Filesystem search | `grep -ri "wp-(content\|includes\|json\|admin)\|wordpress\|huahinexpats-(core\|co)" --include="*.{html,js,json,md}"` | Zero hits in repo source. (Only my own Airwallex report mentions WordPress when describing the mismatch.) |
| Git history | `git log --all --oneline` | 3 commits total before this branch. The site was clearly built fresh as a Node project. |

### What the brief asserts

| Brief claim | Repo reality |
|---|---|
| Live site is `HuaHinExpats.Co` | Repo targets `HuaHinExpatsPortal.com` (different TLD, different name). |
| WordPress with custom theme `huahinexpats-co` | No PHP files anywhere. |
| Custom plugin `huahinexpats-core` | No such plugin in this repo (and nothing would load it — it's not a WP site). |
| `_hhe_*` post meta | Not used. Listings are PostgreSQL rows (`listings.data` JSONB). |
| Existing Stripe modules | None. No Stripe code, ever. |
| WP Admin workflows | The admin in this repo is `/admin.html` (custom JWT-gated SPA), not `/wp-admin/`. |

## Answers to the brief's questions

### Is this repo the live production codebase?

For `HuaHinExpats.Co`: **Almost certainly no.** Nothing about this code matches the WordPress description.

For `HuaHinExpatsPortal.com`: **Probably yes**, based on branding, env vars, and `vercel.json`. Steve must confirm; I cannot reach the live site to verify the deployed bundle.

### If yes, how does it map to the current live website?

If this repo is the live `HuaHinExpatsPortal.com` codebase, it maps 1:1:

- All static pages under `public/` are served by Vercel's static handler.
- All `/api/*` requests route to `server.js` via the function in `vercel.json`.
- Database: external Postgres referenced by `DATABASE_URL` (Vercel does not host Postgres directly; presumably Neon/Supabase/Railway).
- Admin URL: `https://HuaHinExpatsPortal.com/admin.html` (custom; gated by `ADMIN_PASSWORD` → JWT).
- Live server process manager: **none** (Vercel manages it). If actually deployed to a VPS instead, you'd typically use `pm2`, `systemd`, or Docker; none of those configs are present in the repo.

### If no, what repo/codebase powers the live WordPress site?

**Unknown to me.** It is a separate repository or hosted-only deployment. Steve must identify it. Likely candidates:

- A managed WordPress host (Kinsta, WP Engine, SiteGround) where the theme + plugin live only on the host's filesystem, with no separate git repository.
- Another GitHub repo not connected to this one (e.g. `huahinexpats-co` or `huahinexpats-wp`).

Concrete things Steve can do to identify it:

```bash
# From any machine on the public internet:
curl -sI https://huahinexpats.co/ | grep -iE 'server|x-powered'
curl -s https://huahinexpats.co/ | grep -iE 'wp-content|wp-includes|generator'
curl -s https://huahinexpats.co/wp-json/ -o /dev/null -w '%{http_code}\n'
```

If the last one returns `200`, the live site is WordPress and the WP REST API is open. Then check the active theme/plugin slugs:

```bash
curl -s https://huahinexpats.co/wp-content/themes/huahinexpats-co/style.css | head -10
```

### Is the Node/Express build intended to replace WordPress or sit beside it?

I don't know — this is a product decision. Possible interpretations:

1. **Replacement:** The Portal is the long-term home; `huahinexpats.co` will redirect to `huahinexpatsportal.com` once the Portal is feature-complete.
2. **Sister property:** Two different markets — one consumer-facing on WP, one operator/directory on Node.
3. **API backend:** The Node app exposes JSON to the WordPress site, which embeds it via JS.

The brief reads like option (1) was assumed silently. The repo gives no evidence either way.

### What would break if this branch were deployed today?

- **If deployed to `HuaHinExpatsPortal.com` (the Node app):** Nothing breaks at the code level if the env is correct. Migrations are idempotent (`CREATE TABLE IF NOT EXISTS`, `ALTER ... ADD COLUMN IF NOT EXISTS`). The 42/42 smoke test passes locally. See risks in §"Deployment risks" below.
- **If pushed to `HuaHinExpats.Co` (the WordPress site):** Total breakage. The repo isn't a WordPress plugin; pushing it to a WP filesystem would dump 28 unrelated files into the site root with no PHP entry point, no `wp-config.php`, no theme/plugin headers. Nothing in this branch is consumable by WordPress.

### What migrations are required?

The schema changes are all in `database.js#initSchema()`, idempotent. Run once:

```bash
npm run migrate
```

This will:
- Create `settings`, `audit_log`, `payments`, `payment_events`, `discovery_jobs`, `candidates`, `candidate_conflicts`, `import_logs`, `api_usage` tables.
- Add `premium_level`, `premium_expires_at`, `verified`, `featured`, `admin_notes`, `owner_email` columns to `listings`.
- Add new indexes.

No data is lost; no existing rows are touched. Re-running is safe.

### What environment variables are required?

See `.env.example` in this commit. Required:

- `DATABASE_URL`
- `ADMIN_PASSWORD`
- `SESSION_SECRET` (or `JWT_SECRET`)
- `BASE_URL` (defaults to `SITE_URL`)

Optional (DB-stored alternatives in admin UI):

- `AIRWALLEX_MODE`, `AIRWALLEX_CLIENT_ID`, `AIRWALLEX_API_KEY`, `AIRWALLEX_WEBHOOK_SECRET`
- `GOOGLE_PLACES_API_KEY`

The Airwallex/Places values bootstrap the `settings` table on first read; once an admin saves via the UI, the DB row wins.

### What secrets are required?

| Secret | Where it lives | Notes |
|---|---|---|
| Postgres password | `DATABASE_URL` (env) | Provider-managed (Neon/Supabase/etc). |
| `ADMIN_PASSWORD` | env | Manually rotated; gates admin JWTs. |
| `SESSION_SECRET` / `JWT_SECRET` | env | Long random string. |
| Airwallex API key & client_id | DB `settings` (or env bootstrap) | Server-side only; redacted in admin UI. |
| Airwallex webhook signing secret | DB `settings` (or env bootstrap) | Used to verify HMAC SHA-256 of incoming events. |
| Google Places API key | DB `settings` (or env bootstrap) | IP-restricted on Google Cloud side recommended. |

### What DNS/proxy changes are required?

For **Vercel** (the configured target):

- Domain `huahinexpatsportal.com` → Vercel project. Already presumed configured.
- Vercel handles TLS automatically via Let's Encrypt.
- The Airwallex webhook needs **public HTTPS reachability** at `https://<deployed-host>/api/webhooks/airwallex`. Vercel's default URLs are public by default.

For a **traditional VPS** (alternative deployment):

- Add an Nginx reverse-proxy in front of `node server.js` (see `docs/DEPLOYMENT_RUNBOOK.md`).
- TLS via Let's Encrypt / certbot.
- The webhook path needs to forward the **raw body** (Nginx's default proxying is byte-faithful — no body modification — so this works out of the box).

For **`HuaHinExpats.Co`** (WordPress): N/A. This branch is not the codebase for that site.

## Deployment risks specific to this branch

1. **Webhook raw-body integrity.** `server.js` mounts `/api/webhooks/airwallex` before `express.json()` and uses `express.raw({type:'*/*'})` to preserve bytes for HMAC verification. If a CDN or proxy mutates the body (Cloudflare's "Rocket Loader", any HTML rewrite, request body compression/transcoding), signature verification will fail silently. Vercel's serverless functions pass bodies verbatim — should be fine.
2. **`pg` `ssl: { rejectUnauthorized: false }` in production.** That setting is permissive — it accepts any cert. For a managed Postgres the public cert is fine, but for a self-hosted DB on the same VPS, prefer Unix socket or pinned CA. Worth tightening if the deploy target is a VPS.
3. **JWT_SECRET defaults to `'change-me'` if neither env var is set.** This is a production footgun inherited from before this branch. If `SESSION_SECRET` / `JWT_SECRET` are both missing in prod, anyone can forge an admin JWT. The deployment checklist should fail the deploy if neither is set.
4. **No webhook retry budget on Airwallex's side.** If our endpoint is briefly down, Airwallex retries per their docs. Today our endpoint always responds 200 to verified-and-recorded events even if we couldn't apply the upgrade — except on `applyUpgrade` exception which returns 500. Airwallex will retry; we are idempotent on (provider, event_id). Acceptable.
5. **First-boot UX:** If `settings.airwallex.enabled = false` and no env-var bootstrap is provided, the public `/api/payments/tiers` returns 503 and the upgrade flow is invisible — by design. Operator must enable it explicitly via `/admin/airwallex-settings.html` before any payment can be taken.

## Smoke test (run locally) — RESULT

```bash
sudo service postgresql start
sudo -u postgres psql -c "CREATE USER hhe WITH PASSWORD 'hhe' SUPERUSER;" || true
sudo -u postgres psql -c "CREATE DATABASE hhe OWNER hhe;" || true
npm test
```

Latest output on this branch:

```
42 passed, 0 failed
```

Covered (subset):

- Database migrations: `initSchema()` runs and rebuilds 11 tables.
- Server boot: GET `/` returns 200.
- Admin Airwallex settings page: `/admin/airwallex-settings.html` returns 200.
- Owner upgrade page: `/upgrade.html` returns 200.
- Webhook endpoint with raw body: HMAC verification (`PASS - Webhook signature verifies`, invalid → 401, replay → duplicate flag).
- Enrichment admin page: `/admin/enrichment.html` returns 200.
- Public listings endpoint: `/api/listings/:id` returns 200.
- `admin_notes` leakage: `Public listing API does not expose admin_notes column`.
- `owner_email` leakage: `Public listing API does not expose owner_email column`.

The full harness lives at `tests/integration.js` and is invoked by `npm test`.

## Recommendations

1. **Steve confirms which site this branch is for.** If for the Portal: proceed to deploy. If for `huahinexpats.co`: this branch is the wrong artefact and should not be merged. (`AskUserQuestion` will be used in the next session turn to settle this if not done already.)
2. **Do not merge `claude/integrate-airwallex-payments-pcMOu` to `main` until that confirmation lands.**
3. **Document the two-property architecture** (Portal vs `.co`) somewhere top-level — a `README.md` in this repo, and a short README in whatever repo backs the WP site.
4. **If WordPress integration is still desired for `huahinexpats.co`**, that is a *separate* engagement against a *separate* repo. The data model + audit/idempotency design from this branch are portable; the PHP/WP plumbing would have to be written from scratch.

## Open questions for Steve

- Which is the live customer-facing site today: `huahinexpats.co`, `huahinexpatsportal.com`, or both?
- Is the WP site (if it exists) currently taking payments via any provider? If so, which?
- Is the long-term plan to consolidate onto one domain, or run both?
- Where is the `huahinexpats-core` plugin source if it exists (separate repo, host filesystem only, or hypothetical)?
- Who else has admin access to either site, and do they need a heads-up before a deploy?

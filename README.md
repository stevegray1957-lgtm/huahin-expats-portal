# huahin-expats-portal

**This repo is the Node/Express + Postgres codebase for `https://HuaHinExpatsPortal.com` (the "Portal").**

**It is _not_ the codebase for `https://HuaHinExpats.Co`** (the WordPress site). The two are different properties on different domains, with different stacks.

Do not deploy this repo to `huahinexpats.co`. The WordPress source (theme `huahinexpats-co`, plugin `huahinexpats-core`) lives elsewhere — see `docs/HUAHINEXPATS_CO_SOURCE_LOCATION_REPORT.md`.

---

## What this app is

- Static expat-life content under `public/` (directory, blog, essentials, living, moving, community).
- Express JSON API at `/api/*` (`server.js`), backed by Postgres (`database.js`).
- Custom JWT-gated admin SPA at `/admin.html` and `/admin/*.html`.
- Optional Airwallex sandbox payments + Google Places candidate-enrichment pipeline (branch `claude/integrate-airwallex-payments-pcMOu`, not yet merged to `main`).

## Quick start

```bash
cp .env.example .env
# Set DATABASE_URL, ADMIN_PASSWORD, SESSION_SECRET, BASE_URL.

npm install
npm run migrate          # idempotent schema setup
npm run seed             # one-off — loads data/directory.json into the DB
npm start                # http://localhost:3000
```

## Test

```bash
# Requires a Postgres reachable via TEST_DATABASE_URL or DATABASE_URL.
npm test
```

42/42 integration tests pass on the current branch.

## Documentation

- `docs/STACK_RECONCILIATION_REPORT.md` — Why this repo ≠ `huahinexpats.co`, evidence, recommendations.
- `docs/HUAHINEXPATS_CO_LIVE_CODEBASE_MAP.md` — What we know about the `.co` site and the gap to close.
- `docs/HUAHINEXPATS_CO_SOURCE_LOCATION_REPORT.md` — Steps Steve must run on his Mac to locate the real WP source; server-admin request template if it isn't local.
- `docs/DEPLOYMENT_RUNBOOK.md` — How to deploy *this Portal app* (Vercel and VPS+systemd+Nginx variants). Read this before any push of the Portal.
- `docs/AIRWALLEX_INTEGRATION_REPORT.md` — Payments integration design and test results.
- `docs/TRUSTED_SOURCE_ENRICHMENT_PLAN.md` — Google Places candidate pipeline design.
- `docs/DATA_SOURCE_POLICY.md` — Allowed / forbidden data-source practices.
- `docs/GOOGLE_PLACES_OPERATOR_RUNBOOK.md` — Operator manual for enrichment.

## What goes where

| Domain | Stack | Repo | Status |
|---|---|---|---|
| `huahinexpatsportal.com` | Node/Express + Postgres | **this repo** (`stevegray1957-lgtm/huahin-expats-portal`) | Live (per Vercel config and branding). |
| `huahinexpats.co` | WordPress (custom theme + plugin) | **unknown** — see `docs/HUAHINEXPATS_CO_SOURCE_LOCATION_REPORT.md` | Live, but the source location has not yet been confirmed. |

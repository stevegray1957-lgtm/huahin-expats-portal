# Trusted Source Listing Enrichment Plan

**Branch:** `claude/integrate-airwallex-payments-pcMOu`
**Target path in brief:** `~/ai-lab/ventures/huahinexpats/TRUSTED_SOURCE_ENRICHMENT_PLAN.md`
**Written here instead:** `docs/TRUSTED_SOURCE_ENRICHMENT_PLAN.md`.

## Goal

Discover real Hua Hin businesses (restaurants, laundry, massage, pets, handyman, car rental, medical/dental, visa/legal) via compliant APIs and verified public sources, route everything through operator moderation, and **never auto-publish**.

## Data model

| Table | Purpose |
|---|---|
| `discovery_jobs` | Each operator-initiated discovery run (search query, geo, category, status, counts, error log). |
| `candidates` | One row per discovered business. Stores provenance: `source`, `source_place_id`, `source_url`, `source_capture_date`, `source_confidence`, `dq_score`, `duplicate_match_listing_id`, `conflict_status`, `approval_status`, social-URL provenance fields. |
| `candidate_conflicts` | Field-level disagreements between a candidate and an existing listing. Resolution per row (keep/accept/alternate/ignore/flag). |
| `import_logs` | Per-job/per-candidate info/warn/error log. |
| `api_usage` | One row per outbound API call for cost monitoring & daily-cap enforcement. |

The full DDL is in `database.js` and runs idempotently on each app start.

## Pipeline

```
[operator creates job] → discovery_jobs(queued)
       │
       ▼  POST /discovery-jobs/:id/run
[runner] ── Google Places textSearch (field-masked) ── normalisePlace()
       │
       ├── duplicate check vs listings (place_id → name → phone → website domain → coordinates+name token)
       │       └── if dup ⇒ candidate.duplicate_match_listing_id = listing.id, conflicts recorded
       │
       ├── candidate upserted (UNIQUE(source, source_place_id))
       │
       ├── if website_url:
       │       ├── robots.txt allowed?
       │       ├── GET homepage (timeout, byte-cap, custom UA)
       │       ├── parse JSON-LD, extract email/social
       │       ├── follow ONE same-domain /contact or /about page (also robots-guarded)
       │       └── store social URLs with provenance = "verified-from-website"
       │
       └── DQ score computed
                       │
                       ▼
       /admin/enrichment.html — Candidate Review tab
                       │
       (operator approves | rejects | needs-verification | resolves conflicts | edits social)
                       │
                       ▼      POST /candidates/:id/promote
       listings table gets new row (status pending, _hhe_admin_notes-style admin_notes populated)
                       │
                       ▼ Editorial review
                  visible publicly
```

## What the runner does NOT do

- It does **not** scrape Google Maps pages.
- It does **not** scrape Facebook, Instagram, TikTok pages.
- It does **not** invent or guess social URLs.
- It does **not** auto-publish — `approval_status` defaults to `pending`; only `POST /candidates/:id/promote` after explicit approval creates a listing.
- It does **not** overwrite trusted listing fields when conflicts exist — conflict rows are created and the operator chooses.
- It does **not** ignore robots.txt — `parseRobotsAllowed` honors `User-agent` / `Disallow`.
- It does **not** download photos. Only the `photoReference` name is stored; serving photos would need an additional, billed Places call which is left to a later iteration.

## Approval states

| State | Meaning |
|---|---|
| `pending` | Default after import. Awaits operator decision. |
| `approved` | Operator has confirmed data. Eligible for promotion if no duplicate. |
| `rejected` | Will not be promoted. |
| `needs_verification` | Sent back to operator/owner queue. |
| `merged` | Has been promoted to a listing. |

## Data quality scoring (`src/enrichment/dq.js`)

Score = fraction of these checks that pass (rounded to two decimals):

- `name_present`, `category_mapped`, `address_present`, `coordinates_present`
- `contact_route` (phone OR website)
- `web_or_social` (website OR any social)
- `source_verified` (has place_id from a trusted source)
- `duplicate_free`
- `strict_category_ok` — currently enforces `business_status` + `phone` for `medical-dental`; `phone` for `visa-legal`.

The score is shown in Candidate Review for operator triage.

## Promotion = a manual, audit-logged action

When the operator clicks **Promote** on an approved, non-duplicate candidate:

1. New `listings` row is created via `upsertListing` with the `cand-<id>` suffix and category from `mapped_directory_category`.
2. Public fields (address, phone, website, socials, coordinates, opening hours, place_id) go to `listings.data`.
3. **Source / debug / import info goes to `listings.admin_notes`** (a private column never returned by public `/api/listings/*` endpoints).
4. `public_note`, if explicitly written by the operator for readers, is the only thing copied to `listings.data.notes`.
5. Audit log `candidate.promoted` is written.

## Compliance

- Server-side API keys only. The Google Places key never leaves the server.
- Admin auth gate on every enrichment endpoint via `requireAdmin`.
- Operator action endpoints accept JSON bodies and never echo secrets back.
- `api_usage` rows give a per-call audit trail; the daily cap blocks further calls when reached.
- All policy details are in `DATA_SOURCE_POLICY.md`.
- The Google Places operator manual is in `GOOGLE_PLACES_OPERATOR_RUNBOOK.md`.

## Test coverage (covered by `test-integration.js`)

```
PASS - Duplicate detected by place_id
PASS - Duplicate detected by exact name
PASS - Duplicate detected by phone
PASS - Candidate created
PASS - DQ score populated
PASS - New place not flagged as duplicate of existing listing
PASS - Duplicate candidate matched to existing listing
PASS - Conflicts recorded for duplicate match
PASS - Cannot promote a candidate with a duplicate match
PASS - Promotion creates listing id
PASS - Promoted listing has admin_notes from source
PASS - Public listing API does not expose admin_notes column
```

## Acceptance criteria status

| Criterion | Status |
|---|---|
| Enrichment can discover candidates without publishing | YES — promotion is manual, audit-logged. |
| Google Places data lands in candidate review | YES — `candidates` table is the only entry path. |
| Website/social enrichment only stores verified links | YES — social fields carry `*_provenance` of `verified-from-website` or `operator-provided`. |
| No internal notes leak publicly | YES — public listing query never selects `admin_notes`. |
| No unverified social links invented | YES — only extracted from the official website, or operator-set. |
| No listing is auto-published | YES — `auto_publish` setting is forced `false`. |
| Operator can approve / reject / merge / verify | YES — all four endpoints + UI buttons. |
| Full audit/report files created | YES — this file + the other three docs. |

## Limitations / follow-ups

- The website fetcher follows one same-domain `contact|about` link; deeper crawling intentionally not built.
- TikTok regex may miss `vm.tiktok.com` shorts; expand once needed.
- Promotion currently produces a synthetic listing id like `restaurants-cand-42`. If you want human IDs, pre-fill `id` from the operator UI before promote.
- The discovery job runs in-process (`setImmediate`), which is fine for a single-node deploy. If the app moves to multiple workers or serverless, lift it to a queue (BullMQ + Redis) so a job survives a restart.

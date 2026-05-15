# Data Source Policy

Applies to all listing enrichment, candidate creation, and operator actions on HuaHinExpatsPortal.

## Allowed sources

1. **Official APIs with valid commercial use:**
   - Google Places API (Places API New). Used via authenticated server-side calls, with FieldMask to keep cost down.
   - Owner-submitted data via on-site forms (existing `edit_suggestions` table; future claim flow).
   - Operator-provided pastes (manual entry).
2. **Public business websites** of the businesses themselves, fetched politely (custom User-Agent, timeout, byte cap, robots.txt-aware). The contact/about page on the same domain may also be fetched once per candidate.
3. **CSV uploads from operators or business owners** (not implemented in this iteration, but the candidate model is shaped to accept it: just set `source = 'operator_csv'`).

## Forbidden practices

The following are **never** performed by this codebase, and operators must not enable them:

- Scraping Google Maps web pages, search-result pages, or business listings directly.
- Scraping Facebook, Instagram, TikTok, X / Twitter, or any social platform's HTML or DOM.
- Browser-driven scraping (headless Chromium against any of the above).
- Using third-party scraping services that themselves break those platforms' ToS.
- Ignoring `robots.txt`.
- Mass-fetching at a rate higher than `1 req/sec` per source domain. Today the website fetcher is sequential per candidate; if that changes, add explicit pacing.
- Importing or republishing copyrighted reviews or photos from third-party platforms beyond what the source API explicitly licenses.
- Inventing social-media handles by pattern guessing (e.g. "the business is X, so try `facebook.com/x`").
- Persisting raw third-party API responses to public-facing fields. The raw payload is kept in `candidates.raw_payload` for audit; only the normalised, attributed fields are eligible to be promoted to public listings.

## Provenance is required

Every candidate field that ends up on a public listing must be traceable. The candidate row holds:

- `source` — the source name (`google_places`, `operator_csv`, `owner_submission`, …).
- `source_place_id`, `source_url`, `source_capture_date`, `source_confidence`.
- For social URLs: `facebook_url_provenance`, `instagram_url_provenance`, `tiktok_url_provenance` ∈ `{verified-from-website, operator-provided, official-api}` — and **only those values**. If a social URL appears without provenance, it is dropped.

## Conflict policy

A candidate field that differs from an existing listing's same field is **never** silently overwritten. A row is added to `candidate_conflicts` with `existing_value`, `new_value`, and `source`. The operator resolves it with one of:

- `keep_existing` — listing unchanged.
- `accept_new` — listing updated (the operator owns this decision; not the source).
- `store_as_alternate` — stash the new value as an alternative reference.
- `ignore_source` — note that this source is wrong for this field.
- `flag_owner_verification` — send back to the listing owner via the future claim flow.

## Secrets and API keys

- Google Places API key and Airwallex secrets live in the `settings` table.
- They are returned to admin UIs only via the redacted view (`getRedacted`).
- They are stripped from `audit_log.details` by `src/lib/audit.js#redact`.
- They are never sent to browsers, logged to stdout in plaintext, or committed to git.
- `.env` files holding bootstrap secrets (DATABASE_URL, ADMIN_PASSWORD, JWT_SECRET) are listed in `.gitignore`.

## Auto-publishing is prohibited

The `enrichment.auto_publish` setting is forced to `false` by the settings endpoint. Even with operator approval, promoted listings start in a `pending` state, awaiting Editorial Review before becoming visible on the site.

## Owner verification path

When a candidate or conflict is `flag_owner_verification`, the operator's intent is:

> We will reach out to the business to confirm this data before publishing.

Today there is no automated mailer; this is captured in `audit_log` and in the candidate's `notes_internal`. The future claim-flow will pick up flagged entries and send an outbound verification email.

## Quota governance

The `enrichment.daily_request_cap` and `enrichment.per_run_request_cap` settings cap the number of outbound API calls. Hitting the cap stops the runner gracefully (logged in `import_logs`).

## What is OK to do with the raw Google Places payload

- Persist it in `candidates.raw_payload` for audit and debugging (admin-only).
- Use field-masked extracted values to populate the structured candidate columns.
- Display the raw payload in admin UI to support operator decisions.

What is **not** OK:

- Republishing the raw payload, ratings, review text, or photos publicly without complying with Google's display attribution requirements. The current promotion code does not republish ratings or photos. If photo display is added later, it must use the Places photo endpoint and follow attribution rules.

# Trusted-Source Enrichment Architecture

Status: design — not yet implemented.
Scope: HuaHinExpats.Co WordPress (`wp-content/plugins/huahinexpats-core` + `wp-content/themes/huahinexpats-co`).
Audit basis: full code read of the production plugin and theme at the audit-package state. Every file/line reference below points into that audited tree.

---

## 1. What "trusted-source enrichment" means here

For HuaHinExpats.Co a trusted source is a third-party data provider whose answer about a business — name, address, phone, website, social URLs, opening hours, coordinates — is more authoritative than the importer-supplied or operator-typed value, with an audit trail explaining where each field came from. Today the codebase has none of this. The audit confirms:

- The "Enrichment Queue" (`inc/enrichment-queue.php`) is a virtual filter over `listing` posts, not a real queue. There is no producer, no consumer, no job table. The visible "bulk geocode" action depends on a function `hhec_geocode_listing()` that **is not defined anywhere in the package** (audit §18).
- The geocoder (`inc/geocoder.php`) hits Google Geocoding API only — never Places Details, never Places Search. `place_id` is returned by Google and discarded (audit §5, line 343-345 of geocoder.php).
- Provenance is per-record (`_hhe_source`, `_hhe_source_url`, `_hhe_promoted_from_candidate_id`, `_hhe_lead_source`) — never per-field. There is no `_hhe_phone_source` or "this address came from Google Places at 2026-04-12".
- Duplicate detection exists in three implementations with different semantics: XLSX importer (title + category, exact), CSV importer (4-channel first-hit-wins), candidate scorer (6-signal weighted). The candidate scorer (`hhec_candidate_find_duplicate_listings()`, `inc/candidates.php:433`) is the most mature primitive.
- Quotas: `geocode_daily_limit` setting exists in UI but **no code reads it** (audit §13). The per-run cap (`HHEC_GEOCODE_BATCH_MAX=25`) is the only practical brake on Google billing.
- The single existing extension point is the filter `apply_filters('hhec_geocode_address', null, $query, $post_id)` at `inc/geocoder.php:147` — clean enough to support pluggable providers.

This document specifies how to add trusted-source enrichment without rebuilding any of the existing systems.

---

## 2. Decision summary

**Recommendation: build a real `wp_hhe_enrichment_queue` table feeding a worker cron that calls trusted-source providers through a dispatcher filter, with per-field provenance stored as a JSON post-meta key. Reuse every existing dedupe / moderation / editorial / trust primitive. Make the existing "Enrichment Queue" page a viewer of the new real queue rather than a virtual filter.**

Six pillars:

1. **Queue**: real DB table for jobs (Phase 5).
2. **Providers**: dispatch via a filter mirroring the existing geocoder pattern.
3. **Provenance**: JSON post-meta key `_hhe_field_provenance` with per-field records.
4. **Conflict policy**: operator-edited fields are sticky; trusted-source overwrites only on a freshness or confidence basis.
5. **Quotas**: implement the `geocode_daily_limit` that the UI already promises; add per-provider daily counters.
6. **Moderation gate**: every trusted-source write that affects publishable fields routes through the existing editorial-review surface.

Detailed rationale grounded in audit findings sits inline.

---

## 3. What we keep, what we extend, what is new

### Keep unchanged (working primitives)

| Concern | File:line | Why we reuse |
|---|---|---|
| `listing` CPT and three taxonomies | `inc/cpts.php:17`, `inc/taxonomies.php:34,60,79` | Already the only object that matters |
| `wp_hhe_candidates` table | `inc/candidates.php:36-98` | Staging table for new listings — fine for what it does |
| `hhec_candidate_find_duplicate_listings()` (multi-signal scored) | `inc/candidates.php:433` | Most mature dedupe primitive; reuse for trusted-source match-before-create |
| Address policy publish gate | `inc/address-policy.php:219` (`wp_insert_post_data` filter, prio 99) | Already blocks strict-category publish without verified address |
| Editorial review states + audit log | `inc/editorial-review.php` | `_hhe_editorial_approved` flag, `_hhe_approval_audit` JSON ledger |
| Moderation queue surface | `inc/moderation.php` | Operator-facing approval/rejection |
| Trust scoring | `inc/trust.php:62` | Signals + 100-point cap |
| Data-quality scoring | `inc/data-quality.php:56` | Outreach completeness |
| `wp_hhe_geocode_log` audit table | `inc/geocode-log.php` | Per-call ledger with raw response retention |
| Filter `hhec_geocode_address` | `inc/geocoder.php:147` (binding), `inc/address-tools.php:239` (invocation) | Dispatcher pattern reused for new providers |
| Phone normaliser | `inc/phone-normaliser.php:124` | Pattern for single-source-of-truth normalisation |
| `_hhe_admin_notes` / `_hhe_notes` policy | `inc/editorial-notes.php:64-109` | Public vs internal note storage; `register_post_meta` with `auth_callback` lockdown |
| `register_post_meta` lockdown pattern | `inc/editorial-notes.php:64` | Apply to new provenance meta in §6 |
| `hhec_address_recompute_and_save()` post-write recompute | `inc/address-policy.php` | Triggered after geocode write |

### Extend (small additive change)

| Concern | File:line | Change shape |
|---|---|---|
| `hhec_geocode_via_google()` | `inc/geocoder.php:295` | Persist `place_id`, `address_components`, raw `viewport`. New meta keys |
| `hhec_geocode_log_write()` schema | `inc/geocode-log.php:37-94` | Already has `raw_response` LONGTEXT — no schema change needed; we just emit Places Details rows with a new `provider` value |
| `hhec_candidate_find_duplicate_listings()` | `inc/candidates.php:433` | New optional dedupe signal: `place_id` exact (weight 80, see §10) |
| `hhe_get_failed_approval_gates()` gate list | `inc/enrichment-queue.php:94` | New gate `field_provenance_complete` so editorial queue can surface "no trusted source has ever touched this listing" |
| Owner-edit handler | `inc/owner.php:288-465` (`hhec_handle_owner_update_listing`) | On save, mark each edited field's provenance with `source=operator`, `locked=true` so future trusted-source runs don't overwrite |
| Settings page | `inc/settings.php:279-333` (geocoding section) | Add Places-API tab plus trusted-source provider toggles + per-provider daily caps |

### New (additive)

| File | Purpose |
|---|---|
| `inc/enrichment.php` | Provider dispatcher, queue producers, worker cron, provenance helpers |
| `inc/places.php` | Google Places provider (Places Search + Places Details) |
| `inc/url-verifier.php` | Website / Facebook / Instagram / Line URL verification |
| `inc/quota.php` | Generic per-provider per-day counter helpers |

### Removed code

`hhec_geocode_listing()` references at `inc/enrichment-queue.php:578` are dead. Replace the call with the new queue producer (§5.5).

---

## 4. Provider model

A single filter is the extension point — mirroring the existing geocoder dispatch (`inc/geocoder.php:147`).

```
apply_filters( 'hhec_enrichment_run', $result, $job, $listing_id );
```

Where `$result` is the running answer (null when no provider has handled it), `$job` is the queue row (see §5), and `$listing_id` is the target. Providers register themselves on this filter at priority < 100 and return a structured payload OR `null` to pass.

Provider contract — every provider returns:

```
[
    'provider'     => 'google_places',          // slug — also used as quota counter key
    'status'       => 'success'|'failed'|'skipped'|'ambiguous',
    'fields'       => [
        '_hhe_phone'   => ['value' => '...', 'confidence' => 0.95, 'raw_field' => 'formatted_phone_number'],
        '_hhe_website' => ['value' => 'https://...', 'confidence' => 0.90, 'raw_field' => 'website'],
        // ...
    ],
    'place_id'     => 'ChIJ...',                // when applicable
    'raw_response' => <decoded JSON>,
    'error'        => 'human-readable string',  // when status != success
    'cost_units'   => 1,                        // for quota accounting
]
```

The dispatcher in `inc/enrichment.php` walks providers in priority order; the first provider returning `status=success` wins for that job. Lower-priority providers can still contribute fields the winner didn't cover — see §7.3 conflict resolution.

This is intentionally close to the geocoder pattern at `inc/geocoder.php:149-187` so a future contributor can read the geocoder and recognise the shape immediately.

### 4.1 Google Places provider (`inc/places.php`)

Two API endpoints we'll add:

- **Places Find Place from Text** — `https://maps.googleapis.com/maps/api/place/findplacefromtext/json` — used to resolve a business name + Hua Hin locality to a `place_id`.
- **Places Details** — `https://maps.googleapis.com/maps/api/place/details/json` — used to harvest the rich fields once `place_id` is known.

The existing Geocoding API call (`hhec_geocode_via_google()` at `inc/geocoder.php:295`) is independent — it stays in the geocoder. Places is a separate code path.

Flow:

```
hhec_places_enrich( int $listing_id, array $job ): array
    1. resolve place_id:
       a. if _hhe_place_id meta set → use it
       b. else: call Find Place with input = '<title> Hua Hin Thailand'
          (or _hhe_address if present). Extract place_id from candidates[0].
          If 0 results or 2+ ambiguous → status='ambiguous', persist nothing,
          log to wp_hhe_geocode_log with provider='places_findplace'
    2. quota check via hhec_quota_consume('google_places', 1)
       — if over cap, status='skipped', return early
    3. call Places Details with fields= name, formatted_address, geometry,
       formatted_phone_number, international_phone_number, website,
       opening_hours, business_status, address_components, url, photos, types
    4. log to wp_hhe_geocode_log with provider='places_details', raw_response = full JSON
    5. transform result.result.* into a fields array:
       formatted_phone_number → _hhe_phone (confidence 0.95)
       website                → _hhe_website (confidence 0.95)
       formatted_address      → _hhe_address (confidence 0.85)
       opening_hours.weekday_text → _hhe_hours (joined; confidence 0.90)
       url                    → _hhe_maps_url (confidence 1.00)
       geometry.location      → _hhe_lat, _hhe_lng (confidence 0.95)
       place_id               → _hhe_place_id (confidence 1.00)
       business_status='OPERATIONAL' → no flag; non-OPERATIONAL →
         field _hhe_places_business_status (so the moderation UI can flag CLOSED/PERMANENTLY_CLOSED)
       address_components     → _hhe_places_address_components (JSON, for downstream consumers)
    6. return the payload (caller writes via the provenance helper, §6)
```

Confidence numbers are starting defaults. Tunable via filter `hhec_places_field_confidence($confidence, $field, $raw)` for operators who want to demote, say, opening hours which Google often has stale.

Result writes do NOT happen inside the provider. The provider returns the payload; the dispatcher (`inc/enrichment.php`) applies the conflict policy (§7) before any `update_post_meta`.

API key: reuse the existing `geocode_google_api_key` setting (audit §17 — stored inside `hhe_settings` blob, resolved via `hhe_setting()`). Google's Places API requires the API key to have the Places API enabled in the GCP console; the operator handles that out-of-band. We add a "Test Places API" button on the settings page (mirrors the existing "test geocode" trigger at `inc/settings.php:594-640`).

### 4.2 URL verifier (`inc/url-verifier.php`)

Verifies websites and social URLs. Not a Google product — no API costs.

Flow per URL:

```
hhec_url_verify( string $url, string $expected_kind ): array
    expected_kind ∈ [website, facebook, instagram, line_oa, tiktok, x_twitter]

    1. parse URL via wp_parse_url; reject if scheme not in [http, https]
       or host missing
    2. host pattern check by kind:
       facebook   → host matches *.facebook.com or fb.com
       instagram  → host matches *.instagram.com
       line_oa    → host matches lin.ee or line.me/R/ti/p/
       tiktok     → host matches *.tiktok.com
       x_twitter  → host matches *.twitter.com or *.x.com
       website    → any (except known social hosts — those are misclassifications)
    3. wp_remote_head($url, ['timeout'=>10, 'redirection'=>3])
       on WP_Error → status='unreachable'
       on 4xx/5xx → status='broken' with code
       on 200 with redirect to a known social host (e.g. site redirects to its FB page) →
           status='redirected_to_<kind>'
    4. wp_remote_get($url, ['timeout'=>15]) for 200 responses to extract:
       <title>, <meta property="og:url">, <meta property="og:title">,
       <link rel="canonical">
    5. score:
       host matches expected_kind AND HTTP 200 AND canonical matches → confidence 0.95
       host matches AND HTTP 200 → 0.80
       host matches AND redirected → 0.50
       host mismatch → 0.10 (flag as misrouted)
       broken/unreachable → 0
    6. return [ 'status' => ..., 'confidence' => ..., 'canonical_url' => ...,
                'title' => ..., 'http_code' => ..., 'redirect_chain' => [...] ]
```

Provenance write on success: `_hhe_<field>_verified = '1'`, plus a per-field provenance entry (§6). On failure: no overwrite of `_hhe_<field>` (the URL stays as-is) but a verification record is logged so the editorial-review surface can show "this URL has been verified broken — operator action required."

A new admin column / moderation tab "URL-broken" surfaces these. Listings move out of the publishable set if their primary `_hhe_website` is broken; this is enforced by extending the gate engine at `inc/enrichment-queue.php:94` with a new gate `urls_verified_or_absent` (weight 5).

Robots.txt and rate-limit decency: verifier respects 1-second-per-host minimum delay (transient `hhec_urlverify_host_<md5>` 1-second TTL). For large bulk runs the queue worker (§5) drives the pacing.

URL canonicalisation lives in a shared `hhec_url_canonicalize($url)` helper (move the rtrim/lowercase logic that's currently inline at `inc/listings-importer.php:416`).

### 4.3 OpenStreetMap / Nominatim — no change

Existing Nominatim adapter at `inc/geocoder.php:197` continues unchanged. Used for free fallback when the operator doesn't enable Google. Not a "trusted source" for fields beyond lat/lng.

### 4.4 Other providers (future)

The filter pattern lets us drop in:
- **Facebook Graph (Pages)** — verify FB URL is a real Page, harvest hours and phone (FB API).
- **Line Official Account Manager** — verify Line OA exists (API auth flow heavy — likely deferred indefinitely).
- **Operator-curated CSV upload** — already exists via `inc/listings-importer.php`; future "enrichment-only CSV" uploader can register as a provider too.

None of these are in scope for Phase 3–5. They drop into the filter shape we've defined.

---

## 5. Queue model

### 5.1 New table `wp_hhe_enrichment_queue`

```
CREATE TABLE wp_hhe_enrichment_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id BIGINT UNSIGNED NOT NULL,
    job_type VARCHAR(40) NOT NULL,
        -- 'geocode' | 'places_enrich' | 'verify_urls' | 'recompute_provenance'
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
        -- 'queued' | 'running' | 'done' | 'failed' | 'skipped'
    priority TINYINT NOT NULL DEFAULT 5,        -- 1=urgent, 9=lowest
    attempts TINYINT NOT NULL DEFAULT 0,
    max_attempts TINYINT NOT NULL DEFAULT 3,
    next_run_at DATETIME NOT NULL,              -- enables backoff
    payload LONGTEXT NULL,                       -- JSON job-specific params
    result LONGTEXT NULL,                        -- JSON provider response summary
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    KEY status_next_idx (status, next_run_at),
    KEY listing_status_idx (listing_id, status),
    KEY job_type_status_idx (job_type, status)
)
```

dbDelta creation hooked on `admin_init`, version-gated via option `hhec_enrichment_queue_v1` — same pattern as `hhec_candidates_table_v3` at `inc/candidates.php:100`.

Created in the activation hook (`huahinexpats-core.php:100-119`): add `hhec_create_enrichment_queue_table` to the activation block, alongside the existing table creators.

### 5.2 Job types

| Job type | Trigger | Provider(s) | Cost |
|---|---|---|---|
| `geocode` | New listing without lat/lng; operator-initiated bulk; admin edit removes coords | Existing `hhec_geocode_address` filter chain | 1 Google call OR 1 Nominatim call |
| `places_enrich` | New listing in eligible category; missing core fields (phone OR website); operator-initiated; nightly refresh for "stale" listings (Phase 5) | `inc/places.php` | 2 Google Places calls (Find Place + Details) — note Places Details is metered per field-mask category |
| `verify_urls` | Listing with `_hhe_website`, `_hhe_facebook`, `_hhe_instagram` set; operator-initiated; nightly refresh | `inc/url-verifier.php` | Free; rate-limited per host |
| `recompute_provenance` | After bulk provenance migration; debug | Pure local | Free |

### 5.3 Producers

Producers create queue rows but never call providers directly. Producers:

1. **Candidate promotion** — `hhec_candidate_promote_to_listing()` (`inc/candidates.php:594`) enqueues `geocode` + `places_enrich` for the newly-created listing. New helper `hhec_enrichment_enqueue($listing_id, $job_type, $payload=[], $priority=5)` invoked once per job type. Inserted after line 712 (post-update of the candidate row).
2. **CSV listings importer** — `hhec_listings_import_create_draft()` (`inc/listings-importer.php:439-562`) enqueues `geocode` + `places_enrich` for each draft inserted. Add the enqueue call after line 560 (the `hhec_dq_recompute_and_save` call).
3. **XLSX importer** — `hhe_create_listing()` (`inc/importer.php:167`) and `hhe_update_listing()` (`inc/importer.php:197`) enqueue `geocode` only (preserving the existing posture that XLSX doesn't auto-touch Google Places — operator review is the gate).
4. **Owner self-edit** — `hhec_handle_owner_update_listing()` (`inc/owner.php:288-465`) does NOT enqueue any job. Operator edits are trusted as-is.
5. **Admin bulk re-enrich** — new action on Moderation and Enrichment Queue admin pages: "Bulk re-enrich N listings". Enqueues `places_enrich` + `verify_urls` for the selected rows.
6. **Nightly refresh** (Phase 5) — cron-driven; selects listings with `_hhe_field_provenance` last-touched > 90 days ago AND tier in `[verified, premium, premium_plus]`. Enqueues `places_enrich` low-priority.

### 5.4 Worker — `hhec_enrichment_run_jobs()`

Single cron hook `hhec_enrichment_worker`, scheduled `every_five_minutes` (custom interval added via `cron_schedules` filter). Activation hook adds the schedule; deactivation clears it.

Worker pseudocode (in `inc/enrichment.php`):

```
function hhec_enrichment_run_jobs() {
    // global budget per worker tick — never burn more than this in one cron tick
    $max_per_tick = (int) apply_filters( 'hhec_enrichment_max_per_tick', 10 );

    $jobs = $wpdb->get_results( ... WHERE status='queued' AND next_run_at <= UTC_NOW()
                                    ORDER BY priority ASC, id ASC LIMIT $max_per_tick );

    foreach ( $jobs as $job ) {
        // atomic claim — flip to 'running' only if still 'queued'
        $claimed = $wpdb->query( "UPDATE wp_hhe_enrichment_queue
                                  SET status='running', updated_at=UTC_NOW(),
                                      attempts=attempts+1
                                  WHERE id=%d AND status='queued'", $job->id );
        if ( ! $claimed ) continue;  // race lost; another worker took it

        $listing_id = (int) $job->listing_id;

        // quota guard — refuse to run if the relevant provider is over cap
        if ( ! hhec_enrichment_quota_ok_for_job( $job ) ) {
            $wpdb->update( ..., [ 'status' => 'queued',
                                   'next_run_at' => $tomorrow_midnight_utc,
                                   'last_error'  => 'quota_exceeded' ], ... );
            continue;
        }

        // dispatch
        $result = apply_filters( 'hhec_enrichment_run', null, $job, $listing_id );

        if ( is_null( $result ) ) {
            // no provider claimed it
            hhec_enrichment_mark_failed( $job, 'no_provider_handled' );
            continue;
        }

        switch ( $result['status'] ) {
            case 'success':
                hhec_enrichment_apply_result( $listing_id, $result, $job );
                hhec_enrichment_mark_done( $job, $result );
                break;
            case 'ambiguous':
                hhec_enrichment_mark_done( $job, $result );  // logged, no write
                break;
            case 'skipped':
                hhec_enrichment_mark_done( $job, $result );
                break;
            case 'failed':
                hhec_enrichment_mark_failed_for_retry( $job, $result );
                break;
        }
    }
}
```

Retry / backoff:

- `hhec_enrichment_mark_failed_for_retry` sets `status='queued'`, `next_run_at = now() + min(2^attempts, 60) minutes`, until `attempts >= max_attempts`. Then sets `status='failed'` and stops.
- HTTP 429 or `OVER_QUERY_LIMIT` responses: set `next_run_at = tomorrow_midnight_utc` regardless of attempts — Google's per-day cap resets at UTC midnight.

Concurrency: the atomic UPDATE-WHERE-status='queued' is the lock. No transactions needed; the row's status is the lock. wp-cron's once-per-five-minutes scheduling means concurrent workers are rare; the lock handles the race when they happen.

### 5.5 Repointing the existing "Enrichment Queue" page

`inc/enrichment-queue.php` currently renders a virtual filter view. We keep all of its scoring logic (gates, scores, badges) and add a new tab "Job queue" which renders rows from `wp_hhe_enrichment_queue`. The dead `hhec_geocode_listing()` reference (`inc/enrichment-queue.php:578`) is replaced with `hhec_enrichment_enqueue($id, 'geocode')` calls — the bulk button stops calling the provider directly and just produces queue rows. The next worker tick processes them.

This means the existing UI's "Bulk Geocode" button starts working again for the first time. Operators see a "Queued N geocode jobs" success message; jobs complete asynchronously; results show on the next page load.

### 5.6 Backpressure

If the queue grows beyond a threshold (default 1000 `queued` rows) the producers degrade:
- Bulk import paths log a warning to the importer log instead of enqueueing.
- Nightly refresh skips for the night.
- Admin notice surfaces the depth.

Threshold filterable via `hhec_enrichment_backpressure_threshold`.

---

## 6. Provenance model

The decision: per-field provenance stored as a single JSON post-meta key on each listing. Single key keeps the table compact and avoids the meta-key proliferation that the codebase has already been disciplined about (audit §6 — `register_post_meta` is currently used for exactly one key).

### 6.1 Meta key shape

```
post_meta key: _hhe_field_provenance
value: JSON like:
{
    "_hhe_phone": {
        "source": "google_places",       // provider slug
        "source_id": "ChIJxxx...",       // place_id for Google sources
        "value_hash": "<md5 of value>",
        "confidence": 0.95,
        "captured_at": "2026-05-17T13:42:11Z",
        "locked": false,                  // true if operator edited
        "history": [
            { "source": "csv_import", "captured_at": "2026-04-02T10:00:00Z",
              "value_hash": "...", "superseded_at": "2026-05-17T13:42:11Z" }
        ]
    },
    "_hhe_website":  { ... },
    "_hhe_address":  { ... },
    "_hhe_hours":    { ... }
}
```

`value_hash` is stored, not the value itself — the value lives in the actual `_hhe_*` meta key. The hash exists so we can answer "did the trusted source's value change vs. last run?" without re-reading the value.

`history[]` is capped at 5 entries per field — older entries are dropped on write.

`captured_at` is the timestamp the source said it was correct (where available — e.g. Google's response doesn't include freshness; we use `time()` of the call). `value_hash` is `md5(strtolower(trim($value)))`.

### 6.2 register_post_meta lockdown

`_hhe_field_provenance` is registered via `register_post_meta` in `inc/enrichment.php` with the same pattern used at `inc/editorial-notes.php:64-71`:

```
register_post_meta( 'listing', '_hhe_field_provenance', [
    'type'              => 'string',
    'single'            => true,
    'show_in_rest'      => false,
    'sanitize_callback' => 'hhec_provenance_sanitize',  // ensures valid JSON, caps size
    'auth_callback'     => function () { return current_user_can( 'edit_others_posts' ); },
] );
```

This stops accidental REST exposure and locks writes to operator-cap users.

### 6.3 Provenance helpers (in `inc/enrichment.php`)

```
hhec_provenance_get( int $listing_id ): array
    Returns the decoded JSON or empty array.

hhec_provenance_field( int $listing_id, string $field ): array|null
    Single field's provenance entry.

hhec_provenance_set( int $listing_id, string $field, array $entry ): void
    Writes a single field, preserving others. Pushes old entry into history[].
    Caps history to 5. Updates the JSON post_meta.

hhec_provenance_lock( int $listing_id, string $field ): void
    Sets the locked flag to true for one field (no source change).
    Called from owner-edit and admin-edit hooks (§6.4).

hhec_provenance_is_locked( int $listing_id, string $field ): bool
    Used by the dispatcher's conflict policy.

hhec_provenance_summary( int $listing_id ): array
    Returns counts per source for the moderation UI.
    e.g. ['google_places' => 4, 'csv_import' => 2, 'operator' => 1]
```

### 6.4 Operator-edit lock

When an admin or owner edits a field in the dashboard or wp-admin, we mark the field as `locked=true` with `source='operator'`. Trusted-source providers cannot overwrite a locked field (§7).

Hook in `hhec_handle_owner_update_listing()` (`inc/owner.php:288-465`): after each `update_post_meta` (around line 360 for each text-meta block), call `hhec_provenance_set( $listing_id, $meta_key, [ 'source' => 'operator', 'locked' => true, ... ] )`. Same for the URL meta writes around line 368.

For wp-admin edits (the standard post editor, not the owner dashboard): hook `updated_post_meta` priority 5 listening for any of the watched keys (the same key list `inc/trust.php:130-134` uses). When the change originated from a logged-in user with `edit_posts` cap, treat as operator edit and lock.

Distinguishing "operator edit" from "trusted-source provider applying its own change": the dispatcher in `inc/enrichment.php` calls `update_post_meta` while a global `$hhec_enrichment_apply_in_progress = true` is set. The hook checks the flag and skips when true (the dispatcher is the one writing).

### 6.5 Provenance and the moderation UI

The Editorial Review page (`inc/editorial-review.php`) gains a "Provenance" column on each row showing icons per source (`G` = Google Places, `V` = URL-verified, `O` = operator, `C` = CSV). Click for the JSON detail in a modal. Implementation: read `hhec_provenance_summary($id)` and render in the row template at `inc/editorial-review.php` around line 480.

The "approve" action in editorial-review writes a provenance entry too — source = `editorial_approval`, confidence = 1.0, no value-hash (it's a workflow event, not a field value).

### 6.6 What is NOT in provenance

- Trust score is not provenance — it's derived from values, recomputed by hooks at `inc/trust.php:123-127`. Provenance feeds trust eventually (a "verified by Google Places" entry could be a +5 signal) but that integration is Phase 6.
- Editorial audit (`_hhe_approval_audit` JSON at `inc/editorial-review.php:73-102`) is a workflow log, not field provenance — kept separate.

---

## 7. Conflict resolution

Trusted-source providers run automatically. Operators want their edits sticky. The matrix:

| Existing value | Provider proposes | Action |
|---|---|---|
| Empty | Anything (confidence ≥ 0.5) | Write. Provenance source = provider, locked=false |
| Operator-locked (`locked=true`) | Anything | Skip. Log to job result. Surface in admin "differs from Google" badge but do not overwrite |
| Provider-set, same value-hash | Same value | No-op (refresh `captured_at` only) |
| Provider-set, different value-hash, NEW source ≥ OLD source confidence | New value | Write. Old entry moves to history[]. Trust/DQ scores recompute via existing hooks |
| Provider-set, different value-hash, NEW source < OLD source confidence | New value | Skip. Log result as "lower-confidence-skipped" |
| Provider-set, value older than `staleness_threshold` (default 90 days) | Same source | Refresh — re-fetch, overwrite even if locked-by-same-source — provenance update only |

Implementation lives in `hhec_enrichment_apply_result()`:

```
function hhec_enrichment_apply_result( int $listing_id, array $result, $job ): void {
    global $hhec_enrichment_apply_in_progress;
    $hhec_enrichment_apply_in_progress = true;

    foreach ( $result['fields'] as $meta_key => $proposal ) {
        $existing_prov = hhec_provenance_field( $listing_id, $meta_key );

        if ( $existing_prov && ! empty( $existing_prov['locked'] )
             && $existing_prov['source'] !== $result['provider'] ) {
            // skip — operator (or higher-trust source) holds the lock
            hhec_enrichment_log_skip( $job, $meta_key, 'locked' );
            continue;
        }

        $existing_value = get_post_meta( $listing_id, $meta_key, true );
        $new_hash       = md5( strtolower( trim( $proposal['value'] ) ) );

        if ( $existing_value && $new_hash === md5( strtolower( trim( $existing_value ) ) ) ) {
            // no-op refresh — same value, just bump captured_at
            hhec_provenance_set( $listing_id, $meta_key, array_merge(
                $existing_prov ?: [],
                [ 'captured_at' => gmdate( 'c' ),
                  'source'       => $result['provider'],
                  'confidence'   => $proposal['confidence'] ]
            ) );
            continue;
        }

        if ( $existing_prov
             && $existing_prov['source'] !== $result['provider']
             && $proposal['confidence'] < (float) $existing_prov['confidence'] ) {
            // lower-confidence — skip
            hhec_enrichment_log_skip( $job, $meta_key, 'lower_confidence' );
            continue;
        }

        // commit
        update_post_meta( $listing_id, $meta_key, $proposal['value'] );
        hhec_provenance_set( $listing_id, $meta_key, [
            'source'      => $result['provider'],
            'source_id'   => $result['place_id'] ?? null,
            'value_hash'  => $new_hash,
            'confidence'  => $proposal['confidence'],
            'captured_at' => gmdate( 'c' ),
            'locked'      => false,
        ] );
    }

    $hhec_enrichment_apply_in_progress = false;

    // existing recomputes fire via the meta-change hooks already wired:
    //   - inc/trust.php:125-127 on the watched keys
    //   - inc/data-quality.php:99-101
    //   - inc/address-policy.php (via _hhe_address / _hhe_lat / _hhe_lng changes)
    // we do NOT call them explicitly — the hooks do it.
}
```

### 7.1 Staleness sweep

The nightly refresh job (Phase 5) selects listings whose latest provenance entry is older than the staleness threshold and enqueues `places_enrich` low-priority. This is the only "automatic overwrite of an unchanged field" path. Default threshold: 90 days. Filterable via `hhec_enrichment_staleness_threshold_days`.

### 7.2 Operator override of trusted-source value

If Google Places says the phone is `+66 32 999 999` but the operator knows it's `+66 32 888 888`, the operator edits the field. The owner-edit handler marks it `locked=true, source=operator`. The next Places run sees the lock and reports the discrepancy in the job result without overwriting. The moderation page surfaces the discrepancy as a "trusted source disagrees" badge — operator can investigate. No automatic resolution.

### 7.3 Multi-provider field overlap

Two providers cover the same field — e.g. Places gives a `_hhe_phone` and a future provider also gives one. The dispatcher gives the first non-null provider priority by registration order (priority arg on `add_filter`). If a higher-priority provider returns `status=success`, lower-priority providers are not called for that job. This is enforced by the worker loop returning on first non-null result.

To override the priority, an operator can set per-field source preference via filter `hhec_provenance_source_priority`. Out-of-scope for Phase 3–4.

---

## 8. Google Places integration plan

Concrete steps for Phase 3 (the audit identifies `inc/geocoder.php:295` as the existing Google touchpoint).

### 8.1 New file `inc/places.php`

Boilerplate mirrors `inc/geocoder.php`. Functions:

```
hhec_places_get_api_key(): string
hhec_places_key_source(): string  // 'settings' | 'constant' | 'none'
hhec_places_find_place( string $name, ?string $address, ?string $locality_bias ): array|WP_Error
hhec_places_details( string $place_id, array $fields ): array|WP_Error
hhec_places_enrich( int $listing_id, array $job ): array
hhec_places_quota_check( int $cost_units ): bool
```

Endpoint URLs (Google Places API):

- `https://maps.googleapis.com/maps/api/place/findplacefromtext/json` — params `input`, `inputtype=textquery`, `fields=place_id,name,formatted_address,geometry`, `locationbias=circle:5000@<lat>,<lng>` (Hua Hin centroid), `key=<api_key>`.
- `https://maps.googleapis.com/maps/api/place/details/json` — params `place_id`, `fields=place_id,name,formatted_address,formatted_phone_number,international_phone_number,website,opening_hours,business_status,address_components,url,geometry,types`, `key=<api_key>`.

Field choice avoids the "atmosphere" billing tier (reviews, photos) which costs more. Photos handled separately if/when we add a photo importer — Phase 6.

### 8.2 Hook into the dispatcher

```
add_filter( 'hhec_enrichment_run', 'hhec_places_dispatch', 30, 3 );

function hhec_places_dispatch( $result, $job, $listing_id ) {
    if ( ! is_null( $result ) ) return $result;
    if ( $job->job_type !== 'places_enrich' ) return $result;
    if ( hhec_places_get_api_key() === '' ) return $result;  // not configured
    return hhec_places_enrich( $listing_id, (array) $job );
}
```

Priority 30: leaves room for a higher-priority provider to claim first if we ever add one.

### 8.3 Place-id-first matching

For an existing listing without a `_hhe_place_id`, the Find Place call attempts resolution. If the candidate score is < 0.5 (Google's `confidence` analogue, or our heuristic when missing) the job records `status='ambiguous'` and waits for operator input. The Editorial Review surface gains a "Suggest place_id" widget — the operator pastes a place_id from a manual Google Maps lookup; that triggers a `places_enrich` job using the supplied id.

For a new listing arriving via candidate promotion, the candidate row already has `maps_url` (Google Maps short URL) in many cases. We add a one-line parser to extract `place_id` from the URL where possible (Google's CID-only URLs require a redirect-follow which we already do in URL verification).

### 8.4 `_hhe_place_id` is the join key

Once captured, `_hhe_place_id` becomes the canonical join key between our listing and Google's record. It enters duplicate detection (§10) as a high-weight signal. Operator-locked, because if it's wrong the whole Places stream is wrong.

### 8.5 Quota enforcement

The `geocode_daily_limit` setting (`inc/settings.php:304`) is finally read — see §11. Per-provider counters:

- `google_geocode` — incremented by `hhec_geocode_via_google()` (`inc/geocoder.php:295`).
- `google_places_findplace` — incremented by `hhec_places_find_place()`.
- `google_places_details` — incremented by `hhec_places_details()`.

Cap is shared by default (Google's billing is at the project level — there's no per-API quota in our UI) but each counter is observable separately for diagnostic purposes.

---

## 9. Website / social verification plan

Phase 4 — covered in §4.2 above. Additional design notes:

### 9.1 What gets verified, when

Every listing's `_hhe_website`, `_hhe_facebook`, `_hhe_instagram`, `_hhe_maps_url` is verified on:
- creation (queued by every producer in §5.3)
- weekly re-verification (Phase 5 cron-driven)
- operator-initiated (admin button)

### 9.2 Verification meta keys

| Meta key | Set by | Reset by |
|---|---|---|
| `_hhe_website_verified` | `_hhe_url_verifier` provider on success | URL change (handled in owner-edit hook) |
| `_hhe_website_http_code` | as above | as above |
| `_hhe_website_canonical_url` | as above | as above |
| `_hhe_website_verified_at` | as above | as above |
| `_hhe_facebook_verified` | as above | URL change |
| `_hhe_facebook_canonical_url` | as above | URL change |
| `_hhe_facebook_verified_at` | as above | URL change |
| `_hhe_instagram_verified` | as above | URL change |
| (etc) | | |

Provenance entry under `_hhe_field_provenance` for `_hhe_website` (etc.) — same shape as §6.

### 9.3 URL change detection

Hook on `updated_post_meta`/`added_post_meta` for the URL meta keys. When the value changes, clear the verified meta and enqueue a new `verify_urls` job.

### 9.4 Anti-cloaking

`wp_remote_head` followed by `wp_remote_get` for 200 responses. We DO follow redirects (up to 3 hops). Redirect chains are recorded — a listing whose `_hhe_website` ends up at facebook.com after a redirect is flagged for operator review (likely the operator pasted a FB URL into the website field).

### 9.5 What we don't do

- No HTTPS-strict policy. We accept HTTP responses (with reduced confidence). Many small Thai businesses host on shared hosting without TLS.
- No content fingerprint matching. We don't try to confirm the page is actually about this business beyond `<title>` heuristics. That's a deep computer-vision task; out-of-scope.
- No SEO scraping. We deliberately do not parse content beyond `<title>`, `og:url`, `og:title`, `link[rel=canonical]`. Anything richer risks ToS violations and is out-of-scope.

---

## 10. Duplicate detection evolution

The existing `hhec_candidate_find_duplicate_listings()` (`inc/candidates.php:433-572`) is the most sophisticated dedupe primitive in the codebase. It scores six signals (title exact 50, title fuzzy 25, phone last-9-digits 40, website host 40, geo within 50m 35, slug fuzzy 15) and bucketises into low/medium/high risk.

### 10.1 Add `place_id` as a new signal

When the candidate or listing has `_hhe_place_id` set, an exact match yields +80 (higher than any existing signal). This makes Google's identifier the most authoritative dedupe key once we capture it.

Implementation: extend `hhec_candidate_find_duplicate_listings()` at `inc/candidates.php:433` — add a new query branch around line 480 (after phone-match) that runs a meta-query on `_hhe_place_id = candidate.place_id`. Weight from `hhec_candidate_dup_signal_weights` filter (new filter; today the weights are inline constants — Phase 3 introduces the filter).

This is backwards-compatible: candidates without a `place_id` field continue to use the existing 6-signal scorer; rows with one get a 7th signal.

### 10.2 Pre-create dedupe in producers

Producers that today create unconditionally (the candidate promotion path, the listings importer path) gain a dedupe check before insert. New helper:

```
hhec_enrichment_check_dup_before_create( array $proposed_data ): array
    Returns [ 'duplicates' => [...with scores], 'risk' => 'low|medium|high' ]
```

Called from `hhec_candidate_promote_to_listing()` (`inc/candidates.php:594`) at the top of the function. If risk='high' (score ≥ 60), promotion is refused with a clear error — operator must resolve manually via the existing "duplicate" status on the candidate row.

### 10.3 Continuous dedupe sweep

A new cron `hhec_enrichment_dedupe_sweep` runs weekly. Calls `hhec_trust_recompute_duplicate_risk()` (`inc/trust.php:163`) which already does title-based clustering across all listings. Result: `_hhe_duplicate_risk` post-meta flag is refreshed. Enrichment Queue and Moderation surface listings flagged this way.

No change to the dedupe algorithm itself — the existing one is good. We just run it on a schedule rather than only on backfill.

### 10.4 What duplicate detection still requires manual resolution

- Two listings that share a Google place_id are not always operationally duplicates — a business with two branches sharing one Google entry, or a renamed business whose old listing should be retired. Operator chooses.
- Cross-category matches (same business listed under "Medical" and "Health & Wellness"). Operator merges.
- Soft-pass categories (audit §1, `inc/enrichment-queue.php:68`) — service-area businesses with no fixed address have systematically weak dedupe signals. These remain manual-review-only.

---

## 11. Quota enforcement

Phase 1 of enrichment-doc work, deliverable independent of Places integration. Wires up the `geocode_daily_limit` setting that already exists in UI but is never read (audit §13).

### 11.1 New file `inc/quota.php`

```
hhec_quota_window_key( string $provider ): string
    Returns 'hhec_quota_<provider>_<YYYYMMDD-UTC>'.
    e.g. 'hhec_quota_google_geocode_20260517'.

hhec_quota_consume( string $provider, int $units = 1 ): bool
    Atomically increments the count for today's window via wp_options.
    Returns true if under cap (caller may proceed), false if over.
    Uses a wp_options row, not a transient, to avoid object-cache eviction risk.

hhec_quota_remaining( string $provider ): int
    Read-only helper for admin UI.

hhec_quota_cap( string $provider ): int
    Resolves the configured cap for this provider:
      google_geocode         → hhe_settings['geocode_daily_limit']  (default 1000)
      google_places_findplace → hhe_settings['places_findplace_daily_limit']  (default 500)
      google_places_details  → hhe_settings['places_details_daily_limit']  (default 500)
      url_verifier           → no cap (free)
    Filterable via 'hhec_quota_cap'.

hhec_quota_cleanup_old_windows()
    Cron-driven (daily). Deletes wp_options rows for windows older than 30 days.
```

Storage: one `wp_options` row per provider per day, non-autoloaded:

```
update_option( 'hhec_quota_google_geocode_20260517', $count, false );
```

This pattern is already used by Stripe's dedup log (`inc/stripe.php:475`).

### 11.2 Integration points

- `inc/geocoder.php:295` (`hhec_geocode_via_google`) — wrap the `wp_remote_get` call in a `hhec_quota_consume('google_geocode')` check. If false, return `status='skipped'`, `error='quota_exceeded'`. The geocode-log row records this (provider='google_geocode', status='skipped').
- `inc/places.php:hhec_places_find_place` — same pattern with `google_places_findplace`.
- `inc/places.php:hhec_places_details` — same pattern with `google_places_details`.
- Worker: `hhec_enrichment_quota_ok_for_job()` is a pre-flight check that consults `hhec_quota_remaining` for the providers a job might call. If 0, the job is re-queued for tomorrow UTC midnight rather than burning an attempt.

### 11.3 Settings UI

Geocoding settings tab (`inc/settings.php:279-333`) gains three input fields:

- `geocode_daily_limit` — already exists, finally honoured. Default 1000.
- `places_findplace_daily_limit` — new. Default 500.
- `places_details_daily_limit` — new. Default 500.

Plus a "Today's usage" panel that shows live counters per provider (queries `hhec_quota_remaining` for each).

### 11.4 Hard stops vs soft warnings

When a quota is exceeded:
- Worker: requeues job for next UTC day.
- Synchronous user-initiated geocode (bulk-button click): displays "Daily quota exceeded — try again tomorrow or raise the limit in Settings → Geocoding". No silent failure.
- Cron-driven nightly refresh: skips with log entry "skipped due to quota".

This converts the existing "unbounded billing risk" into a deterministic spend ceiling.

---

## 12. What stays manual-review only

The brief asks "what should remain manual-review only". Drawn directly from the audit:

1. **First-time publish of a strict-category listing** (medical, legal, hospital, doctor, dental). The existing `hhec_address_publish_gate()` (`inc/address-policy.php:219`) forces a manual review path; trusted-source enrichment never bypasses it. We strengthen this — even a Google Places "verified" address does not auto-flip `_hhe_editorial_approved` to 1. Operator still clicks Approve.
2. **Duplicate-risk high (score ≥ 60)**. Even when `place_id` matches another listing, automated merge is forbidden. Operator decides whether to retire one, rename one, or merge.
3. **Operator-locked field changes**. Trusted source disagrees with operator-locked value → discrepancy badge in moderation UI, no overwrite.
4. **Business status non-OPERATIONAL**. Google Places `business_status` of `CLOSED_TEMPORARILY` or `CLOSED_PERMANENTLY` triggers an editorial review badge — the listing isn't auto-trashed.
5. **Soft-pass categories with weak signals**. `inc/enrichment-queue.php:68` already defines these; trust never crosses the threshold automatically.
6. **Public notes (`_hhe_notes`) and internal notes (`_hhe_admin_notes`)**. Trusted sources never write to either. The note-policy lockdown at `inc/editorial-notes.php` is preserved.
7. **Owner-claim approval**. `inc/claims.php` is unchanged. Trusted source data presence doesn't bypass the operator's manual approval of the claim.
8. **Premium tier**. Trusted sources never write `_hhe_premium_level`. Payment-driven path only.
9. **`_hhe_featured`, `_hhe_verified`, `_hhe_phone_verified`, `_hhe_editorial_approved`**. Operator-only flags. Trusted sources can suggest (via provenance badges) but never set.
10. **Category assignment**. Sheet/CSV maps categories; trusted-source results never change the `listing_category` taxonomy. Google's `types` field is recorded as `_hhe_places_types` (informational) but not auto-mapped.

---

## 13. Diagrams

### 13.1 New-listing enrichment flow

```
Operator promotes candidate / imports CSV draft
        │
        │  hhec_candidate_promote_to_listing()       [inc/candidates.php:594]
        │  -or- hhec_listings_import_create_draft()  [inc/listings-importer.php:439]
        ▼
   listing post inserted (status=pending|draft, _hhe_editorial_approved=0)
        │
        │  producer calls hhec_enrichment_enqueue(...)
        │  for each of [geocode, places_enrich, verify_urls]
        ▼
  wp_hhe_enrichment_queue: 3 rows status=queued
        │
        │  next wp-cron tick (≤ 5 min)
        ▼
   hhec_enrichment_run_jobs()                     [inc/enrichment.php]
        │
        │  pulls up to 10 queued jobs (prio order, FIFO by id)
        │  atomic claim — status→running
        │  quota pre-flight check
        │
        ├─ job geocode
        │   ├─ apply_filters('hhec_geocode_address', null, $query, $id)
        │   │   ├─ existing nominatim/google adapter at inc/geocoder.php
        │   │   ├─ writes _hhe_lat, _hhe_lng, _hhe_geocode_provider, _hhe_geocoded_at
        │   │   ├─ writes _hhe_field_provenance for those keys
        │   │   ├─ logs to wp_hhe_geocode_log
        │   │   ├─ triggers hhec_address_recompute_and_save (existing)
        │   │   └─ existing hooks fire trust + dq recompute
        │   └─ queue row → status=done
        │
        ├─ job places_enrich
        │   ├─ apply_filters('hhec_enrichment_run', null, $job, $id)
        │   │   ├─ hhec_places_dispatch (prio 30)  [inc/places.php]
        │   │   │   ├─ if no _hhe_place_id: Find Place call
        │   │   │   ├─ Places Details call
        │   │   │   ├─ quota_consume('google_places_*') x2
        │   │   │   └─ returns { provider, status, fields[], place_id, raw_response, cost_units }
        │   │   └─ hhec_enrichment_apply_result($id, $result, $job)
        │   │       per-field conflict policy (§7)
        │   │       update_post_meta + hhec_provenance_set per field
        │   │       (existing meta-change hooks recompute trust + dq)
        │   └─ queue row → status=done
        │
        ├─ job verify_urls
        │   ├─ for each of _hhe_website, _hhe_facebook, _hhe_instagram, _hhe_maps_url:
        │   │   hhec_url_verify()                  [inc/url-verifier.php]
        │   │   writes _hhe_*_verified, _hhe_*_canonical_url, _hhe_*_verified_at
        │   │   provenance entries per field
        │   └─ queue row → status=done
        │
        ▼
   Listing now has _hhe_field_provenance + verified URLs + geocoded coords
        │
        ▼
   hhe_approval_readiness_score recomputed via existing meta-change hooks
        │
        ▼
   Editorial Review page surfaces the listing in 'ready' tab when:
   - all gates green (existing logic at inc/enrichment-queue.php:94)
   - AND provenance shows trusted-source coverage on phone OR website
     (new gate field_provenance_complete)
        │
        ▼
   Operator clicks Approve → hhe_editorial_approve()  [inc/editorial-review.php:119]
   → _hhe_editorial_approved=1, status=publish
   → audit log entry appended
```

### 13.2 Operator-edit conflict flow

```
Operator edits phone in /my-listings/ dashboard
        │
        │  POST admin-post hhe_owner_update_listing
        ▼
   hhec_handle_owner_update_listing()              [inc/owner.php:288-465]
        ├─ updates _hhe_phone via update_post_meta
        ├─ NEW: hhec_provenance_set($id, '_hhe_phone',
        │       [ source=operator, locked=true, ... ])
        └─ existing recompute hooks fire (trust + dq + address)

        ── time passes; nightly staleness sweep runs ──

   cron hhec_enrichment_worker
        │
        ▼
   hhec_places_enrich() runs places_enrich for this listing
        ├─ returns fields including _hhe_phone with new value
        │
        ▼
   hhec_enrichment_apply_result()
        ├─ reads provenance for _hhe_phone → locked=true, source=operator
        ├─ skip path: locked
        ├─ logs to job.result: { _hhe_phone: 'skipped_locked',
        │                          provider_value: '+66 32 999 999',
        │                          operator_value: '+66 32 888 888' }
        └─ no update_post_meta call

        ── editorial review page renders ──

   "Trusted-source disagrees on phone" badge shown
        │
        ▼
   Operator can:
   - clear lock (treats provider answer as preferred)
   - dismiss (keeps operator value, suppresses badge)
```

---

## 14. Implementation phases

Phases 1–2 belong to the Airwallex doc.

### Phase 3 — Google Places enrichment

**Goal**: a Google Place ID is captured for every new listing, and Places Details fills phone / website / hours / address / coords without overwriting operator-locked fields. Per-field provenance recorded. Quota enforced. No automated approval.

**Deliverables**:
1. Add `inc/quota.php` and wire `hhec_quota_consume` into `hhec_geocode_via_google()` (`inc/geocoder.php:295`).
2. Add `inc/enrichment.php` with the dispatcher filter, provenance helpers, and the queue worker. Create `wp_hhe_enrichment_queue` table on activation (`huahinexpats-core.php:100-119`). Register provenance meta (`register_post_meta` with auth_callback).
3. Add `inc/places.php` (Find Place + Places Details adapter). Hook into `hhec_enrichment_run` filter at priority 30.
4. Modify producers: candidate promotion (`inc/candidates.php:594-715`) and CSV listings importer (`inc/listings-importer.php:439-562`) to enqueue jobs after insertion.
5. Modify `inc/enrichment-queue.php:578` to enqueue jobs instead of calling the (undefined) `hhec_geocode_listing()`. Add a "Job queue" tab to the existing page showing rows from the new table.
6. Settings UI: add Places-API daily caps to the Geocoding section (`inc/settings.php:279-333`). Add a "Today's usage" panel.
7. Owner-edit hook (`inc/owner.php:288-465`) sets `locked=true` provenance entries for each edited meta.
8. wp-admin edit hook: post-save listener that marks operator-locked provenance for the watched meta keys.
9. Schema migration for `_hhe_field_provenance` (register_post_meta).
10. New cron `hhec_enrichment_worker` every-5-minutes (with custom interval added via `cron_schedules`).

**Acceptance**:
- A new candidate promoted to a draft listing has `_hhe_place_id` populated within 5 minutes.
- The listing's `_hhe_field_provenance` JSON shows `_hhe_phone` and `_hhe_website` populated by `google_places` with confidence ≥ 0.9.
- Operator edits `_hhe_phone` in the dashboard; provenance flips to `source=operator, locked=true`.
- A re-run of `places_enrich` for the listing logs a `skipped_locked` entry and does not change the value.
- Quota: set `geocode_daily_limit=2`. Run 3 geocode jobs. The third one is requeued for next UTC day with `last_error='quota_exceeded'`.
- The "Today's usage" panel shows accurate live counts.

**Out of scope for Phase 3**: nightly staleness sweep, URL verification, automated approval, photo import.

### Phase 4 — website / social verification

**Goal**: every listing's URLs (`_hhe_website`, `_hhe_facebook`, `_hhe_instagram`, `_hhe_maps_url`) are HTTP-verified, reachability is recorded, broken URLs surface in moderation.

**Deliverables**:
1. Add `inc/url-verifier.php`. Hook into `hhec_enrichment_run` filter at priority 50 for `job_type=verify_urls`.
2. Per-URL per-host throttle via transient.
3. Producers enqueue `verify_urls` after insertion (candidate promotion, CSV listings importer).
4. Owner-edit hook clears verified meta and enqueues `verify_urls` when URL fields change.
5. New gate `urls_verified_or_absent` in `inc/enrichment-queue.php:94`. Surfaces "Broken URLs" tab on enrichment-queue page.
6. New moderation tab "Broken URL" — listings with verified=0 for any URL.

**Acceptance**:
- A new listing with `_hhe_website=https://example.test/` (which 404s) has `_hhe_website_verified=0` and surfaces in the Broken URL tab.
- Changing `_hhe_website` to a working URL clears the meta and re-enqueues. After cron run, `_hhe_website_verified=1` and `_hhe_website_canonical_url` set.
- A Facebook URL that 301-redirects to the Facebook login wall returns `status=redirected_to_facebook` with conf 0.5 (host matches but content unclear).

### Phase 5 — enrichment automation

**Goal**: ongoing operations. Stale listings get refreshed. Bulk operator tools work. Backpressure prevents runaway.

**Deliverables**:
1. Add daily cron `hhec_enrichment_staleness_sweep` — selects listings with latest provenance > 90 days, enqueues `places_enrich` priority 7 (low).
2. Add daily cron `hhec_enrichment_dedupe_sweep` — calls `hhec_trust_recompute_duplicate_risk()` (`inc/trust.php:163`).
3. Add weekly cron `hhec_enrichment_url_reverify_sweep` — enqueues `verify_urls` for every listing whose `_hhe_*_verified_at` is > 7 days old.
4. Bulk re-enrich button on Moderation and Enrichment Queue admin pages.
5. Backpressure threshold + admin notice when queue depth > 1000.
6. Job retention cleanup cron: deletes `wp_hhe_enrichment_queue` rows where `status='done'` AND `completed_at < now() - 30 days`.

**Acceptance**:
- A listing whose `_hhe_field_provenance` latest `captured_at` is 95 days old gets a queued `places_enrich` row by the next nightly sweep.
- Setting `hhec_enrichment_backpressure_threshold=5` (filterable) and seeding 10 queued rows triggers the admin notice and producers degrade to "queued at next opportunity" mode.

### Phase 6 — production hardening

Spans payments and enrichment. Enrichment-specific items:

1. **Photo import** (Places Photos API). Operator-initiated only — never automatic.
2. **Multi-currency display for Places-confirmed prices** — Places API doesn't return prices; this is operator-only.
3. **Facebook Graph adapter** for verified FB Page records (requires app review).
4. **Cross-provider precedence configuration UI**.
5. **Provenance signal in trust score** — add a trust signal `+5` for "field provenance includes a trusted-source verification within the last 90 days".
6. **Raw response retention policy** — `wp_hhe_geocode_log.raw_response` (`inc/geocode-log.php:54`) grows unbounded. Add a daily purge for rows older than 90 days; archive summary stats to a smaller table.
7. **Provider-specific failure mode dashboards** — surface "Google returned ZERO_RESULTS for N listings this week" so the operator can audit.
8. **Operator workflow: "review trusted-source discrepancies"** — a dedicated admin page that lists every discrepancy badge (`§7.2` cases) and lets the operator accept-provider / keep-operator / merge.

---

## 15. Master index — exact files to modify or add

### New files

| Path | Purpose |
|---|---|
| `wp-content/plugins/huahinexpats-core/inc/enrichment.php` | Dispatcher, queue worker, provenance helpers |
| `wp-content/plugins/huahinexpats-core/inc/places.php` | Google Places provider |
| `wp-content/plugins/huahinexpats-core/inc/url-verifier.php` | URL verification provider |
| `wp-content/plugins/huahinexpats-core/inc/quota.php` | Per-provider per-day counter |

### Modified files

| Path | Lines | Change |
|---|---|---|
| `huahinexpats-core.php` | 34-95 | Add four `require_once` lines for the new files (after `inc/geocoder.php`, line 72) |
| `huahinexpats-core.php` | 100-119 | Activation hook: add `hhec_create_enrichment_queue_table` call |
| `huahinexpats-core.php` | 124-128 | Deactivation hook: clear `hhec_enrichment_worker`, `hhec_enrichment_staleness_sweep`, `hhec_enrichment_dedupe_sweep`, `hhec_enrichment_url_reverify_sweep`, `hhec_enrichment_quota_cleanup` |
| `inc/geocoder.php` | 295-355 | `hhec_geocode_via_google`: extract `place_id` and `address_components` from response; add `hhec_quota_consume('google_geocode', 1)` pre-call check; on `false`, return `status='skipped'`, `error='quota_exceeded'` |
| `inc/candidates.php` | 433-572 | `hhec_candidate_find_duplicate_listings`: add `_hhe_place_id` exact-match signal (weight 80). Add `hhec_candidate_dup_signal_weights` filter |
| `inc/candidates.php` | 594-715 | `hhec_candidate_promote_to_listing`: after the post-update of the candidate row (line 712), call `hhec_enrichment_enqueue($post_id, 'geocode')` and `hhec_enrichment_enqueue($post_id, 'places_enrich')` and `hhec_enrichment_enqueue($post_id, 'verify_urls')`. Add pre-create dup-check (`hhec_enrichment_check_dup_before_create`) at the top |
| `inc/listings-importer.php` | 439-562 | `hhec_listings_import_create_draft`: after the `hhec_dq_recompute_and_save` call (line 560), enqueue the three job types |
| `inc/importer.php` | 167-260 | `hhe_create_listing` and `hhe_update_listing`: enqueue `geocode` only (preserving the XLSX-doesn't-touch-Places posture) |
| `inc/owner.php` | 288-465 | `hhec_handle_owner_update_listing`: after each `update_post_meta` for the watched keys, call `hhec_provenance_set` with `source=operator, locked=true` |
| `inc/enrichment-queue.php` | 94-200 | Gate engine: add `field_provenance_complete` gate (weight 5) |
| `inc/enrichment-queue.php` | 218-220 | Admin menu callback: render new "Job queue" tab |
| `inc/enrichment-queue.php` | 555-595 | `hhec_enrichment_bulk_handle` — replace the `hhec_geocode_listing($id)` call (which is dead) with `hhec_enrichment_enqueue($id, 'geocode')` |
| `inc/editorial-review.php` | 218-230 | Add "Provenance" column to the editorial review table; render badges from `hhec_provenance_summary($id)` |
| `inc/settings.php` | 279-333 | Geocoding section: add `places_findplace_daily_limit` and `places_details_daily_limit` fields. Add "Today's usage" panel. Add a "Test Places API" button |
| `inc/settings.php` | 408-505 | Diagnostics rendering: include per-provider quota usage |
| `inc/trust.php` | 73-91 | Phase 6 only: add a signal `provenance_verified_recent` (+5) |
| `inc/geocode-log.php` | (Phase 6) | Add a 90-day purge cron |

### Hooks added or extended

| Hook | Owner | Purpose |
|---|---|---|
| `hhec_enrichment_run` | `inc/enrichment.php` | Dispatcher filter — providers register here |
| `hhec_enrichment_worker` (cron) | `inc/enrichment.php` | Every 5 minutes |
| `hhec_enrichment_staleness_sweep` (cron) | `inc/enrichment.php` | Daily |
| `hhec_enrichment_dedupe_sweep` (cron) | `inc/enrichment.php` | Daily |
| `hhec_enrichment_url_reverify_sweep` (cron) | `inc/enrichment.php` | Weekly |
| `hhec_enrichment_quota_cleanup` (cron) | `inc/quota.php` | Daily — purge old quota windows |
| `hhec_enrichment_max_per_tick` (filter) | `inc/enrichment.php` | Tunes worker batch size |
| `hhec_enrichment_backpressure_threshold` (filter) | `inc/enrichment.php` | Tunes degrade-producers threshold |
| `hhec_enrichment_staleness_threshold_days` (filter) | `inc/enrichment.php` | Tunes staleness sweep age |
| `hhec_places_field_confidence` (filter) | `inc/places.php` | Per-field confidence override |
| `hhec_quota_cap` (filter) | `inc/quota.php` | Per-provider cap override |
| `hhec_provenance_source_priority` (filter, Phase 6) | `inc/enrichment.php` | Multi-provider precedence |
| `hhec_candidate_dup_signal_weights` (filter) | `inc/candidates.php` | Currently inline constants — promoted to filter |
| `add_filter('hhec_geocode_address', ..., 20)` | `inc/places.php` | NOT this filter — places is on `hhec_enrichment_run` |
| `add_filter('hhec_enrichment_run', 'hhec_places_dispatch', 30)` | `inc/places.php` | Places provider registration |
| `add_filter('hhec_enrichment_run', 'hhec_url_verifier_dispatch', 50)` | `inc/url-verifier.php` | URL verifier provider registration |

### Functions added

| Function | File | Purpose |
|---|---|---|
| `hhec_create_enrichment_queue_table()` | `inc/enrichment.php` | dbDelta with version gate |
| `hhec_enrichment_enqueue()` | `inc/enrichment.php` | Producer entry point |
| `hhec_enrichment_run_jobs()` | `inc/enrichment.php` | Worker — cron callback |
| `hhec_enrichment_apply_result()` | `inc/enrichment.php` | Conflict policy + provenance write |
| `hhec_enrichment_quota_ok_for_job()` | `inc/enrichment.php` | Pre-flight quota check |
| `hhec_enrichment_check_dup_before_create()` | `inc/enrichment.php` | Pre-create dedupe |
| `hhec_provenance_get()` / `set()` / `field()` / `lock()` / `is_locked()` / `summary()` / `sanitize()` | `inc/enrichment.php` | Provenance helpers |
| `hhec_url_canonicalize()` | `inc/enrichment.php` (or `inc/url-verifier.php`) | Single source of truth for URL normalisation |
| `hhec_places_get_api_key()` / `key_source()` / `find_place()` / `details()` / `enrich()` / `quota_check()` / `dispatch()` | `inc/places.php` | Places provider |
| `hhec_url_verify()` / `dispatch()` | `inc/url-verifier.php` | URL verifier |
| `hhec_quota_window_key()` / `consume()` / `remaining()` / `cap()` / `cleanup_old_windows()` | `inc/quota.php` | Quota counters |

### Constants added

| Constant | File | Value |
|---|---|---|
| `HHEC_ENRICHMENT_QUEUE_VERSION` | `inc/enrichment.php` | `'v1'` |
| `HHEC_ENRICHMENT_JOB_RETENTION_DAYS` | `inc/enrichment.php` | `30` |
| `HHEC_ENRICHMENT_STALENESS_DEFAULT_DAYS` | `inc/enrichment.php` | `90` |
| `HHEC_PLACES_API_BASE` | `inc/places.php` | `'https://maps.googleapis.com/maps/api/place/'` |
| `HHEC_PLACES_DETAILS_FIELD_MASK` | `inc/places.php` | (the fixed comma-separated fields list) |
| `HHEC_URL_VERIFY_TIMEOUT` | `inc/url-verifier.php` | `10` (HEAD) / `15` (GET) |
| `HHEC_URL_VERIFY_HOST_THROTTLE_SECONDS` | `inc/url-verifier.php` | `1` |
| `HHEC_QUOTA_RETENTION_DAYS` | `inc/quota.php` | `30` |

---

## 16. Open questions to confirm before Phase 3 starts

1. **Google Maps API project quota**: confirm GCP project quotas for Places API (Find Place + Details) are sized for the expected daily volume. Worst case: 500 new candidates promoted/day × 2 calls = 1000 calls — well within typical Places quotas. But if the operator forgets to enable the Places API on the existing geocode project, we get `REQUEST_DENIED` and the quota counter still increments — design behaviour, but worth flagging.
2. **Places API field-mask billing**: Places Details charges per field category (Basic, Contact, Atmosphere). Our chosen field-mask is Basic + Contact (cheaper). Atmosphere (reviews, ratings) deliberately omitted. Operator confirms this matches budget expectations.
3. **Operator-edit-as-lock policy**: this doc proposes that any owner edit locks the field permanently against trusted-source overwrites. Alternative: lock for 90 days then unlock. Recommend permanent lock until operator unlocks via the "discrepancy review" UI in Phase 6. Confirm.
4. **Initial-load posture**: on plugin upgrade with this code, do we backfill provenance for existing listings as `source=legacy, captured_at=now()` so the UI doesn't show "unknown" for every field? Recommend yes; cheap one-shot. Track via option `hhec_provenance_legacy_backfilled_v1`.
5. **Worker concurrency**: WP-cron is single-threaded by request. If a tick takes > 5 minutes, the next tick can be skipped. Recommend setting a hard 4-minute budget per `hhec_enrichment_run_jobs` invocation; remaining jobs wait. Confirm threshold.
6. **Mobile-app future**: should provenance be `show_in_rest=true` so a future mobile app can render trust badges? Recommend false in Phase 3, revisit in Phase 6 when we know if the field shape is stable.

These belong in `docs/ops/enrichment-launch-checklist.md` once Phase 3 begins.

---

## 17. Not in scope

- Place-detail prefetch for Phase 3. We fetch on-demand per job, not in advance.
- Review / rating import. Out of scope by design — we don't want third-party reviews fragmenting the operator's editorial voice.
- Multi-language enrichment. Google returns Thai-language names where the operator has English; choosing which to display is editorial. No automated translation.
- Operator-typed merge of two listings. The duplicate-risk UI surfaces pairs; manual merge is wp-admin work.
- Email-address verification. SMTP probing is fraught; deferred indefinitely.
- Photo deduplication. If/when we import Places Photos, perceptual hashing comes later — Phase 6 at earliest.
- Webhook-style "Google sent us an update for place X". Google does not push; we pull on cron. If a future provider supports push, the queue gracefully accepts producer-initiated job rows.

---

## 18. Acceptance criteria for this design doc

Cross-checked against the brief's acceptance criteria:

- **Precise implementation roadmap against the real WP architecture**: every file/function/line cited above is verified in the audit-package tree. The dead-code reference to `hhec_geocode_listing()` (`inc/enrichment-queue.php:578`) is explicitly addressed. No invented APIs.
- **Existing systems reused, not duplicated**: candidate scorer, address policy, editorial review, moderation, trust, data-quality, geocode log, candidate table — all reused. The new queue table is the only net-new DB object.
- **Airwallex integration path clear**: covered in `docs/AIRWALLEX_WORDPRESS_INTEGRATION_ARCHITECTURE.md`.
- **Trusted-source enrichment path clear**: §3–§11 cover provider model, queue, provenance, conflict, dedupe evolution, and quotas. §12 covers what stays manual.
- **Security and moderation remain intact**: §6.2 keeps the `register_post_meta` lockdown pattern. §7 keeps operator edits sticky. §12 enumerates the 10 things that remain manual-review-only. Strict-category publish gate at `inc/address-policy.php:219` continues to apply unchanged. Trusted source never sets `_hhe_editorial_approved`, `_hhe_verified`, `_hhe_phone_verified`, `_hhe_featured`, or `_hhe_premium_level`.
- **No production changes occur yet**: this document is design only.

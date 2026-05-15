# Google Places Operator Runbook

A short, opinionated walkthrough for running enrichment without burning budget or violating Google's terms.

## 0. One-time setup

1. **Enable billing on a Google Cloud project.** Places API (New) requires billing even when the call sits inside the free monthly credit.
2. In **APIs & Services → Library**, enable **Places API (New)** (not the legacy "Places API").
3. In **APIs & Services → Credentials**, create an **API key**. Restrict it:
   - **Application restrictions**: HTTP referrers are *not* useful for server-to-server. Choose **IP addresses** and add the production server IP(s).
   - **API restrictions**: limit to **Places API (New)** only.
4. Paste the key into `/admin/enrichment.html` → Source Settings → "Google Places API key", click **Save**.
5. Set sensible caps on the same page:
   - `Daily request cap`: start at 500. One Places search or Place Details call = 1 unit.
   - `Per-run request cap`: 100. One discovery job will halt at this many calls.
   - `Confidence threshold`: 0.7.
   - Boundary: leave `center_lat = 12.5684`, `center_lng = 99.9577`, `radius = 15000` (15 km around Hua Hin).
6. Verify by creating a small **Discovery Job** with `max_results = 20` and `search_query = "laundry in Hua Hin Thailand"`.

## 1. Field mask discipline

The integration always uses `X-Goog-FieldMask`. Two masks are defined in `src/enrichment/google-places.js`:

- **Search mask** (`SEARCH_FIELD_MASK`): id, displayName, formattedAddress, location, types, primaryType, businessStatus, websiteUri, phone numbers, googleMapsUri, rating, userRatingCount, regularOpeningHours, photos.name, nextPageToken.
- **Details mask** (`DETAILS_FIELD_MASK`): the same minus the listing wrapper plus `editorialSummary`.

**Why this matters:** Without a field mask, Places returns every available field, billed at the **Essentials + Pro + Enterprise** tier. With the masks above, every call is billed at the cheaper Essentials tier.

If you add a new field (e.g. `reviews`, `priceLevel`):

1. Check the [Pricing SKUs](https://developers.google.com/maps/documentation/places/web-service/usage-and-billing) for that field's tier.
2. Only add it if the operator UI actually displays it.
3. Update both `SEARCH_FIELD_MASK` and `DETAILS_FIELD_MASK` carefully — extra fields cost real money.

## 2. Daily routine

Recommended cadence: **one discovery job per category per week**, manually.

| Day | Job query | Category |
|---|---|---|
| Mon | "restaurants in Hua Hin Thailand" | restaurants |
| Tue | "laundry in Hua Hin Thailand" | laundry |
| Wed | "Thai massage in Hua Hin Thailand" | massage |
| Thu | "veterinary clinic in Hua Hin Thailand" | pet-services |
| Fri | "handyman services in Hua Hin Thailand" | handyman |
| Mon (alt) | "car rental in Hua Hin Thailand" | car-rental |
| Tue (alt) | "dental clinic in Hua Hin Thailand" | medical-dental |
| Wed (alt) | "visa lawyer in Hua Hin Thailand" | visa-legal |

For each job:

1. Create the job in **Discovery Jobs**.
2. Click **Run**. The runner streams paginated `searchText` results until either:
   - the per-run cap is hit,
   - the daily cap is hit,
   - the max_results value is reached, or
   - Google returns no `nextPageToken`.
3. For each found place, the runner also fetches the business's website (homepage + optionally one same-domain contact/about page) to extract verified social links.

## 3. Operator decisions

In **Candidate Review**:

- Use the DQ score to triage. Filter `Status = pending`.
- For obvious good matches: **Approve**, then **Promote** (creates a pending listing with admin notes).
- For dupes-of-existing-listings: open the **Conflict Review** tab and resolve each conflict row before approving.
- For maybes (low DQ, weird address, business_status `CLOSED_TEMPORARILY`, etc.): **Verify** — moves it to the verification queue.
- For spam/wrong-category: **Reject**.

In **Conflict Review**:

- For each row, pick a resolution and click **Resolve**. The chosen resolution is audit-logged and applied to the listing only if you chose `accept_new` (and you must then re-trigger a write — currently the conflicts UI records intent but does not automatically write to the listing; that is intentional, so you can stage many resolutions and apply them in one batch).

## 4. Quota & cost monitoring

The **Quota / Cost** tab shows today, last-7-days, and all-time counts per provider. Cross-reference this against the Google Cloud billing dashboard once a week.

The integration enforces the daily cap by counting rows in `api_usage WHERE created_at::date = CURRENT_DATE`. If you re-create the Postgres DB, the counter resets.

If the daily cap is reached mid-run, the job stops and writes `Quota stop: daily_cap` to `import_logs`.

## 5. Edge cases

- **Place returns no website**: candidate is still created, social fields stay empty. Operator can paste socials manually (set `provenance = operator-provided` server-side).
- **Website returns 403 / 404 / SSL error**: an `import_logs` warn row is written. The candidate keeps its phone/address from Places.
- **Website blocks our UA via robots.txt**: an `import_logs` info row says `robots_disallow`. We do not retry with a different UA.
- **Place returns lat/lng outside the Hua Hin radius**: the runner logs `Skipped: outside geo boundary` and does not create a candidate.
- **Two jobs with overlapping queries**: candidates are upserted on `(source, source_place_id)` so re-discovering the same place updates the row rather than creating a duplicate.

## 6. Updating the API key

To rotate the Google Places key:

1. Create a new key in Google Cloud with the same restrictions.
2. Paste it into the Settings page and save.
3. Wait 1 minute for in-flight calls to finish.
4. Delete the old key from Google Cloud.

The key is stored only in `settings.value` JSONB. Audit log records `settings.enrichment.updated` with `google_places_api_key:rotated` (not the value).

## 7. Disabling enrichment

Set `enrichment.daily_request_cap = 0` (UI: type 0) to halt all calls without removing the key.

To shut it down fully:

1. Set the key field blank in the UI and save.
2. The next discovery job run will fail with `No Google Places API key configured` and stop.

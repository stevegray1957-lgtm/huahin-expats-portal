'use strict';
const settings = require('../lib/settings');
const { query } = require('../../database');

const BASE = 'https://places.googleapis.com/v1';

const SEARCH_FIELD_MASK = [
  'places.id',
  'places.displayName',
  'places.formattedAddress',
  'places.location',
  'places.types',
  'places.primaryType',
  'places.businessStatus',
  'places.websiteUri',
  'places.nationalPhoneNumber',
  'places.internationalPhoneNumber',
  'places.googleMapsUri',
  'places.rating',
  'places.userRatingCount',
  'places.regularOpeningHours',
  'places.photos.name',
  'nextPageToken',
].join(',');

const DETAILS_FIELD_MASK = [
  'id','displayName','formattedAddress','location','types','primaryType','businessStatus',
  'websiteUri','nationalPhoneNumber','internationalPhoneNumber','googleMapsUri','rating',
  'userRatingCount','regularOpeningHours','photos.name','editorialSummary',
].join(',');

async function recordUsage(endpoint, jobId, units = 1) {
  await query('INSERT INTO api_usage (provider, endpoint, cost_units, job_id) VALUES ($1,$2,$3,$4)',
    ['google_places', endpoint, units, jobId || null]);
}

async function checkQuota(perRunCount, perRunCap, dailyCap) {
  if (perRunCount >= perRunCap) return { ok: false, reason: 'per_run_cap' };
  const r = await query(
    `SELECT COUNT(*)::int AS n FROM api_usage WHERE provider='google_places' AND created_at::date = CURRENT_DATE`
  );
  if (r.rows[0].n >= dailyCap) return { ok: false, reason: 'daily_cap' };
  return { ok: true };
}

async function textSearch({ query: q, locationBias, pageToken, jobId }) {
  const cfg = await settings.get('enrichment');
  if (!cfg.google_places_api_key) throw new Error('Google Places API key not configured');
  const body = { textQuery: q, pageSize: 20 };
  if (locationBias) body.locationBias = locationBias;
  if (pageToken) body.pageToken = pageToken;

  const res = await fetch(`${BASE}/places:searchText`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Goog-Api-Key': cfg.google_places_api_key,
      'X-Goog-FieldMask': SEARCH_FIELD_MASK,
    },
    body: JSON.stringify(body),
  });
  await recordUsage('places:searchText', jobId);
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = { raw: text }; }
  if (!res.ok) {
    const err = new Error(`Places searchText ${res.status}: ${text.slice(0,200)}`);
    err.status = res.status;
    throw err;
  }
  return data;
}

async function placeDetails(placeId, { jobId } = {}) {
  const cfg = await settings.get('enrichment');
  if (!cfg.google_places_api_key) throw new Error('Google Places API key not configured');
  const res = await fetch(`${BASE}/places/${encodeURIComponent(placeId)}`, {
    headers: {
      'X-Goog-Api-Key': cfg.google_places_api_key,
      'X-Goog-FieldMask': DETAILS_FIELD_MASK,
    },
  });
  await recordUsage('places:details', jobId);
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = { raw: text }; }
  if (!res.ok) throw new Error(`Places details ${res.status}: ${text.slice(0,200)}`);
  return data;
}

function normalisePlace(place) {
  return {
    source_place_id: place.id,
    business_name: place.displayName?.text || '',
    business_type: place.primaryType || (Array.isArray(place.types) ? place.types[0] : null),
    full_address: place.formattedAddress || null,
    phone: place.internationalPhoneNumber || place.nationalPhoneNumber || null,
    website_url: place.websiteUri || null,
    google_maps_url: place.googleMapsUri || null,
    latitude: place.location?.latitude || null,
    longitude: place.location?.longitude || null,
    opening_hours: place.regularOpeningHours || null,
    business_status: place.businessStatus || null,
    photo_reference: place.photos?.[0]?.name || null,
    rating: place.rating ?? null,
    rating_count: place.userRatingCount ?? null,
    types: place.types || [],
  };
}

module.exports = { textSearch, placeDetails, normalisePlace, recordUsage, checkQuota, SEARCH_FIELD_MASK, DETAILS_FIELD_MASK };

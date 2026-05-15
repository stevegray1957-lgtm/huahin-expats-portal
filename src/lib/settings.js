'use strict';
const { query } = require('../../database');

const DEFAULTS = {
  airwallex: {
    mode: 'sandbox',
    client_id: '',
    api_key: '',
    webhook_secret: '',
    account_currency: 'THB',
    success_url: '',
    cancel_url: '',
    enabled: false,
    tiers: {
      premium:      { label: 'Premium',      amount: 990,  currency: 'THB', duration_days: 365 },
      premium_plus: { label: 'Premium Plus', amount: 1990, currency: 'THB', duration_days: 365 },
      verified:     { label: 'Verified',     amount: 490,  currency: 'THB', duration_days: 365 },
      featured:     { label: 'Featured',     amount: 2990, currency: 'THB', duration_days: 365 },
    },
  },
  stripe: {
    enabled: false,
    note: 'Stripe was never implemented in this codebase. Kept here as a future fallback toggle.',
  },
  enrichment: {
    google_places_api_key: '',
    daily_request_cap: 500,
    per_run_request_cap: 100,
    allowed_categories: ['restaurants','laundry','massage','pet-services','handyman','car-rental','medical-dental','visa-legal'],
    excluded_categories: [],
    geo_boundary: { name: 'Hua Hin', center_lat: 12.5684, center_lng: 99.9577, radius_meters: 15000 },
    confidence_threshold: 0.7,
    auto_create_candidates: true,
    auto_publish: false,
    user_agent: 'HuaHinExpatsPortal/1.0 (+admin enrichment; contact admin)',
    website_fetch_timeout_ms: 8000,
    website_max_bytes: 500000,
  },
};

function maskSecret(value) {
  if (!value || typeof value !== 'string') return '';
  if (value.length <= 8) return '***';
  return value.slice(0, 4) + '…' + value.slice(-2);
}

async function getRaw(key) {
  const r = await query('SELECT value FROM settings WHERE key=$1', [key]);
  if (!r.rows.length) return structuredClone(DEFAULTS[key] || {});
  return { ...structuredClone(DEFAULTS[key] || {}), ...r.rows[0].value };
}

async function get(key) {
  return getRaw(key);
}

async function getRedacted(key) {
  const v = await getRaw(key);
  if (key === 'airwallex') {
    return { ...v, api_key: maskSecret(v.api_key), webhook_secret: maskSecret(v.webhook_secret) };
  }
  if (key === 'enrichment') {
    return { ...v, google_places_api_key: maskSecret(v.google_places_api_key) };
  }
  return v;
}

async function update(key, patch) {
  const existing = await getRaw(key);
  const merged = deepMerge(existing, patch);
  await query(
    `INSERT INTO settings (key, value, updated_at) VALUES ($1, $2, NOW())
     ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value, updated_at=NOW()`,
    [key, merged]
  );
  return merged;
}

function deepMerge(a, b) {
  if (Array.isArray(b)) return b;
  if (b === null || typeof b !== 'object') return b === undefined ? a : b;
  const out = { ...a };
  for (const k of Object.keys(b)) {
    out[k] = (a && typeof a[k] === 'object' && !Array.isArray(a[k])) ? deepMerge(a[k] || {}, b[k]) : b[k];
  }
  return out;
}

module.exports = { get, getRedacted, update, maskSecret, DEFAULTS };

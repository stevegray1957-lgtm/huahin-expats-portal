'use strict';
const settings = require('../lib/settings');

const BASE_URLS = {
  sandbox: 'https://api-demo.airwallex.com',
  live:    'https://api.airwallex.com',
};

let tokenCache = { token: null, expiresAt: 0, mode: null };

function baseUrl(mode) {
  return BASE_URLS[mode] || BASE_URLS.sandbox;
}

async function getConfig() {
  const cfg = await settings.get('airwallex');
  if (!cfg.client_id || !cfg.api_key) {
    throw new Error('Airwallex is not configured: missing client_id or api_key');
  }
  return cfg;
}

async function authenticate(force = false) {
  const cfg = await getConfig();
  const now = Date.now();
  if (!force && tokenCache.token && tokenCache.mode === cfg.mode && tokenCache.expiresAt > now + 30_000) {
    return tokenCache.token;
  }
  const res = await fetch(`${baseUrl(cfg.mode)}/api/v1/authentication/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-client-id': cfg.client_id,
      'x-api-key':   cfg.api_key,
    },
    body: '{}',
  });
  if (!res.ok) {
    const body = await res.text();
    throw new Error(`Airwallex auth failed (${res.status}): ${body.slice(0, 200)}`);
  }
  const data = await res.json();
  tokenCache = {
    token: data.token,
    expiresAt: now + 25 * 60 * 1000,
    mode: cfg.mode,
  };
  return data.token;
}

async function airwallexFetch(path, { method = 'GET', body } = {}) {
  const cfg = await getConfig();
  const token = await authenticate();
  const res = await fetch(`${baseUrl(cfg.mode)}${path}`, {
    method,
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let data;
  try { data = text ? JSON.parse(text) : {}; } catch { data = { raw: text }; }
  if (!res.ok) {
    const err = new Error(`Airwallex API ${method} ${path} failed (${res.status}): ${text.slice(0, 200)}`);
    err.status = res.status;
    err.body = data;
    throw err;
  }
  return data;
}

async function createPaymentLink({ amount, currency, title, description, reference, successUrl }) {
  if (!(amount > 0)) throw new Error('amount must be positive');
  if (!currency) throw new Error('currency required');
  const body = {
    amount,
    currency,
    reusable: false,
    title: title?.slice(0, 120) || 'Listing upgrade',
    description: description?.slice(0, 280),
    reference: reference?.slice(0, 64),
    metadata: { source: 'huahinexpats', reference },
    expires_at: new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString(),
  };
  if (successUrl) {
    body.collectable_shopper_info = { phone_number: false, shopper_name: false };
    body.return_url = successUrl;
  }
  const data = await airwallexFetch('/api/v1/pa/payment_links/create', { method: 'POST', body });
  return data;
}

async function getPaymentIntent(id) {
  return airwallexFetch(`/api/v1/pa/payment_intents/${encodeURIComponent(id)}`);
}

async function getPaymentLink(id) {
  return airwallexFetch(`/api/v1/pa/payment_links/${encodeURIComponent(id)}`);
}

function clearTokenCache() {
  tokenCache = { token: null, expiresAt: 0, mode: null };
}

module.exports = {
  authenticate, createPaymentLink, getPaymentIntent, getPaymentLink, clearTokenCache, baseUrl, BASE_URLS,
};

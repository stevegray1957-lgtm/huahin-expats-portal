'use strict';

// Integration test harness. Reproducible single-file test runner.
//
// Requires a live Postgres reachable via TEST_DATABASE_URL (or DATABASE_URL).
// Defaults: postgres://hhe:hhe@127.0.0.1:5432/hhe.
//
// Run:
//   sudo service postgresql start
//   sudo -u postgres psql -c "CREATE USER hhe WITH PASSWORD 'hhe' SUPERUSER;" || true
//   sudo -u postgres psql -c "CREATE DATABASE hhe OWNER hhe;" || true
//   npm test

process.env.DATABASE_URL = process.env.TEST_DATABASE_URL || process.env.DATABASE_URL || 'postgres://hhe:hhe@127.0.0.1:5432/hhe';
process.env.ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'testpw';
process.env.JWT_SECRET = process.env.JWT_SECRET || 'testjwt';
process.env.PORT = process.env.TEST_PORT || '0';

const http = require('http');
const crypto = require('crypto');
const db = require('../database');
const settings = require('../src/lib/settings');
const audit = require('../src/lib/audit');
const repo = require('../src/payments/repository');
const { applyUpgrade } = require('../src/payments/upgrade');
const { verifySignature } = require('../src/airwallex/webhook');
const duplicates = require('../src/enrichment/duplicates');
const candidates = require('../src/enrichment/candidates');
const conflicts = require('../src/enrichment/conflicts');
const places = require('../src/enrichment/google-places');

let passed = 0, failed = 0;

function eq(a, b, label) {
  const ok = JSON.stringify(a) === JSON.stringify(b);
  if (ok) { passed++; console.log('PASS -', label); }
  else { failed++; console.log('FAIL -', label, '\n  expected:', JSON.stringify(b), '\n  got:', JSON.stringify(a)); }
}
function truthy(v, label) {
  if (v) { passed++; console.log('PASS -', label); }
  else { failed++; console.log('FAIL -', label, '(got', JSON.stringify(v), ')'); }
}

async function httpReq(port, opts, body) {
  return new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port, ...opts }, (res) => {
      let buf = '';
      res.on('data', (c) => buf += c);
      res.on('end', () => resolve({ status: res.statusCode, body: buf, headers: res.headers }));
    });
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

async function main() {
  await db.initSchema();
  await db.query(`TRUNCATE listings, reviews, edit_suggestions, payments, payment_events, candidates, candidate_conflicts, discovery_jobs, import_logs, api_usage, settings, audit_log RESTART IDENTITY CASCADE`);

  await db.upsertListing({
    id: 'test-l1', category: 'Restaurants', category_slug: 'restaurants', name: 'Test Bistro',
    address: '1 Test St', phone_number: '+66 32 555 100', website_facebook_page: 'https://example.com',
  });

  // --- Settings round-trip and redaction ---
  await settings.update('airwallex', {
    client_id: 'cid_xxx', api_key: 'sk_test_supersecret123',
    webhook_secret: 'whsec_supersecret', enabled: true, mode: 'sandbox',
  });
  const red = await settings.getRedacted('airwallex');
  truthy(red.api_key.includes('…'), 'API key is redacted via getRedacted');
  truthy(red.webhook_secret.includes('…'), 'Webhook secret is redacted');
  truthy(!red.api_key.includes('supersecret'), 'Plain key not exposed in redacted view');
  eq((await settings.get('airwallex')).api_key, 'sk_test_supersecret123', 'Raw settings keep the actual key');

  // --- Payment lifecycle ---
  const p = await repo.createPending({
    listing_id: 'test-l1', owner_email: 'owner@example.com',
    tier: 'premium', amount: 990, currency: 'THB', provider: 'airwallex', mode: 'sandbox',
    metadata: { listing_name: 'Test Bistro' },
  });
  truthy(p.id && p.status === 'pending', 'Payment created in pending status');
  await repo.attachProvider(p.id, { provider_payment_id: 'int_abc', provider_link_id: 'lnk_xyz', hosted_url: 'https://checkout/x' });

  // --- Webhook signature verification ---
  const secret = 'whsec_supersecret';
  const eventBody = JSON.stringify({ id: 'evt_test_001', name: 'payment_intent.succeeded', data: { object: { id: 'int_abc', metadata: { reference: `hhe:p:${p.id}` } } } });
  const ts = Math.floor(Date.now() / 1000).toString();
  const sig = crypto.createHmac('sha256', secret).update(ts + eventBody).digest('hex');
  truthy(verifySignature({ timestamp: ts, signature: sig, rawBody: eventBody, secret }), 'Webhook signature verifies');
  truthy(!verifySignature({ timestamp: ts, signature: sig, rawBody: eventBody, secret: 'wrong' }), 'Wrong secret rejected');
  truthy(!verifySignature({ timestamp: '0', signature: sig, rawBody: eventBody, secret }), 'Old timestamp rejected');

  // --- Idempotency ---
  const e1 = await repo.recordEvent({ provider: 'airwallex', event_id: 'evt_test_001', event_type: 'payment_intent.succeeded', payment_id: p.id, payload: { id: 'evt_test_001' } });
  const e2 = await repo.recordEvent({ provider: 'airwallex', event_id: 'evt_test_001', event_type: 'payment_intent.succeeded', payment_id: p.id, payload: { id: 'evt_test_001' } });
  truthy(e1.inserted && !e2.inserted, 'Duplicate webhook event ignored (idempotency)');

  // --- Apply upgrade ---
  await repo.setStatus(p.id, 'succeeded', { eventId: 'evt_test_001' });
  await applyUpgrade({ payment: await repo.getById(p.id), source: 'webhook' });
  const listing = (await db.query('SELECT * FROM listings WHERE id=$1', ['test-l1'])).rows[0];
  truthy(listing.premium_level === 'premium', 'Listing premium_level set to premium after success');
  truthy(listing.premium_expires_at != null, 'Listing premium_expires_at populated');
  const initialExpiry = new Date(listing.premium_expires_at).getTime();

  // --- Failed payment must NOT upgrade ---
  const p2 = await repo.createPending({ listing_id: 'test-l1', tier: 'featured', amount: 2990, currency: 'THB' });
  await repo.setStatus(p2.id, 'failed');
  const l2 = (await db.query('SELECT * FROM listings WHERE id=$1', ['test-l1'])).rows[0];
  truthy(!l2.featured, 'Failed payment did not flip featured flag');

  // --- Successive successful upgrade extends expiry ---
  const p3 = await repo.createPending({ listing_id: 'test-l1', tier: 'premium', amount: 990, currency: 'THB' });
  await repo.setStatus(p3.id, 'succeeded');
  await applyUpgrade({ payment: await repo.getById(p3.id), source: 'test' });
  const l3 = (await db.query('SELECT * FROM listings WHERE id=$1', ['test-l1'])).rows[0];
  truthy(new Date(l3.premium_expires_at).getTime() > initialExpiry, 'Second upgrade extends premium expiry');

  // --- Duplicate detection ---
  await db.query(`UPDATE listings SET data = data || '{"google_place_id":"place_known"}'::jsonb WHERE id='test-l1'`);
  truthy((await duplicates.findDuplicate({ source_place_id: 'place_known', business_name: 'Anything Else' }))?.reason === 'place_id', 'Duplicate detected by place_id');
  truthy((await duplicates.findDuplicate({ business_name: 'Test Bistro' }))?.reason === 'exact_name', 'Duplicate detected by exact name');
  truthy((await duplicates.findDuplicate({ phone: '+66 32 555 100' }))?.reason === 'phone', 'Duplicate detected by phone');

  // --- Candidate creation + conflict detection ---
  const candResult = await candidates.upsertFromPlace({
    place: { id: 'place_new', displayName: { text: 'Fresh Cafe' }, formattedAddress: '99 New Rd', location: { latitude: 12.57, longitude: 99.96 }, primaryType: 'cafe', websiteUri: 'https://newcafe.test' },
    normalised: places.normalisePlace({
      id: 'place_new', displayName: { text: 'Fresh Cafe' }, formattedAddress: '99 New Rd',
      location: { latitude: 12.57, longitude: 99.96 }, primaryType: 'cafe', websiteUri: 'https://newcafe.test',
      internationalPhoneNumber: '+66 99 000', businessStatus: 'OPERATIONAL',
    }),
    category: 'restaurants', confidence: 0.8,
  });
  truthy(candResult.candidate.id, 'Candidate created');
  truthy(candResult.candidate.dq_score != null, 'DQ score populated');
  truthy(!candResult.duplicate, 'New place not flagged as duplicate of existing listing');

  const candDup = await candidates.upsertFromPlace({
    place: { id: 'place_dup', displayName: { text: 'Test Bistro' } },
    normalised: { source_place_id: 'place_dup', business_name: 'Test Bistro', phone: '+1 999 999 9999', website_url: 'https://different.com' },
    category: 'restaurants',
  });
  truthy(candDup.candidate.duplicate_match_listing_id === 'test-l1', 'Duplicate candidate matched to existing listing');
  truthy((await conflicts.listForCandidate(candDup.candidate.id)).length >= 1, 'Conflicts recorded for duplicate match');

  // --- Promotion gates ---
  await candidates.setApproval(candDup.candidate.id, 'approved', 'admin');
  let promoteErr = null;
  try { await candidates.promote(candDup.candidate.id, 'admin'); }
  catch (e) { promoteErr = e.message; }
  truthy(/duplicate/i.test(promoteErr || ''), 'Cannot promote a candidate with a duplicate match');

  await candidates.setApproval(candResult.candidate.id, 'approved', 'admin');
  const promoted = await candidates.promote(candResult.candidate.id, 'admin');
  truthy(promoted.listing_id, 'Promotion creates listing id');
  const newListing = (await db.query('SELECT * FROM listings WHERE id=$1', [promoted.listing_id])).rows[0];
  truthy(newListing && newListing.admin_notes && /Source:/.test(newListing.admin_notes), 'Promoted listing has admin_notes from source');

  // --- Audit log redaction ---
  await audit.log('test.redact', { actor: 'test', details: { api_key: 'must_not_leak', other: 'ok' } });
  const logs = await audit.list({ action: 'test.redact' });
  truthy(logs[0].details.api_key === '[redacted]', 'Audit log redacts api_key');
  truthy(logs[0].details.other === 'ok', 'Audit log preserves non-secret fields');

  // --- HTTP-level smoke tests ---
  const app = require('../server');
  await new Promise(r => setTimeout(r, 200));
  const srv = http.createServer(app).listen(0);
  await new Promise(r => srv.once('listening', r));
  const port = srv.address().port;

  // Server boot
  const homeResp = await httpReq(port, { method: 'GET', path: '/' });
  truthy(homeResp.status === 200 || homeResp.status === 304, 'Server boot: GET / returns 200 (got ' + homeResp.status + ')');

  // Admin pages (publicly available HTML, gated by JS-side auth)
  for (const path of ['/admin.html', '/admin/airwallex-settings.html', '/admin/payments.html', '/admin/enrichment.html', '/upgrade.html', '/upgrade-success.html', '/upgrade-cancel.html']) {
    const r = await httpReq(port, { method: 'GET', path });
    truthy(r.status === 200, `Static page reachable: ${path} (got ${r.status})`);
  }

  // Webhook: invalid signature ⇒ 401
  const badResp = await httpReq(port, { method: 'POST', path: '/api/webhooks/airwallex', headers: { 'content-type': 'application/json', 'x-timestamp': '1', 'x-signature': 'ff' } }, '{}');
  truthy(badResp.status === 401, 'Webhook rejects invalid signature with 401');

  // Webhook: valid signature ⇒ 200
  const newEventBody = JSON.stringify({ id: 'evt_http_1', name: 'payment_intent.failed', data: { object: { id: 'int_abc' } } });
  const ts2 = Math.floor(Date.now() / 1000).toString();
  const sig2 = crypto.createHmac('sha256', 'whsec_supersecret').update(ts2 + newEventBody).digest('hex');
  const goodResp = await httpReq(port, { method: 'POST', path: '/api/webhooks/airwallex', headers: { 'content-type': 'application/json', 'x-timestamp': ts2, 'x-signature': sig2, 'content-length': Buffer.byteLength(newEventBody) } }, newEventBody);
  truthy(goodResp.status === 200, 'Webhook accepts valid signature with 200');

  // Replay = duplicate flag
  const replay = await httpReq(port, { method: 'POST', path: '/api/webhooks/airwallex', headers: { 'content-type': 'application/json', 'x-timestamp': ts2, 'x-signature': sig2, 'content-length': Buffer.byteLength(newEventBody) } }, newEventBody);
  truthy(JSON.parse(replay.body || '{}').duplicate === true, 'Replayed webhook flagged as duplicate');

  // Public listings endpoint does not leak admin_notes
  const pub = await httpReq(port, { method: 'GET', path: '/api/listings/' + promoted.listing_id });
  const pubJson = JSON.parse(pub.body);
  truthy(!('admin_notes' in pubJson), 'Public listing API does not expose admin_notes column');
  truthy(!('owner_email' in pubJson), 'Public listing API does not expose owner_email column');

  // /api/payments/tiers requires Airwallex enabled (we set enabled=true above)
  const tiersResp = await httpReq(port, { method: 'GET', path: '/api/payments/tiers' });
  truthy(tiersResp.status === 200, '/api/payments/tiers returns 200 when enabled');
  truthy(JSON.parse(tiersResp.body).enabled === true, '/api/payments/tiers reports enabled=true');

  // Admin endpoints require admin token
  const unauth = await httpReq(port, { method: 'GET', path: '/api/admin/payments' });
  truthy(unauth.status === 401, 'Admin endpoint requires auth (got ' + unauth.status + ')');

  srv.close();
  await db.pool.end();

  console.log(`\n${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

main().catch(e => { console.error('FATAL:', e); process.exit(1); });

'use strict';
const { query } = require('../../database');

async function createPending({ listing_id, owner_email, tier, amount, currency, provider, mode, metadata }) {
  const r = await query(
    `INSERT INTO payments (listing_id, owner_email, tier, amount, currency, provider, mode, metadata, status)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,'pending')
     RETURNING *`,
    [listing_id, owner_email || null, tier, amount, currency, provider || 'airwallex', mode || 'sandbox', metadata || {}]
  );
  return r.rows[0];
}

async function attachProvider(id, { provider_payment_id, provider_link_id, hosted_url }) {
  const r = await query(
    `UPDATE payments
        SET provider_payment_id = COALESCE($2, provider_payment_id),
            provider_link_id    = COALESCE($3, provider_link_id),
            hosted_url          = COALESCE($4, hosted_url)
      WHERE id=$1 RETURNING *`,
    [id, provider_payment_id || null, provider_link_id || null, hosted_url || null]
  );
  return r.rows[0];
}

async function setStatus(id, status, { paidAt, eventId } = {}) {
  const r = await query(
    `UPDATE payments
        SET status=$2,
            paid_at = CASE WHEN $2='succeeded' AND paid_at IS NULL THEN COALESCE($3, NOW()) ELSE paid_at END,
            last_webhook_event = COALESCE($4, last_webhook_event)
      WHERE id=$1 RETURNING *`,
    [id, status, paidAt || null, eventId || null]
  );
  return r.rows[0];
}

async function markReviewed(id) {
  const r = await query(
    `UPDATE payments SET reviewed_at=NOW() WHERE id=$1 RETURNING *`,
    [id]
  );
  return r.rows[0];
}

async function getById(id) {
  const r = await query('SELECT * FROM payments WHERE id=$1', [id]);
  return r.rows[0] || null;
}

async function getByProviderPaymentId(provider, providerId) {
  const r = await query(
    'SELECT * FROM payments WHERE provider=$1 AND provider_payment_id=$2 ORDER BY id DESC LIMIT 1',
    [provider, providerId]
  );
  return r.rows[0] || null;
}

async function getByProviderLinkId(provider, linkId) {
  const r = await query(
    'SELECT * FROM payments WHERE provider=$1 AND provider_link_id=$2 ORDER BY id DESC LIMIT 1',
    [provider, linkId]
  );
  return r.rows[0] || null;
}

async function list({ status, listing_id, limit = 200 } = {}) {
  const where = [];
  const params = [];
  if (status)     { params.push(status);     where.push(`status=$${params.length}`); }
  if (listing_id) { params.push(listing_id); where.push(`listing_id=$${params.length}`); }
  const sql = `SELECT * FROM payments ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY created_at DESC LIMIT ${parseInt(limit) || 200}`;
  const r = await query(sql, params);
  return r.rows;
}

async function recordEvent({ provider, event_id, event_type, payment_id, payload }) {
  try {
    const r = await query(
      `INSERT INTO payment_events (provider, event_id, event_type, payment_id, payload)
       VALUES ($1,$2,$3,$4,$5)
       ON CONFLICT (provider, event_id) DO NOTHING
       RETURNING id`,
      [provider, event_id, event_type, payment_id || null, payload]
    );
    return { inserted: r.rowCount > 0, id: r.rows[0]?.id };
  } catch (e) {
    return { inserted: false, error: e.message };
  }
}

async function listEvents({ payment_id, limit = 100 } = {}) {
  if (payment_id) {
    const r = await query('SELECT * FROM payment_events WHERE payment_id=$1 ORDER BY id DESC LIMIT $2', [payment_id, limit]);
    return r.rows;
  }
  const r = await query('SELECT * FROM payment_events ORDER BY id DESC LIMIT $1', [limit]);
  return r.rows;
}

module.exports = {
  createPending, attachProvider, setStatus, markReviewed,
  getById, getByProviderPaymentId, getByProviderLinkId, list,
  recordEvent, listEvents,
};

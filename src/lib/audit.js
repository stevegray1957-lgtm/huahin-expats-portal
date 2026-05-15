'use strict';
const { query } = require('../../database');

function redact(details) {
  if (!details || typeof details !== 'object') return details;
  const SENSITIVE = ['api_key', 'apiKey', 'webhook_secret', 'webhookSecret', 'authorization', 'token', 'password'];
  const out = Array.isArray(details) ? [] : {};
  for (const k of Object.keys(details)) {
    if (SENSITIVE.includes(k)) {
      out[k] = '[redacted]';
    } else if (details[k] && typeof details[k] === 'object') {
      out[k] = redact(details[k]);
    } else {
      out[k] = details[k];
    }
  }
  return out;
}

async function log(action, { actor = null, target = null, details = {} } = {}) {
  try {
    await query(
      `INSERT INTO audit_log (action, actor, target, details) VALUES ($1,$2,$3,$4)`,
      [action, actor, target, redact(details)]
    );
  } catch (e) {
    console.error('audit log failed', action, e.message);
  }
}

async function list({ limit = 100, action, target } = {}) {
  const where = [];
  const params = [];
  if (action) { params.push(action); where.push(`action = $${params.length}`); }
  if (target) { params.push(target); where.push(`target = $${params.length}`); }
  const sql = `SELECT * FROM audit_log ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY created_at DESC LIMIT ${parseInt(limit) || 100}`;
  const r = await query(sql, params);
  return r.rows;
}

module.exports = { log, list, redact };

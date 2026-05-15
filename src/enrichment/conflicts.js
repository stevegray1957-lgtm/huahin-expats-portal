'use strict';
const { query } = require('../../database');

const COMPARED_FIELDS = [
  { field: 'phone',       candKey: 'phone',         listingKey: 'phone_number' },
  { field: 'website',     candKey: 'website_url',   listingKey: 'website_facebook_page' },
  { field: 'address',     candKey: 'full_address',  listingKey: 'address' },
  { field: 'opening_hours', candKey: 'opening_hours_text', listingKey: 'opening_hours' },
];

function flatten(v) {
  if (v == null) return '';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

function differs(a, b) {
  const na = flatten(a).trim().toLowerCase();
  const nb = flatten(b).trim().toLowerCase();
  if (!na || !nb) return false;
  if (na === nb) return false;
  if (na.includes(nb) || nb.includes(na)) return false;
  return true;
}

async function detect(candidate, listing) {
  if (!listing) return [];
  const conflicts = [];
  for (const { field, candKey, listingKey } of COMPARED_FIELDS) {
    const newVal = candidate[candKey];
    const oldVal = listing.data?.[listingKey];
    if (differs(oldVal, newVal)) {
      conflicts.push({ field, existing: flatten(oldVal), new: flatten(newVal) });
    }
  }
  return conflicts;
}

async function record(candidateId, listingId, source, conflicts) {
  for (const c of conflicts) {
    await query(
      `INSERT INTO candidate_conflicts (candidate_id, listing_id, field_name, existing_value, new_value, source)
       VALUES ($1,$2,$3,$4,$5,$6)`,
      [candidateId, listingId, c.field, c.existing, c.new, source]
    );
  }
  if (conflicts.length) {
    await query(`UPDATE candidates SET conflict_status='pending' WHERE id=$1`, [candidateId]);
  }
}

async function listForCandidate(candidateId) {
  const r = await query('SELECT * FROM candidate_conflicts WHERE candidate_id=$1 ORDER BY id', [candidateId]);
  return r.rows;
}

async function listPending({ limit = 100 } = {}) {
  const r = await query(`
    SELECT cc.*, c.business_name, c.source
      FROM candidate_conflicts cc
      JOIN candidates c ON c.id = cc.candidate_id
     WHERE cc.resolution IS NULL
     ORDER BY cc.created_at DESC LIMIT $1
  `, [limit]);
  return r.rows;
}

async function resolve(id, resolution) {
  const allowed = ['keep_existing','accept_new','store_as_alternate','ignore_source','flag_owner_verification'];
  if (!allowed.includes(resolution)) throw new Error(`Invalid resolution: ${resolution}`);
  const r = await query(
    `UPDATE candidate_conflicts SET resolution=$2, resolved_at=NOW() WHERE id=$1 RETURNING *`,
    [id, resolution]
  );
  return r.rows[0];
}

module.exports = { detect, record, listForCandidate, listPending, resolve, COMPARED_FIELDS };

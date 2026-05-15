'use strict';
const { query } = require('../../database');
const db = require('../../database');
const audit = require('../lib/audit');
const dq = require('./dq');
const duplicates = require('./duplicates');
const conflicts = require('./conflicts');

async function upsertFromPlace({ place, normalised, category, source = 'google_places', jobId, confidence = 0.8, raw }) {
  const existing = await query(
    `SELECT * FROM candidates WHERE source=$1 AND source_place_id=$2 LIMIT 1`,
    [source, normalised.source_place_id]
  );

  const draft = {
    source,
    source_place_id: normalised.source_place_id,
    source_url: normalised.google_maps_url,
    source_confidence: confidence,
    business_name: normalised.business_name,
    business_type: normalised.business_type,
    mapped_directory_category: category,
    full_address: normalised.full_address,
    phone: normalised.phone,
    website_url: normalised.website_url,
    google_maps_url: normalised.google_maps_url,
    latitude: normalised.latitude,
    longitude: normalised.longitude,
    opening_hours: normalised.opening_hours,
    business_status: normalised.business_status,
    photo_reference: normalised.photo_reference,
    raw_payload: raw || place,
    job_id: jobId,
  };

  const dup = await duplicates.findDuplicate(draft);
  if (dup) draft.duplicate_match_listing_id = dup.listing.id;

  const { score: s, breakdown } = dq.score(draft);
  draft.dq_score = s;
  draft.notes_internal = JSON.stringify({ dq_breakdown: breakdown, duplicate_reason: dup?.reason || null });

  let candidate;
  if (existing.rows.length) {
    const id = existing.rows[0].id;
    const r = await query(`
      UPDATE candidates SET
        source_url=$2, source_confidence=$3, business_name=$4, business_type=$5,
        mapped_directory_category=$6, full_address=$7, phone=$8, website_url=$9,
        google_maps_url=$10, latitude=$11, longitude=$12, opening_hours=$13,
        business_status=$14, photo_reference=$15, raw_payload=$16, job_id=$17,
        duplicate_match_listing_id=$18, dq_score=$19, notes_internal=$20,
        updated_at=NOW()
      WHERE id=$1
      RETURNING *`,
      [id, draft.source_url, draft.source_confidence, draft.business_name, draft.business_type,
       draft.mapped_directory_category, draft.full_address, draft.phone, draft.website_url,
       draft.google_maps_url, draft.latitude, draft.longitude, draft.opening_hours,
       draft.business_status, draft.photo_reference, draft.raw_payload, draft.job_id,
       draft.duplicate_match_listing_id, draft.dq_score, draft.notes_internal]
    );
    candidate = r.rows[0];
  } else {
    const r = await query(`
      INSERT INTO candidates (
        source, source_place_id, source_url, source_confidence, business_name, business_type,
        mapped_directory_category, full_address, phone, website_url, google_maps_url,
        latitude, longitude, opening_hours, business_status, photo_reference,
        raw_payload, job_id, duplicate_match_listing_id, dq_score, notes_internal
      ) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21)
      RETURNING *`,
      [draft.source, draft.source_place_id, draft.source_url, draft.source_confidence,
       draft.business_name, draft.business_type, draft.mapped_directory_category,
       draft.full_address, draft.phone, draft.website_url, draft.google_maps_url,
       draft.latitude, draft.longitude, draft.opening_hours, draft.business_status,
       draft.photo_reference, draft.raw_payload, draft.job_id,
       draft.duplicate_match_listing_id, draft.dq_score, draft.notes_internal]
    );
    candidate = r.rows[0];
  }

  if (dup) {
    const cf = await conflicts.detect(draft, dup.listing);
    if (cf.length) await conflicts.record(candidate.id, dup.listing.id, source, cf);
  }

  return { candidate, duplicate: dup, isNew: !existing.rows.length };
}

async function attachWebsiteEnrichment(candidateId, enrichment) {
  const set = {};
  if (enrichment.social?.facebook)  { set.facebook_url = enrichment.social.facebook;   set.facebook_url_provenance = 'verified-from-website'; }
  if (enrichment.social?.instagram) { set.instagram_url = enrichment.social.instagram; set.instagram_url_provenance = 'verified-from-website'; }
  if (enrichment.social?.tiktok)    { set.tiktok_url = enrichment.social.tiktok;       set.tiktok_url_provenance = 'verified-from-website'; }

  const keys = Object.keys(set);
  if (!keys.length) return null;

  const setSql = keys.map((k, i) => `${k}=$${i + 2}`).join(', ');
  const r = await query(
    `UPDATE candidates SET ${setSql}, updated_at=NOW() WHERE id=$1 RETURNING *`,
    [candidateId, ...keys.map(k => set[k])]
  );
  return r.rows[0];
}

async function setOperatorSocial(candidateId, { facebook, instagram, tiktok }) {
  const set = {};
  if (facebook !== undefined)  { set.facebook_url = facebook || null;   set.facebook_url_provenance = facebook ? 'operator-provided' : null; }
  if (instagram !== undefined) { set.instagram_url = instagram || null; set.instagram_url_provenance = instagram ? 'operator-provided' : null; }
  if (tiktok !== undefined)    { set.tiktok_url = tiktok || null;       set.tiktok_url_provenance = tiktok ? 'operator-provided' : null; }
  const keys = Object.keys(set);
  if (!keys.length) return null;
  const setSql = keys.map((k, i) => `${k}=$${i + 2}`).join(', ');
  const r = await query(
    `UPDATE candidates SET ${setSql}, updated_at=NOW() WHERE id=$1 RETURNING *`,
    [candidateId, ...keys.map(k => set[k])]
  );
  return r.rows[0];
}

async function list({ status, category, has_duplicate, limit = 100 } = {}) {
  const where = [];
  const params = [];
  if (status)    { params.push(status);    where.push(`approval_status=$${params.length}`); }
  if (category)  { params.push(category);  where.push(`mapped_directory_category=$${params.length}`); }
  if (has_duplicate === 'true')  where.push('duplicate_match_listing_id IS NOT NULL');
  if (has_duplicate === 'false') where.push('duplicate_match_listing_id IS NULL');
  const sql = `SELECT * FROM candidates ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY created_at DESC LIMIT ${parseInt(limit) || 100}`;
  const r = await query(sql, params);
  return r.rows;
}

async function getById(id) {
  const r = await query('SELECT * FROM candidates WHERE id=$1', [id]);
  return r.rows[0] || null;
}

async function setApproval(id, status, actor) {
  const allowed = ['pending','approved','rejected','merged','needs_verification'];
  if (!allowed.includes(status)) throw new Error(`Invalid approval status: ${status}`);
  const r = await query(`UPDATE candidates SET approval_status=$2, updated_at=NOW() WHERE id=$1 RETURNING *`, [id, status]);
  await audit.log(`candidate.${status}`, { actor: actor || 'admin', target: String(id), details: {} });
  return r.rows[0];
}

function listingIdFromCandidate(c) {
  const cat = c.mapped_directory_category || 'misc';
  return `${cat}-cand-${c.id}`;
}

async function promote(id, actor) {
  const c = await getById(id);
  if (!c) throw new Error('Candidate not found');
  if (c.approval_status !== 'approved') throw new Error('Candidate must be approved before promotion');
  if (c.duplicate_match_listing_id) {
    throw new Error('Candidate has a duplicate match — resolve in conflict review first');
  }

  const listingId = listingIdFromCandidate(c);
  const data = {
    address: c.full_address || null,
    google_maps_link: c.google_maps_url || null,
    phone_number: c.phone || null,
    website_facebook_page: c.website_url || c.facebook_url || null,
    facebook_page: c.facebook_url || null,
    instagram_page: c.instagram_url || null,
    tiktok_page: c.tiktok_url || null,
    opening_hours: c.opening_hours || null,
    latitude: c.latitude,
    longitude: c.longitude,
    google_place_id: c.source_place_id,
    business_status: c.business_status,
    services_offered: null,
    languages_spoken: null,
    price_range: null,
  };

  const adminNotes = `Source: ${c.source}; place_id=${c.source_place_id}; captured=${c.source_capture_date}; confidence=${c.source_confidence}; dq=${c.dq_score}`;

  await db.upsertListing({
    id: listingId,
    category: c.mapped_directory_category || 'Other',
    category_slug: c.mapped_directory_category || 'other',
    name: c.business_name,
    ...data,
  });
  await query(
    `UPDATE listings SET admin_notes=$2 WHERE id=$1`,
    [listingId, adminNotes]
  );
  if (c.public_note) {
    await query(
      `UPDATE listings SET data = data || jsonb_build_object('notes', $2::text) WHERE id=$1`,
      [listingId, c.public_note]
    );
  }

  await setApproval(id, 'merged', actor);
  await audit.log('candidate.promoted', {
    actor: actor || 'admin', target: listingId,
    details: { candidate_id: id, status: 'pending_review' },
  });

  return { listing_id: listingId, candidate_id: id };
}

module.exports = {
  upsertFromPlace, attachWebsiteEnrichment, setOperatorSocial,
  list, getById, setApproval, promote, listingIdFromCandidate,
};

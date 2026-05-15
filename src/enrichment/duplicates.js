'use strict';
const { query } = require('../../database');

function normalisePhone(s) {
  if (!s) return null;
  return String(s).replace(/[^\d]/g, '').replace(/^0+/, '').slice(-10);
}

function normaliseName(s) {
  if (!s) return '';
  return String(s).toLowerCase().replace(/[^\w\s]/g, ' ').replace(/\s+/g, ' ').trim();
}

function domainOf(url) {
  if (!url) return null;
  try { return new URL(url).hostname.toLowerCase().replace(/^www\./, ''); } catch { return null; }
}

function haversineMeters(lat1, lng1, lat2, lng2) {
  if ([lat1, lng1, lat2, lng2].some(v => v == null)) return Infinity;
  const toRad = (d) => d * Math.PI / 180;
  const R = 6371000;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng/2)**2;
  return 2 * R * Math.asin(Math.sqrt(a));
}

async function findDuplicate(candidate) {
  if (candidate.source_place_id) {
    const r = await query(
      `SELECT id, name, data FROM listings WHERE data->>'google_place_id' = $1 LIMIT 1`,
      [candidate.source_place_id]
    );
    if (r.rows.length) return { listing: r.rows[0], reason: 'place_id', score: 1.0 };
  }

  const normName = normaliseName(candidate.business_name);
  if (normName) {
    const r = await query(
      `SELECT id, name, data FROM listings WHERE LOWER(REGEXP_REPLACE(name, '[^a-zA-Z0-9 ]', '', 'g')) = $1 LIMIT 1`,
      [normName]
    );
    if (r.rows.length) return { listing: r.rows[0], reason: 'exact_name', score: 0.95 };
  }

  const phone = normalisePhone(candidate.phone);
  if (phone && phone.length >= 8) {
    const r = await query(
      `SELECT id, name, data FROM listings
        WHERE regexp_replace(COALESCE(data->>'phone_number',''), '[^0-9]', '', 'g') LIKE '%' || $1
        LIMIT 1`,
      [phone]
    );
    if (r.rows.length) return { listing: r.rows[0], reason: 'phone', score: 0.9 };
  }

  const dom = domainOf(candidate.website_url);
  if (dom) {
    const r = await query(
      `SELECT id, name, data FROM listings WHERE LOWER(COALESCE(data->>'website_facebook_page','')) LIKE '%' || $1 || '%' LIMIT 1`,
      [dom]
    );
    if (r.rows.length) return { listing: r.rows[0], reason: 'website_domain', score: 0.85 };
  }

  if (candidate.latitude && candidate.longitude && normName) {
    const r = await query(
      `SELECT id, name, data FROM listings WHERE (data->>'latitude') IS NOT NULL AND (data->>'longitude') IS NOT NULL`
    );
    for (const row of r.rows) {
      const lat = parseFloat(row.data.latitude);
      const lng = parseFloat(row.data.longitude);
      const d = haversineMeters(candidate.latitude, candidate.longitude, lat, lng);
      if (d < 80 && normaliseName(row.name).split(' ').some(t => normName.split(' ').includes(t) && t.length > 3)) {
        return { listing: row, reason: 'proximity', score: 0.8 };
      }
    }
  }

  return null;
}

module.exports = { findDuplicate, normalisePhone, normaliseName, domainOf, haversineMeters };

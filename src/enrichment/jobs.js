'use strict';
const { query } = require('../../database');
const settings = require('../lib/settings');
const audit = require('../lib/audit');
const places = require('./google-places');
const website = require('./website');
const candidates = require('./candidates');

async function logEntry(jobId, candidateId, level, message, details) {
  await query(
    `INSERT INTO import_logs (job_id, candidate_id, level, message, details) VALUES ($1,$2,$3,$4,$5)`,
    [jobId || null, candidateId || null, level, message, details || {}]
  );
}

async function create({ name, search_query, category_target, radius_meters, center_lat, center_lng, max_results, created_by }) {
  if (!name || !search_query) throw new Error('name and search_query are required');
  const cfg = await settings.get('enrichment');
  const r = await query(
    `INSERT INTO discovery_jobs
       (name, source, category_target, search_query, radius_meters, center_lat, center_lng, max_results, created_by)
     VALUES ($1,'google_places',$2,$3,$4,$5,$6,$7,$8)
     RETURNING *`,
    [
      name, category_target || null, search_query,
      radius_meters || cfg.geo_boundary?.radius_meters || 15000,
      center_lat ?? cfg.geo_boundary?.center_lat ?? 12.5684,
      center_lng ?? cfg.geo_boundary?.center_lng ?? 99.9577,
      Math.max(1, Math.min(parseInt(max_results, 10) || 60, 200)),
      created_by || 'admin',
    ]
  );
  await audit.log('discovery.job.created', { actor: created_by || 'admin', target: String(r.rows[0].id), details: { name, search_query, category_target } });
  return r.rows[0];
}

async function get(id) {
  const r = await query('SELECT * FROM discovery_jobs WHERE id=$1', [id]);
  return r.rows[0] || null;
}

async function list({ limit = 50 } = {}) {
  const r = await query('SELECT * FROM discovery_jobs ORDER BY created_at DESC LIMIT $1', [limit]);
  return r.rows;
}

async function setStatus(id, status, fields = {}) {
  const sets = [`status=$2`];
  const params = [id, status];
  for (const [k, v] of Object.entries(fields)) {
    params.push(v);
    sets.push(`${k}=$${params.length}`);
  }
  const r = await query(`UPDATE discovery_jobs SET ${sets.join(', ')} WHERE id=$1 RETURNING *`, params);
  return r.rows[0];
}

async function appendError(jobId, message, details) {
  await query(
    `UPDATE discovery_jobs
        SET errors = errors + 1,
            error_log = error_log || $2::jsonb
      WHERE id=$1`,
    [jobId, JSON.stringify([{ at: new Date().toISOString(), message, details: details || {} }])]
  );
}

async function run(jobId) {
  const job = await get(jobId);
  if (!job) throw new Error('Job not found');
  if (job.status === 'running') throw new Error('Job already running');

  const cfg = await settings.get('enrichment');
  if (!cfg.google_places_api_key) {
    await setStatus(jobId, 'failed', { finished_at: new Date() });
    await logEntry(jobId, null, 'error', 'No Google Places API key configured');
    throw new Error('Google Places API key not configured');
  }
  if (cfg.auto_publish) {
    await logEntry(jobId, null, 'warn', 'auto_publish is enabled in settings; enrichment will still mark candidates pending. Auto-publishing of candidates is not supported.');
  }

  await setStatus(jobId, 'running', { started_at: new Date() });
  await logEntry(jobId, null, 'info', 'Job started', { search_query: job.search_query, category: job.category_target });

  const locationBias = (job.center_lat && job.center_lng && job.radius_meters)
    ? { circle: { center: { latitude: parseFloat(job.center_lat), longitude: parseFloat(job.center_lng) }, radius: Math.min(50000, parseFloat(job.radius_meters)) } }
    : undefined;

  let total = 0, created = 0, duplicates = 0;
  let pageToken = null;
  let perRunCount = 0;

  try {
    while (perRunCount < cfg.per_run_request_cap && total < (job.max_results || 60)) {
      const quota = await places.checkQuota(perRunCount, cfg.per_run_request_cap, cfg.daily_request_cap);
      if (!quota.ok) {
        await logEntry(jobId, null, 'warn', `Quota stop: ${quota.reason}`);
        break;
      }

      let result;
      try {
        result = await places.textSearch({ query: job.search_query, locationBias, pageToken, jobId });
        perRunCount++;
      } catch (e) {
        await appendError(jobId, 'textSearch failed', { error: e.message });
        await logEntry(jobId, null, 'error', 'textSearch failed', { error: e.message });
        break;
      }

      const list = Array.isArray(result.places) ? result.places : [];
      total += list.length;

      for (const place of list) {
        try {
          const normalised = places.normalisePlace(place);
          if (!normalised.business_name) continue;
          if (!withinBoundary(normalised, job, cfg)) {
            await logEntry(jobId, null, 'info', 'Skipped: outside geo boundary', { place_id: normalised.source_place_id, name: normalised.business_name });
            continue;
          }
          const { candidate, duplicate, isNew } = await candidates.upsertFromPlace({
            place, normalised,
            category: job.category_target,
            jobId,
            confidence: cfg.confidence_threshold,
          });
          if (duplicate) duplicates++;
          if (isNew) created++;

          if (normalised.website_url) {
            try {
              const enrichment = await website.enrich(normalised.website_url);
              if (enrichment.ok) {
                await candidates.attachWebsiteEnrichment(candidate.id, enrichment);
                await logEntry(jobId, candidate.id, 'info', 'Website enrichment ok', { url: enrichment.source_url, social_found: enrichment.social });
              } else {
                await logEntry(jobId, candidate.id, 'info', 'Website enrichment skipped/failed', { reason: enrichment.error });
              }
            } catch (e) {
              await logEntry(jobId, candidate.id, 'warn', 'Website enrichment threw', { error: e.message });
            }
          }
        } catch (e) {
          await appendError(jobId, 'Per-place processing failed', { place_id: place.id, error: e.message });
          await logEntry(jobId, null, 'error', 'Per-place processing failed', { place_id: place.id, error: e.message });
        }
      }

      pageToken = result.nextPageToken;
      if (!pageToken) break;
    }

    await setStatus(jobId, 'done', {
      finished_at: new Date(),
      total_found: total,
      candidates_created: created,
      duplicates_found: duplicates,
    });
    await logEntry(jobId, null, 'info', 'Job complete', { total, created, duplicates });
  } catch (e) {
    await setStatus(jobId, 'failed', { finished_at: new Date() });
    await logEntry(jobId, null, 'error', 'Job failed', { error: e.message });
    throw e;
  }

  return { jobId, total, created, duplicates };
}

function withinBoundary(normalised, job, cfg) {
  if (normalised.latitude == null || normalised.longitude == null) return true;
  const b = cfg.geo_boundary || {};
  const cLat = job.center_lat ?? b.center_lat;
  const cLng = job.center_lng ?? b.center_lng;
  const r = (job.radius_meters || b.radius_meters || 15000) * 1.5;
  if (cLat == null || cLng == null) return true;
  const toRad = (d) => d * Math.PI / 180;
  const R = 6371000;
  const dLat = toRad(normalised.latitude - cLat);
  const dLng = toRad(normalised.longitude - cLng);
  const a = Math.sin(dLat/2)**2 + Math.cos(toRad(cLat)) * Math.cos(toRad(normalised.latitude)) * Math.sin(dLng/2)**2;
  const dist = 2 * R * Math.asin(Math.sqrt(a));
  return dist <= r;
}

async function logsForJob(jobId, { limit = 200 } = {}) {
  const r = await query('SELECT * FROM import_logs WHERE job_id=$1 ORDER BY created_at DESC LIMIT $2', [jobId, limit]);
  return r.rows;
}

async function recentLogs({ limit = 200 } = {}) {
  const r = await query('SELECT * FROM import_logs ORDER BY created_at DESC LIMIT $1', [limit]);
  return r.rows;
}

async function quotaUsage() {
  const r = await query(`
    SELECT provider,
           COUNT(*) FILTER (WHERE created_at::date = CURRENT_DATE) AS today,
           COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '7 days') AS last_7d,
           COUNT(*) AS total
      FROM api_usage GROUP BY provider`);
  return r.rows;
}

module.exports = { create, get, list, run, setStatus, logsForJob, recentLogs, quotaUsage };

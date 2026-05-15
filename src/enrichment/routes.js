'use strict';
const express = require('express');
const settings = require('../lib/settings');
const audit = require('../lib/audit');
const jobs = require('./jobs');
const candidates = require('./candidates');
const conflicts = require('./conflicts');

function attach(app, { requireAdmin }) {
  const r = express.Router();

  r.get('/enrichment/settings', requireAdmin, async (req, res) => {
    res.json(await settings.getRedacted('enrichment'));
  });

  r.put('/enrichment/settings', requireAdmin, async (req, res) => {
    try {
      const body = req.body || {};
      const patch = {};
      const allowed = ['daily_request_cap','per_run_request_cap','allowed_categories','excluded_categories',
                       'geo_boundary','confidence_threshold','auto_create_candidates','auto_publish',
                       'user_agent','website_fetch_timeout_ms','website_max_bytes'];
      for (const k of allowed) if (k in body) patch[k] = body[k];
      if (typeof body.google_places_api_key === 'string' && body.google_places_api_key.trim() && !body.google_places_api_key.includes('…')) {
        patch.google_places_api_key = body.google_places_api_key.trim();
      }
      if (patch.auto_publish === true) {
        return res.status(400).json({ error: 'auto_publish must be false; candidates require operator approval' });
      }
      const merged = await settings.update('enrichment', patch);
      await audit.log('settings.enrichment.updated', {
        actor: 'admin', target: 'enrichment',
        details: { keys: Object.keys(patch).map(k => k === 'google_places_api_key' ? 'google_places_api_key:rotated' : k) },
      });
      res.json(await settings.getRedacted('enrichment'));
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });

  r.get('/discovery-jobs', requireAdmin, async (req, res) => {
    res.json(await jobs.list({ limit: parseInt(req.query.limit) || 50 }));
  });

  r.post('/discovery-jobs', requireAdmin, async (req, res) => {
    try {
      const job = await jobs.create({ ...req.body, created_by: 'admin' });
      res.json(job);
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  r.post('/discovery-jobs/:id/run', requireAdmin, async (req, res) => {
    const id = parseInt(req.params.id, 10);
    const job = await jobs.get(id);
    if (!job) return res.status(404).json({ error: 'Not found' });
    res.json({ queued: true, id });
    setImmediate(async () => {
      try { await jobs.run(id); }
      catch (e) { console.error('Job', id, 'failed:', e.message); }
    });
  });

  r.get('/discovery-jobs/:id', requireAdmin, async (req, res) => {
    const id = parseInt(req.params.id, 10);
    const job = await jobs.get(id);
    if (!job) return res.status(404).json({ error: 'Not found' });
    const logs = await jobs.logsForJob(id, { limit: 200 });
    res.json({ ...job, logs });
  });

  r.get('/candidates', requireAdmin, async (req, res) => {
    res.json(await candidates.list({
      status: req.query.status,
      category: req.query.category,
      has_duplicate: req.query.has_duplicate,
      limit: parseInt(req.query.limit) || 100,
    }));
  });

  r.get('/candidates/:id', requireAdmin, async (req, res) => {
    const c = await candidates.getById(parseInt(req.params.id, 10));
    if (!c) return res.status(404).json({ error: 'Not found' });
    const cf = await conflicts.listForCandidate(c.id);
    res.json({ ...c, conflicts: cf });
  });

  r.post('/candidates/:id/approve', requireAdmin, async (req, res) => {
    try { res.json(await candidates.setApproval(parseInt(req.params.id, 10), 'approved', 'admin')); }
    catch (e) { res.status(400).json({ error: e.message }); }
  });

  r.post('/candidates/:id/reject', requireAdmin, async (req, res) => {
    try { res.json(await candidates.setApproval(parseInt(req.params.id, 10), 'rejected', 'admin')); }
    catch (e) { res.status(400).json({ error: e.message }); }
  });

  r.post('/candidates/:id/needs-verification', requireAdmin, async (req, res) => {
    try { res.json(await candidates.setApproval(parseInt(req.params.id, 10), 'needs_verification', 'admin')); }
    catch (e) { res.status(400).json({ error: e.message }); }
  });

  r.post('/candidates/:id/promote', requireAdmin, async (req, res) => {
    try { res.json(await candidates.promote(parseInt(req.params.id, 10), 'admin')); }
    catch (e) { res.status(400).json({ error: e.message }); }
  });

  r.post('/candidates/:id/social', requireAdmin, async (req, res) => {
    try {
      const id = parseInt(req.params.id, 10);
      const updated = await candidates.setOperatorSocial(id, req.body || {});
      await audit.log('candidate.social.updated', { actor: 'admin', target: String(id), details: { provenance: 'operator-provided' } });
      res.json(updated);
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  r.get('/conflicts', requireAdmin, async (req, res) => {
    res.json(await conflicts.listPending({ limit: parseInt(req.query.limit) || 100 }));
  });

  r.post('/conflicts/:id/resolve', requireAdmin, async (req, res) => {
    try {
      const id = parseInt(req.params.id, 10);
      const resolved = await conflicts.resolve(id, req.body?.resolution);
      await audit.log('conflict.resolved', { actor: 'admin', target: String(id), details: { resolution: req.body?.resolution } });
      res.json(resolved);
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  r.get('/enrichment/logs', requireAdmin, async (req, res) => {
    res.json(await jobs.recentLogs({ limit: parseInt(req.query.limit) || 200 }));
  });

  r.get('/enrichment/quota', requireAdmin, async (req, res) => {
    res.json(await jobs.quotaUsage());
  });

  r.get('/audit', requireAdmin, async (req, res) => {
    res.json(await audit.list({ limit: parseInt(req.query.limit) || 100, action: req.query.action, target: req.query.target }));
  });

  app.use('/api/admin', r);
}

module.exports = { attach };

'use strict';
const express = require('express');
const db = require('../../database');
const settings = require('../lib/settings');
const audit = require('../lib/audit');
const client = require('../airwallex/client');
const { verifySignature } = require('../airwallex/webhook');
const repo = require('./repository');
const { applyUpgrade, TIER_KEYS } = require('./upgrade');

function siteBaseUrl(req) {
  return process.env.SITE_URL || `${req.protocol}://${req.get('host')}`;
}

function emailLooksValid(s) {
  return typeof s === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s) && s.length <= 254;
}

function listingIdLooksValid(s) {
  return typeof s === 'string' && /^[a-zA-Z0-9_-]{1,80}$/.test(s);
}

function attach(app, { requireAdmin }) {
  const publicRouter = express.Router();
  const adminRouter = express.Router();

  publicRouter.get('/tiers', async (req, res) => {
    try {
      const cfg = await settings.get('airwallex');
      if (!cfg.enabled) return res.status(503).json({ error: 'Payments not enabled' });
      const out = {};
      for (const k of TIER_KEYS) {
        if (cfg.tiers[k]) out[k] = cfg.tiers[k];
      }
      res.json({ enabled: true, mode: cfg.mode, currency: cfg.account_currency, tiers: out });
    } catch (e) {
      res.status(500).json({ error: 'Server error' });
    }
  });

  publicRouter.post('/checkout', async (req, res) => {
    try {
      const { listing_id, tier, owner_email } = req.body || {};
      if (!listingIdLooksValid(listing_id)) return res.status(400).json({ error: 'Invalid listing_id' });
      if (!TIER_KEYS.includes(tier)) return res.status(400).json({ error: 'Invalid tier' });
      if (owner_email && !emailLooksValid(owner_email)) return res.status(400).json({ error: 'Invalid email' });

      const cfg = await settings.get('airwallex');
      if (!cfg.enabled) return res.status(503).json({ error: 'Payments not enabled' });

      const listing = await db.getListingById(listing_id);
      if (!listing) return res.status(404).json({ error: 'Listing not found' });

      const tierCfg = cfg.tiers[tier];
      if (!tierCfg) return res.status(400).json({ error: 'Tier not configured' });

      const amount = Number(tierCfg.amount);
      const currency = tierCfg.currency || cfg.account_currency || 'THB';
      if (!(amount > 0)) return res.status(400).json({ error: 'Tier amount is zero' });

      const payment = await repo.createPending({
        listing_id, owner_email, tier, amount, currency,
        provider: 'airwallex', mode: cfg.mode,
        metadata: { listing_name: listing.name, requested_via: 'public' },
      });

      const successUrl = cfg.success_url || `${siteBaseUrl(req)}/upgrade-success.html`;
      let link;
      try {
        link = await client.createPaymentLink({
          amount,
          currency,
          title: `${tierCfg.label || tier} — ${listing.name}`,
          description: `Listing upgrade for ${listing.name}`,
          reference: `hhe:p:${payment.id}`,
          successUrl,
        });
      } catch (e) {
        await repo.setStatus(payment.id, 'failed');
        await audit.log('payment.link.create_failed', {
          actor: 'public', target: listing_id,
          details: { payment_id: payment.id, error: e.message },
        });
        return res.status(502).json({ error: 'Payment provider error' });
      }

      const updated = await repo.attachProvider(payment.id, {
        provider_link_id: link.id || null,
        hosted_url: link.url || null,
      });

      await audit.log('payment.link.created', {
        actor: 'public', target: listing_id,
        details: { payment_id: payment.id, tier, amount, currency, provider_link_id: link.id },
      });

      res.json({ payment_id: payment.id, hosted_url: updated.hosted_url, link_id: updated.provider_link_id });
    } catch (e) {
      console.error('checkout error', e);
      res.status(500).json({ error: 'Server error' });
    }
  });

  publicRouter.get('/status/:id', async (req, res) => {
    const id = parseInt(req.params.id, 10);
    if (!id) return res.status(400).json({ error: 'Invalid id' });
    const p = await repo.getById(id);
    if (!p) return res.status(404).json({ error: 'Not found' });
    res.json({ id: p.id, status: p.status, tier: p.tier, listing_id: p.listing_id });
  });

  adminRouter.get('/airwallex-settings', requireAdmin, async (req, res) => {
    res.json(await settings.getRedacted('airwallex'));
  });

  adminRouter.put('/airwallex-settings', requireAdmin, async (req, res) => {
    try {
      const body = req.body || {};
      const patch = {};
      const allowed = ['mode','client_id','account_currency','success_url','cancel_url','enabled','tiers'];
      for (const k of allowed) if (k in body) patch[k] = body[k];
      if (typeof body.api_key === 'string' && body.api_key.trim() && !body.api_key.includes('…')) {
        patch.api_key = body.api_key.trim();
      }
      if (typeof body.webhook_secret === 'string' && body.webhook_secret.trim() && !body.webhook_secret.includes('…')) {
        patch.webhook_secret = body.webhook_secret.trim();
      }
      if (patch.mode && !['sandbox','live'].includes(patch.mode)) {
        return res.status(400).json({ error: 'mode must be sandbox or live' });
      }
      const merged = await settings.update('airwallex', patch);
      client.clearTokenCache();
      await audit.log('settings.airwallex.updated', {
        actor: 'admin', target: 'airwallex',
        details: { keys: Object.keys(patch).filter(k => k !== 'api_key' && k !== 'webhook_secret').concat(
          Object.keys(patch).filter(k => k === 'api_key' || k === 'webhook_secret').map(k => `${k}:rotated`)
        ) },
      });
      res.json(await settings.getRedacted('airwallex'));
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });

  adminRouter.post('/airwallex-settings/test', requireAdmin, async (req, res) => {
    try {
      await client.authenticate(true);
      res.json({ ok: true });
    } catch (e) {
      res.status(400).json({ ok: false, error: e.message });
    }
  });

  adminRouter.get('/airwallex-settings/webhook-url', requireAdmin, (req, res) => {
    res.json({ url: `${siteBaseUrl(req)}/api/webhooks/airwallex` });
  });

  adminRouter.get('/payments', requireAdmin, async (req, res) => {
    const rows = await repo.list({ status: req.query.status, listing_id: req.query.listing_id });
    res.json(rows);
  });

  adminRouter.get('/payments/:id', requireAdmin, async (req, res) => {
    const id = parseInt(req.params.id, 10);
    const p = await repo.getById(id);
    if (!p) return res.status(404).json({ error: 'Not found' });
    const events = await repo.listEvents({ payment_id: id });
    res.json({ ...p, events });
  });

  adminRouter.post('/payments/:id/mark-reviewed', requireAdmin, async (req, res) => {
    const id = parseInt(req.params.id, 10);
    const updated = await repo.markReviewed(id);
    if (!updated) return res.status(404).json({ error: 'Not found' });
    await audit.log('payment.reviewed', { actor: 'admin', target: String(id), details: {} });
    res.json(updated);
  });

  adminRouter.post('/payments/:id/retry-sync', requireAdmin, async (req, res) => {
    const id = parseInt(req.params.id, 10);
    const p = await repo.getById(id);
    if (!p) return res.status(404).json({ error: 'Not found' });
    if (!p.provider_payment_id && !p.provider_link_id) {
      return res.status(400).json({ error: 'No provider id to sync' });
    }
    try {
      let intent = null;
      if (p.provider_payment_id) intent = await client.getPaymentIntent(p.provider_payment_id);
      else if (p.provider_link_id) intent = await client.getPaymentLink(p.provider_link_id);

      const remoteStatus = intent?.status?.toLowerCase?.() || '';
      let newStatus = p.status;
      if (remoteStatus === 'succeeded') newStatus = 'succeeded';
      else if (['failed','requires_payment_method','cancelled','canceled'].includes(remoteStatus)) newStatus = remoteStatus === 'failed' ? 'failed' : 'cancelled';
      else if (remoteStatus === 'expired') newStatus = 'expired';
      const updated = await repo.setStatus(id, newStatus);
      if (newStatus === 'succeeded' && p.status !== 'succeeded') {
        await applyUpgrade({ payment: updated, source: 'manual_sync' });
      }
      await audit.log('payment.sync', { actor: 'admin', target: String(id), details: { remote: intent?.status, applied: newStatus } });
      res.json({ ok: true, payment: updated, remote: intent });
    } catch (e) {
      res.status(502).json({ error: e.message });
    }
  });

  app.use('/api/payments', publicRouter);
  app.use('/api/admin', adminRouter);
}

async function handleAirwallexWebhook(req, res) {
  const rawBody = req.rawBody || '';
  const timestamp = req.headers['x-timestamp'] || req.headers['x-airwallex-timestamp'];
  const signature = req.headers['x-signature'] || req.headers['x-airwallex-signature'];

  let cfg;
  try { cfg = await settings.get('airwallex'); }
  catch { return res.status(503).json({ error: 'Not configured' }); }

  if (!cfg.webhook_secret) {
    await audit.log('webhook.airwallex.misconfigured', { actor: 'webhook', details: {} });
    return res.status(503).json({ error: 'Webhook secret not configured' });
  }

  if (!verifySignature({ timestamp, signature, rawBody, secret: cfg.webhook_secret })) {
    await audit.log('webhook.airwallex.invalid_signature', {
      actor: 'webhook',
      details: { has_ts: !!timestamp, has_sig: !!signature, body_len: rawBody.length },
    });
    return res.status(401).json({ error: 'Invalid signature' });
  }

  let event;
  try { event = JSON.parse(rawBody); }
  catch { return res.status(400).json({ error: 'Invalid JSON' }); }

  const eventId = event.id || event.event_id || `${event.name || event.event}::${event.created_at || Date.now()}`;
  const eventType = event.name || event.type || event.event || 'unknown';
  const data = event.data?.object || event.data || event;

  const providerPaymentId = data.payment_intent_id || data.id;
  const providerLinkId = data.payment_link_id || (data.object_type === 'payment_link' ? data.id : null);

  let payment = null;
  if (providerLinkId) payment = await repo.getByProviderLinkId('airwallex', providerLinkId);
  if (!payment && providerPaymentId) payment = await repo.getByProviderPaymentId('airwallex', providerPaymentId);
  if (!payment && data.metadata?.reference?.startsWith('hhe:p:')) {
    const id = parseInt(data.metadata.reference.split(':')[2], 10);
    if (id) payment = await repo.getById(id);
  }

  const recorded = await repo.recordEvent({
    provider: 'airwallex',
    event_id: eventId,
    event_type: eventType,
    payment_id: payment?.id,
    payload: event,
  });

  if (!recorded.inserted) {
    return res.json({ ok: true, duplicate: true });
  }

  if (!payment) {
    await audit.log('webhook.airwallex.no_match', {
      actor: 'webhook',
      details: { event_type: eventType, event_id: eventId },
    });
    return res.json({ ok: true, matched: false });
  }

  if (payment.provider_payment_id == null && providerPaymentId) {
    payment = await repo.attachProvider(payment.id, { provider_payment_id: providerPaymentId });
  }

  let newStatus = null;
  if (/succeeded$/i.test(eventType) || /\.paid$/i.test(eventType)) newStatus = 'succeeded';
  else if (/failed$/i.test(eventType)) newStatus = 'failed';
  else if (/cancel/i.test(eventType)) newStatus = 'cancelled';
  else if (/expired$/i.test(eventType)) newStatus = 'expired';
  else if (/refund/i.test(eventType)) newStatus = 'refunded';

  if (!newStatus) {
    await audit.log('webhook.airwallex.unhandled_type', { actor: 'webhook', target: String(payment.id), details: { event_type: eventType } });
    return res.json({ ok: true, matched: true, unhandled: true });
  }

  if (payment.status === 'succeeded' && newStatus === 'succeeded') {
    return res.json({ ok: true, matched: true, alreadyApplied: true });
  }

  const updated = await repo.setStatus(payment.id, newStatus, { eventId });
  await audit.log(`webhook.airwallex.${newStatus}`, {
    actor: 'webhook',
    target: String(payment.id),
    details: { event_id: eventId, event_type: eventType, listing_id: payment.listing_id },
  });

  if (newStatus === 'succeeded' && payment.status !== 'succeeded') {
    try {
      await applyUpgrade({ payment: updated, source: 'webhook' });
    } catch (e) {
      await audit.log('webhook.airwallex.apply_failed', {
        actor: 'webhook', target: String(payment.id),
        details: { error: e.message },
      });
      return res.status(500).json({ error: 'Upgrade apply failed' });
    }
  }

  res.json({ ok: true, matched: true, status: newStatus });
}

module.exports = { attach, handleAirwallexWebhook };

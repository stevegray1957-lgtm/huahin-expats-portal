'use strict';
const { query } = require('../../database');
const settings = require('../lib/settings');
const audit = require('../lib/audit');

const TIER_KEYS = ['premium', 'premium_plus', 'verified', 'featured'];

async function tierConfig(tier) {
  const cfg = await settings.get('airwallex');
  const t = cfg.tiers?.[tier];
  if (!t) throw new Error(`Unknown tier: ${tier}`);
  return t;
}

function tierLevel(tier) {
  if (tier === 'premium_plus' || tier === 'featured') return 'premium_plus';
  if (tier === 'premium') return 'premium';
  return null;
}

async function applyUpgrade({ payment, source }) {
  if (payment.status !== 'succeeded') {
    throw new Error('applyUpgrade requires payment.status=succeeded');
  }
  const cfg = await tierConfig(payment.tier);
  const days = parseInt(cfg.duration_days, 10) || 365;
  const now = new Date();
  const existing = await query('SELECT premium_expires_at, premium_level, verified, featured FROM listings WHERE id=$1', [payment.listing_id]);
  if (!existing.rows.length) {
    throw new Error(`Listing not found: ${payment.listing_id}`);
  }
  const cur = existing.rows[0];
  const curExp = cur.premium_expires_at ? new Date(cur.premium_expires_at) : null;
  const base = curExp && curExp > now ? curExp : now;
  const newExp = new Date(base.getTime() + days * 24 * 60 * 60 * 1000);

  const level = tierLevel(payment.tier);
  const setVerified = payment.tier === 'verified' || payment.tier === 'premium_plus' || payment.tier === 'featured' ? true : cur.verified;
  const setFeatured = payment.tier === 'featured' ? true : cur.featured;

  await query(
    `UPDATE listings
        SET premium_level     = COALESCE($2, premium_level),
            premium_expires_at = $3,
            verified           = $4,
            featured           = $5
      WHERE id=$1`,
    [payment.listing_id, level, newExp.toISOString(), setVerified, setFeatured]
  );

  await audit.log('listing.upgrade.applied', {
    actor: source || 'webhook',
    target: payment.listing_id,
    details: {
      payment_id: payment.id,
      tier: payment.tier,
      premium_level: level,
      expires_at: newExp.toISOString(),
      verified: setVerified,
      featured: setFeatured,
    },
  });

  return { listing_id: payment.listing_id, premium_level: level, premium_expires_at: newExp, verified: setVerified, featured: setFeatured };
}

module.exports = { applyUpgrade, tierConfig, TIER_KEYS, tierLevel };

'use strict';
const crypto = require('crypto');

const MAX_AGE_SECONDS = 5 * 60;

function timingSafeEqualHex(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string') return false;
  if (a.length !== b.length) return false;
  try {
    return crypto.timingSafeEqual(Buffer.from(a, 'hex'), Buffer.from(b, 'hex'));
  } catch {
    return false;
  }
}

function verifySignature({ timestamp, signature, rawBody, secret }) {
  if (!timestamp || !signature || !rawBody || !secret) return false;
  const ts = parseInt(timestamp, 10);
  if (!Number.isFinite(ts)) return false;
  const nowSec = Math.floor(Date.now() / 1000);
  const tsSec = ts > 1e12 ? Math.floor(ts / 1000) : ts;
  if (Math.abs(nowSec - tsSec) > MAX_AGE_SECONDS) return false;
  const signed = `${timestamp}${rawBody}`;
  const expected = crypto.createHmac('sha256', secret).update(signed).digest('hex');
  return timingSafeEqualHex(expected, signature);
}

module.exports = { verifySignature, MAX_AGE_SECONDS };

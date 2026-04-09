'use strict';
require('dotenv').config();
const express = require('express');
const path = require('path');
const jwt = require('jsonwebtoken');
const rateLimit = require('express-rate-limit');
const db = require('./database');

const app = express();
const PORT = process.env.PORT || 3000;
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'admin';
const JWT_SECRET = process.env.JWT_SECRET || process.env.SESSION_SECRET || 'change-me';

// ─── Middleware ───────────────────────────────────────────────────────────────
app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));

function getIp(req) {
  return req.headers['x-forwarded-for']?.split(',')[0].trim() || req.socket?.remoteAddress || 'unknown';
}

const submitLimiter = rateLimit({
  windowMs: 60 * 60 * 1000,
  max: 3,
  keyGenerator: getIp,
  message: { error: 'Too many submissions. Please try again later.' },
  standardHeaders: true,
  legacyHeaders: false,
});

function requireAdmin(req, res, next) {
  const token = req.headers['x-admin-token'];
  if (!token) return res.status(401).json({ error: 'Unauthorized' });
  try {
    jwt.verify(token, JWT_SECRET);
    next();
  } catch {
    res.status(401).json({ error: 'Session expired. Please sign in again.' });
  }
}

// ─── Listings ─────────────────────────────────────────────────────────────────

app.get('/api/listings', async (req, res) => {
  try {
    const { category, q } = req.query;
    let results;
    if (q?.trim()) {
      results = await db.searchListings(q.trim());
      if (category) results = results.filter(l => l.category_slug === category);
    } else if (category) {
      results = await db.getListingsByCategory(category);
    } else {
      results = await db.getAllListings();
    }
    res.json(results);
  } catch (e) {
    console.error(e);
    res.status(500).json({ error: 'Server error' });
  }
});

app.get('/api/listings/categories', async (req, res) => {
  try {
    res.json(await db.getCategories());
  } catch (e) {
    res.status(500).json({ error: 'Server error' });
  }
});

app.get('/api/listings/:id', async (req, res) => {
  try {
    const listing = await db.getListingById(req.params.id);
    if (!listing) return res.status(404).json({ error: 'Not found' });
    res.json(listing);
  } catch (e) {
    res.status(500).json({ error: 'Server error' });
  }
});

// ─── Reviews ──────────────────────────────────────────────────────────────────

app.post('/api/reviews/submit', submitLimiter, async (req, res) => {
  try {
    const { listing_id, listing_name, category, reviewer_name, rating, review_text } = req.body;
    if (!listing_id || !listing_name || !reviewer_name || !rating || !review_text)
      return res.status(400).json({ error: 'All fields are required.' });
    if (review_text.length < 10) return res.status(400).json({ error: 'Review must be at least 10 characters.' });
    if (review_text.length > 1000) return res.status(400).json({ error: 'Review must be 1000 characters or fewer.' });
    const r = parseInt(rating);
    if (!Number.isInteger(r) || r < 1 || r > 5) return res.status(400).json({ error: 'Rating must be 1–5.' });
    await db.submitReview({
      listing_id, listing_name, category: category || '',
      reviewer_name: reviewer_name.trim().substring(0, 100),
      rating: r, review_text: review_text.trim(), reviewer_ip: getIp(req),
    });
    res.json({ success: true, message: 'Thank you! Your review has been submitted and will appear once approved.' });
  } catch (e) {
    console.error(e);
    res.status(500).json({ error: 'Server error' });
  }
});

app.get('/api/reviews/approved', async (req, res) => {
  try {
    const { listing_id } = req.query;
    if (!listing_id) return res.status(400).json({ error: 'listing_id required' });
    res.json(await db.getApprovedReviews(listing_id));
  } catch (e) {
    res.status(500).json({ error: 'Server error' });
  }
});

app.get('/api/reviews/avg', async (req, res) => {
  try {
    const { listing_id } = req.query;
    if (!listing_id) return res.status(400).json({ error: 'listing_id required' });
    res.json(await db.getAvgRating(listing_id));
  } catch (e) {
    res.status(500).json({ error: 'Server error' });
  }
});

// ─── Edit Suggestions ─────────────────────────────────────────────────────────

app.post('/api/edits/submit', submitLimiter, async (req, res) => {
  try {
    const { listing_id, listing_name, submitter_name, submitter_email, edit_description } = req.body;
    if (!listing_id || !listing_name || !edit_description)
      return res.status(400).json({ error: 'listing_id, listing_name and edit_description are required.' });
    if (edit_description.length < 5) return res.status(400).json({ error: 'Please describe the edit in more detail.' });
    await db.submitEdit({
      listing_id, listing_name, submitter_name, submitter_email,
      edit_description: edit_description.trim().substring(0, 2000), submitter_ip: getIp(req),
    });
    res.json({ success: true, message: 'Thank you! Your suggestion has been submitted.' });
  } catch (e) {
    console.error(e);
    res.status(500).json({ error: 'Server error' });
  }
});

// ─── Admin Auth (JWT) ─────────────────────────────────────────────────────────

app.post('/api/admin/auth', (req, res) => {
  const { password } = req.body;
  if (!password || password !== ADMIN_PASSWORD)
    return res.status(401).json({ error: 'Invalid password.' });
  const token = jwt.sign({ role: 'admin' }, JWT_SECRET, { expiresIn: '8h' });
  res.json({ token });
});

// ─── Admin Reviews ────────────────────────────────────────────────────────────

app.get('/api/admin/reviews/pending', requireAdmin, async (req, res) => {
  try { res.json(await db.getPendingReviews()); } catch (e) { res.status(500).json({ error: 'Server error' }); }
});

app.post('/api/admin/reviews/approve/:id', requireAdmin, async (req, res) => {
  try { await db.approveReview(parseInt(req.params.id)); res.json({ success: true }); }
  catch (e) { res.status(500).json({ error: 'Server error' }); }
});

app.post('/api/admin/reviews/reject/:id', requireAdmin, async (req, res) => {
  try { await db.rejectReview(parseInt(req.params.id)); res.json({ success: true }); }
  catch (e) { res.status(500).json({ error: 'Server error' }); }
});

app.get('/api/admin/reviews/published', requireAdmin, async (req, res) => {
  try { res.json(await db.getPublishedReviews(req.query.search)); }
  catch (e) { res.status(500).json({ error: 'Server error' }); }
});

app.delete('/api/admin/reviews/:id', requireAdmin, async (req, res) => {
  try { await db.deleteReview(parseInt(req.params.id)); res.json({ success: true }); }
  catch (e) { res.status(500).json({ error: 'Server error' }); }
});

// ─── Admin Edits ──────────────────────────────────────────────────────────────

app.get('/api/admin/edits/pending', requireAdmin, async (req, res) => {
  try { res.json(await db.getPendingEdits()); }
  catch (e) { res.status(500).json({ error: 'Server error' }); }
});

app.post('/api/admin/edits/resolve/:id', requireAdmin, async (req, res) => {
  try {
    const { action } = req.body;
    if (!['done', 'dismissed'].includes(action))
      return res.status(400).json({ error: "action must be 'done' or 'dismissed'" });
    await db.resolveEdit(parseInt(req.params.id), action);
    res.json({ success: true });
  } catch (e) { res.status(500).json({ error: 'Server error' }); }
});

// ─── 404 fallback ─────────────────────────────────────────────────────────────

app.use((req, res) => {
  if (req.path.startsWith('/api/')) return res.status(404).json({ error: 'Not found' });
  res.sendFile(path.join(__dirname, 'public', '404.html'));
});

// ─── Start ────────────────────────────────────────────────────────────────────

async function start() {
  await db.initSchema();
  app.listen(PORT, () => {
    console.log(`\n🌏 HuaHin ExpatsPortal running at http://localhost:${PORT}`);
    console.log(`   Admin panel: http://localhost:${PORT}/admin.html\n`);
  });
}

start().catch(err => { console.error('Failed to start:', err); process.exit(1); });

module.exports = app; // needed for Vercel

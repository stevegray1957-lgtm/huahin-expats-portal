'use strict';
const { Pool } = require('pg');

const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: process.env.NODE_ENV === 'production' ? { rejectUnauthorized: false } : false,
});

async function query(text, params) {
  const client = await pool.connect();
  try {
    return await client.query(text, params);
  } finally {
    client.release();
  }
}

// ─── Schema init ─────────────────────────────────────────────────────────────

async function initSchema() {
  await query(`
    CREATE TABLE IF NOT EXISTS listings (
      id            TEXT PRIMARY KEY,
      category      TEXT NOT NULL,
      category_slug TEXT NOT NULL,
      name          TEXT NOT NULL,
      data          JSONB NOT NULL DEFAULT '{}'
    );
    CREATE INDEX IF NOT EXISTS idx_listings_category ON listings(category_slug);
    CREATE INDEX IF NOT EXISTS idx_listings_name ON listings(name);

    CREATE TABLE IF NOT EXISTS reviews (
      id            SERIAL PRIMARY KEY,
      listing_id    TEXT NOT NULL,
      listing_name  TEXT NOT NULL,
      category      TEXT NOT NULL,
      reviewer_name TEXT NOT NULL,
      rating        INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5),
      review_text   TEXT NOT NULL,
      status        TEXT DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
      reviewer_ip   TEXT,
      submitted_at  TIMESTAMPTZ DEFAULT NOW(),
      moderated_at  TIMESTAMPTZ
    );
    CREATE INDEX IF NOT EXISTS idx_reviews_listing ON reviews(listing_id);
    CREATE INDEX IF NOT EXISTS idx_reviews_status  ON reviews(status);

    CREATE TABLE IF NOT EXISTS edit_suggestions (
      id               SERIAL PRIMARY KEY,
      listing_id       TEXT NOT NULL,
      listing_name     TEXT NOT NULL,
      submitter_name   TEXT,
      submitter_email  TEXT,
      edit_description TEXT NOT NULL,
      status           TEXT DEFAULT 'pending' CHECK(status IN ('pending','done','dismissed')),
      submitter_ip     TEXT,
      submitted_at     TIMESTAMPTZ DEFAULT NOW()
    );
    CREATE INDEX IF NOT EXISTS idx_edits_status ON edit_suggestions(status);
  `);
}

// ─── Listings ─────────────────────────────────────────────────────────────────

async function getAllListings() {
  const r = await query('SELECT id, category, category_slug, name, data FROM listings ORDER BY category, name');
  return r.rows;
}

async function getListingsByCategory(slug) {
  const r = await query(
    'SELECT id, category, category_slug, name, data FROM listings WHERE category_slug=$1 ORDER BY name',
    [slug]
  );
  return r.rows;
}

async function getListingById(id) {
  const r = await query(
    'SELECT id, category, category_slug, name, data FROM listings WHERE id=$1',
    [id]
  );
  return r.rows[0] || null;
}

async function searchListings(q) {
  const like = `%${q.toLowerCase()}%`;
  const r = await query(
    `SELECT id, category, category_slug, name, data FROM listings
     WHERE LOWER(name) LIKE $1 OR LOWER(data::text) LIKE $1
     ORDER BY category, name`,
    [like]
  );
  return r.rows;
}

async function upsertListing({ id, category, category_slug, name, ...rest }) {
  await query(
    `INSERT INTO listings (id, category, category_slug, name, data)
     VALUES ($1,$2,$3,$4,$5)
     ON CONFLICT(id) DO UPDATE SET
       category=EXCLUDED.category,
       category_slug=EXCLUDED.category_slug,
       name=EXCLUDED.name,
       data=EXCLUDED.data`,
    [id, category, category_slug, name, rest]
  );
}

async function getCategories() {
  const r = await query(
    'SELECT category, category_slug, COUNT(*)::int as count FROM listings GROUP BY category, category_slug ORDER BY category'
  );
  return r.rows;
}

// ─── Reviews ──────────────────────────────────────────────────────────────────

async function submitReview({ listing_id, listing_name, category, reviewer_name, rating, review_text, reviewer_ip }) {
  await query(
    `INSERT INTO reviews (listing_id,listing_name,category,reviewer_name,rating,review_text,reviewer_ip)
     VALUES ($1,$2,$3,$4,$5,$6,$7)`,
    [listing_id, listing_name, category, reviewer_name, rating, review_text, reviewer_ip]
  );
}

async function getApprovedReviews(listing_id) {
  const r = await query(
    `SELECT id,reviewer_name,rating,review_text,submitted_at FROM reviews
     WHERE listing_id=$1 AND status='approved' ORDER BY submitted_at DESC`,
    [listing_id]
  );
  return r.rows;
}

async function getAvgRating(listing_id) {
  const r = await query(
    `SELECT ROUND(AVG(rating)::numeric,1)::float as avg, COUNT(*)::int as count
     FROM reviews WHERE listing_id=$1 AND status='approved'`,
    [listing_id]
  );
  return r.rows[0];
}

async function getPendingReviews() {
  const r = await query(`SELECT * FROM reviews WHERE status='pending' ORDER BY submitted_at ASC`);
  return r.rows;
}

async function getPublishedReviews(search) {
  if (search) {
    const like = `%${search.toLowerCase()}%`;
    const r = await query(
      `SELECT * FROM reviews WHERE status='approved'
       AND (LOWER(listing_name) LIKE $1 OR LOWER(category) LIKE $1)
       ORDER BY moderated_at DESC`,
      [like]
    );
    return r.rows;
  }
  const r = await query(`SELECT * FROM reviews WHERE status='approved' ORDER BY moderated_at DESC`);
  return r.rows;
}

async function approveReview(id) {
  await query(`UPDATE reviews SET status='approved', moderated_at=NOW() WHERE id=$1`, [id]);
}

async function rejectReview(id) {
  await query(`DELETE FROM reviews WHERE id=$1`, [id]);
}

async function deleteReview(id) {
  await query(`DELETE FROM reviews WHERE id=$1`, [id]);
}

// ─── Edit Suggestions ─────────────────────────────────────────────────────────

async function submitEdit({ listing_id, listing_name, submitter_name, submitter_email, edit_description, submitter_ip }) {
  await query(
    `INSERT INTO edit_suggestions (listing_id,listing_name,submitter_name,submitter_email,edit_description,submitter_ip)
     VALUES ($1,$2,$3,$4,$5,$6)`,
    [listing_id, listing_name, submitter_name || null, submitter_email || null, edit_description, submitter_ip]
  );
}

async function getPendingEdits() {
  const r = await query(`SELECT * FROM edit_suggestions WHERE status='pending' ORDER BY submitted_at ASC`);
  return r.rows;
}

async function resolveEdit(id, action) {
  const status = action === 'done' ? 'done' : 'dismissed';
  await query(`UPDATE edit_suggestions SET status=$1 WHERE id=$2`, [status, id]);
}

module.exports = {
  initSchema,
  getAllListings, getListingsByCategory, getListingById, searchListings, upsertListing, getCategories,
  submitReview, getApprovedReviews, getAvgRating, getPendingReviews, getPublishedReviews,
  approveReview, rejectReview, deleteReview,
  submitEdit, getPendingEdits, resolveEdit,
};

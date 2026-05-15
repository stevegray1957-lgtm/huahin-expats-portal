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

    ALTER TABLE listings ADD COLUMN IF NOT EXISTS premium_level TEXT;
    ALTER TABLE listings ADD COLUMN IF NOT EXISTS premium_expires_at TIMESTAMPTZ;
    ALTER TABLE listings ADD COLUMN IF NOT EXISTS verified BOOLEAN DEFAULT FALSE;
    ALTER TABLE listings ADD COLUMN IF NOT EXISTS featured BOOLEAN DEFAULT FALSE;
    ALTER TABLE listings ADD COLUMN IF NOT EXISTS admin_notes TEXT;
    ALTER TABLE listings ADD COLUMN IF NOT EXISTS owner_email TEXT;

    CREATE TABLE IF NOT EXISTS settings (
      key        TEXT PRIMARY KEY,
      value      JSONB NOT NULL,
      updated_at TIMESTAMPTZ DEFAULT NOW()
    );

    CREATE TABLE IF NOT EXISTS audit_log (
      id         SERIAL PRIMARY KEY,
      action     TEXT NOT NULL,
      actor      TEXT,
      target     TEXT,
      details    JSONB DEFAULT '{}',
      created_at TIMESTAMPTZ DEFAULT NOW()
    );
    CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_log(action);
    CREATE INDEX IF NOT EXISTS idx_audit_target ON audit_log(target);

    CREATE TABLE IF NOT EXISTS payments (
      id                   SERIAL PRIMARY KEY,
      listing_id           TEXT NOT NULL,
      owner_email          TEXT,
      tier                 TEXT NOT NULL,
      amount               NUMERIC(10,2) NOT NULL,
      currency             TEXT NOT NULL DEFAULT 'THB',
      provider             TEXT NOT NULL DEFAULT 'airwallex',
      mode                 TEXT NOT NULL DEFAULT 'sandbox',
      provider_payment_id  TEXT,
      provider_link_id     TEXT,
      hosted_url           TEXT,
      status               TEXT NOT NULL DEFAULT 'pending'
                              CHECK(status IN ('pending','succeeded','failed','cancelled','expired','refunded')),
      metadata             JSONB DEFAULT '{}',
      created_at           TIMESTAMPTZ DEFAULT NOW(),
      paid_at              TIMESTAMPTZ,
      reviewed_at          TIMESTAMPTZ,
      last_webhook_event   TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_payments_listing ON payments(listing_id);
    CREATE INDEX IF NOT EXISTS idx_payments_status  ON payments(status);
    CREATE INDEX IF NOT EXISTS idx_payments_provider_pid ON payments(provider_payment_id);

    CREATE TABLE IF NOT EXISTS payment_events (
      id            SERIAL PRIMARY KEY,
      provider      TEXT NOT NULL,
      event_id      TEXT NOT NULL,
      event_type    TEXT NOT NULL,
      payment_id    INTEGER REFERENCES payments(id) ON DELETE SET NULL,
      payload       JSONB NOT NULL,
      processed_at  TIMESTAMPTZ DEFAULT NOW(),
      UNIQUE(provider, event_id)
    );

    CREATE TABLE IF NOT EXISTS discovery_jobs (
      id                 SERIAL PRIMARY KEY,
      name               TEXT NOT NULL,
      source             TEXT NOT NULL DEFAULT 'google_places',
      category_target    TEXT,
      search_query       TEXT NOT NULL,
      radius_meters      INTEGER,
      center_lat         NUMERIC(9,6),
      center_lng         NUMERIC(9,6),
      max_results        INTEGER DEFAULT 60,
      status             TEXT NOT NULL DEFAULT 'queued'
                            CHECK(status IN ('queued','running','done','failed','cancelled')),
      created_by         TEXT,
      started_at         TIMESTAMPTZ,
      finished_at        TIMESTAMPTZ,
      total_found        INTEGER DEFAULT 0,
      candidates_created INTEGER DEFAULT 0,
      duplicates_found   INTEGER DEFAULT 0,
      errors             INTEGER DEFAULT 0,
      error_log          JSONB DEFAULT '[]',
      created_at         TIMESTAMPTZ DEFAULT NOW()
    );

    CREATE TABLE IF NOT EXISTS candidates (
      id                          SERIAL PRIMARY KEY,
      source                      TEXT NOT NULL,
      source_place_id             TEXT,
      source_url                  TEXT,
      source_capture_date         TIMESTAMPTZ DEFAULT NOW(),
      source_confidence           NUMERIC(3,2),
      business_name               TEXT NOT NULL,
      business_type               TEXT,
      mapped_directory_category   TEXT,
      full_address                TEXT,
      phone                       TEXT,
      website_url                 TEXT,
      facebook_url                TEXT,
      facebook_url_provenance     TEXT,
      instagram_url               TEXT,
      instagram_url_provenance    TEXT,
      tiktok_url                  TEXT,
      tiktok_url_provenance       TEXT,
      google_maps_url             TEXT,
      latitude                    NUMERIC(9,6),
      longitude                   NUMERIC(9,6),
      opening_hours               JSONB,
      business_status             TEXT,
      photo_reference             TEXT,
      notes_internal              TEXT,
      public_note                 TEXT,
      duplicate_match_listing_id  TEXT,
      conflict_status             TEXT DEFAULT 'none'
                                    CHECK(conflict_status IN ('none','pending','resolved')),
      approval_status             TEXT NOT NULL DEFAULT 'pending'
                                    CHECK(approval_status IN ('pending','approved','rejected','merged','needs_verification')),
      dq_score                    NUMERIC(3,2),
      job_id                      INTEGER REFERENCES discovery_jobs(id) ON DELETE SET NULL,
      raw_payload                 JSONB,
      created_at                  TIMESTAMPTZ DEFAULT NOW(),
      updated_at                  TIMESTAMPTZ DEFAULT NOW(),
      UNIQUE(source, source_place_id)
    );
    CREATE INDEX IF NOT EXISTS idx_candidates_status ON candidates(approval_status);
    CREATE INDEX IF NOT EXISTS idx_candidates_dup    ON candidates(duplicate_match_listing_id);
    CREATE INDEX IF NOT EXISTS idx_candidates_cat    ON candidates(mapped_directory_category);

    CREATE TABLE IF NOT EXISTS candidate_conflicts (
      id            SERIAL PRIMARY KEY,
      candidate_id  INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
      listing_id    TEXT,
      field_name    TEXT NOT NULL,
      existing_value TEXT,
      new_value     TEXT,
      source        TEXT,
      resolution    TEXT,
      resolved_at   TIMESTAMPTZ,
      created_at    TIMESTAMPTZ DEFAULT NOW()
    );
    CREATE INDEX IF NOT EXISTS idx_conflicts_candidate ON candidate_conflicts(candidate_id);

    CREATE TABLE IF NOT EXISTS import_logs (
      id           SERIAL PRIMARY KEY,
      job_id       INTEGER REFERENCES discovery_jobs(id) ON DELETE SET NULL,
      candidate_id INTEGER REFERENCES candidates(id) ON DELETE SET NULL,
      level        TEXT NOT NULL DEFAULT 'info'
                      CHECK(level IN ('info','warn','error')),
      message      TEXT NOT NULL,
      details      JSONB DEFAULT '{}',
      created_at   TIMESTAMPTZ DEFAULT NOW()
    );
    CREATE INDEX IF NOT EXISTS idx_import_logs_job ON import_logs(job_id);

    CREATE TABLE IF NOT EXISTS api_usage (
      id         SERIAL PRIMARY KEY,
      provider   TEXT NOT NULL,
      endpoint   TEXT NOT NULL,
      cost_units NUMERIC(8,2) DEFAULT 1,
      job_id     INTEGER,
      created_at TIMESTAMPTZ DEFAULT NOW()
    );
    CREATE INDEX IF NOT EXISTS idx_usage_provider ON api_usage(provider);
  `);
}

// ─── Listings ─────────────────────────────────────────────────────────────────

async function getAllListings() {
  const r = await query('SELECT id, category, category_slug, name, data, premium_level, premium_expires_at, verified, featured FROM listings ORDER BY category, name');
  return r.rows;
}

async function getListingsByCategory(slug) {
  const r = await query(
    'SELECT id, category, category_slug, name, data, premium_level, premium_expires_at, verified, featured FROM listings WHERE category_slug=$1 ORDER BY name',
    [slug]
  );
  return r.rows;
}

async function getListingById(id) {
  const r = await query(
    'SELECT id, category, category_slug, name, data, premium_level, premium_expires_at, verified, featured FROM listings WHERE id=$1',
    [id]
  );
  return r.rows[0] || null;
}

async function searchListings(q) {
  const like = `%${q.toLowerCase()}%`;
  const r = await query(
    `SELECT id, category, category_slug, name, data, premium_level, premium_expires_at, verified, featured FROM listings
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
  pool, query, initSchema,
  getAllListings, getListingsByCategory, getListingById, searchListings, upsertListing, getCategories,
  submitReview, getApprovedReviews, getAvgRating, getPendingReviews, getPublishedReviews,
  approveReview, rejectReview, deleteReview,
  submitEdit, getPendingEdits, resolveEdit,
};

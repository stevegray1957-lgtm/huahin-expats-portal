'use strict';

let adminToken = sessionStorage.getItem('adminToken') || '';

// ── Init ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  if (adminToken) {
    showAdminShell();
  }

  document.getElementById('login-form').addEventListener('submit', async e => {
    e.preventDefault();
    const pw = document.getElementById('admin-password').value;
    const errEl = document.getElementById('login-error');
    errEl.style.display = 'none';

    try {
      const res = await fetch('/api/admin/auth', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: pw }),
      });
      const data = await res.json();
      if (res.ok) {
        adminToken = data.token;
        sessionStorage.setItem('adminToken', adminToken);
        showAdminShell();
      } else {
        errEl.style.display = 'block';
        document.getElementById('admin-password').value = '';
      }
    } catch {
      errEl.textContent = 'Connection error. Is the server running?';
      errEl.style.display = 'block';
    }
  });

  document.getElementById('pub-search').addEventListener('keydown', e => {
    if (e.key === 'Enter') loadPublishedReviews();
  });
});

function showAdminShell() {
  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('admin-shell').style.display = 'block';
  loadAll();
}

async function logout() {
  try {
    await fetch('/api/admin/logout', { method: 'POST', headers: authHeaders() });
  } catch {}
  adminToken = '';
  sessionStorage.removeItem('adminToken');
  location.reload();
}

function authHeaders() {
  return { 'Content-Type': 'application/json', 'x-admin-token': adminToken };
}

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById(`tab-${name}`).classList.add('active');
  document.getElementById(`panel-${name}`).classList.add('active');

  if (name === 'published-reviews') loadPublishedReviews();
}

// ── Load all ──────────────────────────────────────────────────────────────────
function loadAll() {
  loadPendingReviews();
  loadPendingEdits();
  loadStats();
}

async function loadStats() {
  try {
    const [pending, edits, published] = await Promise.all([
      fetch('/api/admin/reviews/pending', { headers: authHeaders() }).then(r => r.json()),
      fetch('/api/admin/edits/pending', { headers: authHeaders() }).then(r => r.json()),
      fetch('/api/admin/reviews/published', { headers: authHeaders() }).then(r => r.json()),
    ]);
    document.getElementById('stat-pending').textContent = Array.isArray(pending) ? pending.length : '–';
    document.getElementById('stat-published').textContent = Array.isArray(published) ? published.length : '–';
    document.getElementById('stat-edits').textContent = Array.isArray(edits) ? edits.length : '–';
  } catch {}
}

// ── Pending Reviews ───────────────────────────────────────────────────────────
async function loadPendingReviews() {
  const container = document.getElementById('pending-reviews-list');
  try {
    const reviews = await fetch('/api/admin/reviews/pending', { headers: authHeaders() }).then(r => r.json());

    if (!Array.isArray(reviews)) {
      handleUnauth(container);
      return;
    }

    updateBadge('pending-reviews', reviews.length);
    document.getElementById('stat-pending').textContent = reviews.length;

    if (reviews.length === 0) {
      container.innerHTML = `<div class="admin-empty"><div class="icon">🎉</div><p>No reviews pending — you're all caught up!</p></div>`;
      return;
    }

    container.innerHTML = reviews.map(r => `
      <div class="admin-card pending" id="rev-${r.id}">
        <div class="admin-card-header">
          <div>
            <strong>${escHtml(r.listing_name)}</strong>
            <span style="color:#888; font-size:0.82rem; margin-left:8px;">${escHtml(r.category)}</span>
          </div>
          <div class="admin-stars">${'★'.repeat(r.rating)}${'☆'.repeat(5 - r.rating)}</div>
        </div>
        <div class="admin-card-meta">
          <span>👤 <strong>${escHtml(r.reviewer_name)}</strong></span>
          <span>📅 ${formatDate(r.submitted_at)}</span>
          <span class="ip-tag">IP: ${escHtml(r.reviewer_ip || 'unknown')}</span>
        </div>
        <div class="admin-card-body">${escHtml(r.review_text)}</div>
        <div class="admin-card-actions">
          <button class="btn btn-primary btn-sm" onclick="approveReview(${r.id})">✅ Approve</button>
          <button class="btn btn-sm" style="background:#FFF0F0; color:#CC1A1A; border:1px solid #f5c6c6;" onclick="rejectReview(${r.id})">❌ Reject</button>
        </div>
      </div>`).join('');
  } catch (e) {
    container.innerHTML = `<div class="admin-empty"><div class="icon">⚠️</div><p>Failed to load. ${e.message}</p></div>`;
  }
}

async function approveReview(id) {
  try {
    const res = await fetch(`/api/admin/reviews/approve/${id}`, { method: 'POST', headers: authHeaders() });
    if (res.ok) {
      removeCard(`rev-${id}`);
      showToast('Review approved and published.', 'success');
      loadStats();
    }
  } catch { showToast('Error approving review.', 'error'); }
}

async function rejectReview(id) {
  if (!confirm('Permanently delete this review?')) return;
  try {
    const res = await fetch(`/api/admin/reviews/reject/${id}`, { method: 'POST', headers: authHeaders() });
    if (res.ok) {
      removeCard(`rev-${id}`);
      showToast('Review rejected and deleted.', 'success');
      loadStats();
    }
  } catch { showToast('Error rejecting review.', 'error'); }
}

// ── Pending Edits ─────────────────────────────────────────────────────────────
async function loadPendingEdits() {
  const container = document.getElementById('pending-edits-list');
  try {
    const edits = await fetch('/api/admin/edits/pending', { headers: authHeaders() }).then(r => r.json());

    if (!Array.isArray(edits)) {
      handleUnauth(container);
      return;
    }

    updateBadge('pending-edits', edits.length);
    document.getElementById('stat-edits').textContent = edits.length;

    if (edits.length === 0) {
      container.innerHTML = `<div class="admin-empty"><div class="icon">✅</div><p>No pending edit suggestions.</p></div>`;
      return;
    }

    container.innerHTML = edits.map(e => `
      <div class="admin-card pending" id="edit-${e.id}">
        <div class="admin-card-header">
          <strong>${escHtml(e.listing_name)}</strong>
          <span style="color:#888; font-size:0.82rem;">📅 ${formatDate(e.submitted_at)}</span>
        </div>
        <div class="admin-card-meta">
          ${e.submitter_name ? `<span>👤 <strong>${escHtml(e.submitter_name)}</strong></span>` : ''}
          ${e.submitter_email ? `<span>✉️ <a href="mailto:${escHtml(e.submitter_email)}">${escHtml(e.submitter_email)}</a></span>` : ''}
          <span class="ip-tag">IP: ${escHtml(e.submitter_ip || 'unknown')}</span>
        </div>
        <div class="admin-card-body">${escHtml(e.edit_description)}</div>
        <div class="admin-card-actions">
          <button class="btn btn-primary btn-sm" onclick="resolveEdit(${e.id}, 'done')">✅ Mark as Done</button>
          <button class="btn btn-sm" style="background:#F5F5F5; color:#666; border:1px solid #dde3ee;" onclick="resolveEdit(${e.id}, 'dismissed')">❌ Dismiss</button>
        </div>
      </div>`).join('');
  } catch (err) {
    container.innerHTML = `<div class="admin-empty"><div class="icon">⚠️</div><p>Failed to load edits.</p></div>`;
  }
}

async function resolveEdit(id, action) {
  try {
    const res = await fetch(`/api/admin/edits/resolve/${id}`, {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify({ action }),
    });
    if (res.ok) {
      removeCard(`edit-${id}`);
      showToast(action === 'done' ? 'Marked as done.' : 'Dismissed.', 'success');
      loadStats();
    }
  } catch { showToast('Error resolving edit.', 'error'); }
}

// ── Published Reviews ─────────────────────────────────────────────────────────
async function loadPublishedReviews() {
  const container = document.getElementById('published-reviews-list');
  const search = document.getElementById('pub-search').value.trim();

  container.innerHTML = `<div class="admin-empty"><div class="icon">⏳</div><p>Loading…</p></div>`;

  try {
    const url = `/api/admin/reviews/published${search ? `?search=${encodeURIComponent(search)}` : ''}`;
    const reviews = await fetch(url, { headers: authHeaders() }).then(r => r.json());

    document.getElementById('stat-published').textContent = reviews.length;

    if (!Array.isArray(reviews) || reviews.length === 0) {
      container.innerHTML = `<div class="admin-empty"><div class="icon">📭</div><p>No published reviews${search ? ` matching "${escHtml(search)}"` : ''} yet.</p></div>`;
      return;
    }

    container.innerHTML = reviews.map(r => `
      <div class="admin-card approved" id="pub-${r.id}">
        <div class="admin-card-header">
          <div>
            <strong>${escHtml(r.listing_name)}</strong>
            <span style="color:#888; font-size:0.82rem; margin-left:8px;">${escHtml(r.category)}</span>
          </div>
          <div class="admin-stars">${'★'.repeat(r.rating)}${'☆'.repeat(5 - r.rating)}</div>
        </div>
        <div class="admin-card-meta">
          <span>👤 <strong>${escHtml(r.reviewer_name)}</strong></span>
          <span>📅 ${formatDate(r.submitted_at)}</span>
          <span style="color:#2ABFBF; font-size:0.78rem; font-weight:700;">✅ Published</span>
        </div>
        <div class="admin-card-body">${escHtml(r.review_text)}</div>
        <div class="admin-card-actions">
          <button class="btn btn-sm" style="background:#FFF0F0; color:#CC1A1A; border:1px solid #f5c6c6;" onclick="deletePublished(${r.id})">🗑️ Delete</button>
        </div>
      </div>`).join('');
  } catch {
    container.innerHTML = `<div class="admin-empty"><div class="icon">⚠️</div><p>Failed to load published reviews.</p></div>`;
  }
}

async function deletePublished(id) {
  if (!confirm('Permanently delete this published review?')) return;
  try {
    const res = await fetch(`/api/admin/reviews/${id}`, { method: 'DELETE', headers: authHeaders() });
    if (res.ok) {
      removeCard(`pub-${id}`);
      showToast('Review deleted.', 'success');
      loadStats();
    }
  } catch { showToast('Error deleting review.', 'error'); }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function updateBadge(name, count) {
  const el = document.getElementById(`badge-${name}`);
  if (!el) return;
  el.textContent = count;
  el.classList.toggle('zero', count === 0);
}

function removeCard(id) {
  const el = document.getElementById(id);
  if (el) {
    el.style.opacity = '0';
    el.style.transform = 'translateX(30px)';
    el.style.transition = 'all 0.25s ease';
    setTimeout(() => el.remove(), 250);
  }
}

function handleUnauth(container) {
  adminToken = '';
  sessionStorage.removeItem('adminToken');
  container.innerHTML = `<div class="admin-empty"><div class="icon">🔒</div><p>Session expired. <a href="/admin.html">Sign in again</a></p></div>`;
}

function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function formatDate(dt) {
  if (!dt) return '';
  try { return new Date(dt).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
  catch { return dt; }
}

function showToast(msg, type = 'success') {
  const toast = document.getElementById('toast');
  toast.textContent = msg;
  toast.className = `toast ${type} show`;
  setTimeout(() => toast.classList.remove('show'), 4000);
}

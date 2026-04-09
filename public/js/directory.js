'use strict';

// ── State ────────────────────────────────────────────────────────────────────
let allListings = [];
let filteredListings = [];
let activeCategory = '';
let searchQuery = '';
let reviewsCache = {};    // listing_id → { reviews, avg, count, loaded }
let expandedReviews = {}; // listing_id → bool

// ── Init ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Parse URL params
  const params = new URLSearchParams(location.search);
  activeCategory = params.get('cat') || '';
  searchQuery = params.get('q') || '';
  if (searchQuery) document.getElementById('search-input').value = searchQuery;

  loadDirectory();
  setupSearch();
  setupModals();
  setupHamburger();
});

function setupHamburger() {
  document.getElementById('hamburger')?.addEventListener('click', () => {
    document.getElementById('site-nav')?.classList.toggle('open');
  });
}

// ── Load data ─────────────────────────────────────────────────────────────────
async function loadDirectory() {
  try {
    const [listings, categories] = await Promise.all([
      fetch('/api/listings').then(r => r.json()),
      fetch('/api/listings/categories').then(r => r.json()),
    ]);

    allListings = listings;
    buildSidebar(categories);
    applyFilters();
  } catch (e) {
    document.getElementById('listings-container').innerHTML =
      `<div class="empty-state"><div class="icon">⚠️</div><p>Could not load directory. Is the server running?</p></div>`;
  }
}

// ── Sidebar ───────────────────────────────────────────────────────────────────
function buildSidebar(categories) {
  const total = categories.reduce((s, c) => s + c.count, 0);
  document.getElementById('total-count').textContent = total;

  const catIcons = {
    'medical-dental': '🏥', 'legal-visa': '⚖️', 'real-estate': '🏠',
    'banks-atms': '🏦', 'restaurants-dining': '🍽️', 'bars-cafes': '🍺',
    'golf-clubs': '⛳', 'supermarkets-shopping': '🛒', 'schools-education': '🎓',
    'gyms-fitness': '💪', 'hair-beauty': '💅', 'pet-services': '🐾',
    'car-motorbike-rental': '🏍️', 'internet-mobile': '📱', 'churches-worship': '⛪',
    'community-organisations': '🤝', 'facebook-groups': '👥', 'thai-massage-parlours': '💆',
    'laundry-services': '👕',
  };

  const ul = document.getElementById('cat-list');
  // Update "All" count
  ul.querySelector('a[data-cat=""]').querySelector('.cat-count').textContent = total;

  categories.forEach(cat => {
    const li = document.createElement('li');
    const icon = catIcons[cat.category_slug] || '📍';
    li.innerHTML = `<a href="#" data-cat="${cat.category_slug}">
      ${icon} ${cat.category}
      <span class="cat-count">${cat.count}</span>
    </a>`;
    ul.appendChild(li);
  });

  // Highlight active
  ul.querySelectorAll('a').forEach(a => {
    if (a.dataset.cat === activeCategory) a.classList.add('active');
    a.addEventListener('click', e => {
      e.preventDefault();
      activeCategory = a.dataset.cat;
      ul.querySelectorAll('a').forEach(x => x.classList.remove('active'));
      a.classList.add('active');
      applyFilters();
      updateUrl();
    });
  });
}

// ── Search ─────────────────────────────────────────────────────────────────────
function setupSearch() {
  const input = document.getElementById('search-input');
  const clearBtn = document.getElementById('clear-search');
  let debounceTimer;

  input.addEventListener('input', () => {
    searchQuery = input.value.trim();
    clearBtn.style.display = searchQuery ? 'block' : 'none';
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { applyFilters(); updateUrl(); }, 300);
  });

  clearBtn.addEventListener('click', () => {
    input.value = '';
    searchQuery = '';
    clearBtn.style.display = 'none';
    applyFilters();
    updateUrl();
  });
}

function updateUrl() {
  const params = new URLSearchParams();
  if (activeCategory) params.set('cat', activeCategory);
  if (searchQuery) params.set('q', searchQuery);
  history.replaceState(null, '', params.toString() ? `?${params}` : location.pathname);
}

// ── Filter & Render ───────────────────────────────────────────────────────────
function applyFilters() {
  let results = allListings;

  if (activeCategory) {
    results = results.filter(l => l.category_slug === activeCategory);
  }

  if (searchQuery) {
    const q = searchQuery.toLowerCase();
    results = results.filter(l => {
      const dataStr = JSON.stringify(l.data || {}).toLowerCase();
      return l.name.toLowerCase().includes(q) ||
             l.category.toLowerCase().includes(q) ||
             dataStr.includes(q);
    });
  }

  filteredListings = results;
  renderListings(results);
}

function renderListings(listings) {
  const container = document.getElementById('listings-container');
  const countEl = document.getElementById('results-count');

  const label = activeCategory
    ? listings[0]?.category || activeCategory
    : 'All Categories';
  countEl.innerHTML = `<strong>${listings.length}</strong> listing${listings.length !== 1 ? 's' : ''} in <strong>${escHtml(label)}</strong>`;

  if (listings.length === 0) {
    container.innerHTML = `<div class="empty-state">
      <div class="icon">🔍</div>
      <p>No listings found${searchQuery ? ` for "<strong>${escHtml(searchQuery)}</strong>"` : ''}. Try a different search or category.</p>
    </div>`;
    return;
  }

  container.innerHTML = listings.map(listing => buildListingCard(listing)).join('');

  // Load ratings for all visible listings
  listings.forEach(l => loadRating(l.id));
}

// ── Listing Card HTML ─────────────────────────────────────────────────────────
function buildListingCard(listing) {
  const d = listing.data || {};
  const fields = buildFieldRows(listing.category_slug, d);

  return `
    <article class="listing-card" id="card-${listing.id}">
      <div class="listing-header">
        <h2 class="listing-name">${escHtml(listing.name)}</h2>
        <span class="listing-category-tag">${escHtml(listing.category)}</span>
      </div>
      <div class="listing-rating-row" id="rating-${listing.id}">
        <span style="color:#aaa; font-size:0.82rem; font-style:italic;">Loading rating…</span>
      </div>
      <div class="listing-fields">${fields}</div>
      <div class="listing-actions">
        <button class="btn btn-primary btn-sm" onclick="openReviewModal('${listing.id}', '${escAttr(listing.name)}', '${escAttr(listing.category)}')">
          ★ Write a Review
        </button>
        <button class="btn btn-outline btn-sm" onclick="openEditModal('${listing.id}', '${escAttr(listing.name)}')">
          ✏️ Suggest an Edit
        </button>
        <button class="btn btn-sm" style="background:#F5F5F5; color:#555; border:1px solid #dde3ee;" onclick="toggleReviews('${listing.id}', '${escAttr(listing.name)}', '${escAttr(listing.category)}')">
          💬 Reviews
        </button>
      </div>
      <div class="reviews-section" id="reviews-${listing.id}" style="display:none"></div>
    </article>`;
}

// ── Build field rows per category ─────────────────────────────────────────────
function buildFieldRows(categorySlug, d) {
  const rows = [];

  // Universal address / location
  const addr = d.address || d.branch_address || d.atm_location || d.location || null;
  if (addr) rows.push(field('📍', addr));

  // Phone / contact
  const phone = d.phone || d['phone_&_email'] || d.phone_fb || d['phone_fb_web'] || null;
  if (phone && phone !== 'Contact for details') rows.push(field('📞', phone));

  // Website
  const web = d.website || d['address_&_website'] || null;
  if (web) {
    const url = web.startsWith('http') ? web : `https://${web}`;
    rows.push(field('🌐', `<a href="${escAttr(url)}" target="_blank" rel="noopener">${escHtml(web)}</a>`));
  }

  // Hours
  const hours = d.hours || d['opening_hours'] || d['service_times'] || null;
  if (hours) rows.push(field('🕒', hours));

  // Category-specific fields
  switch (categorySlug) {
    case 'medical-dental':
      if (d.services) rows.push(field('🩺', d.services));
      if (d.languages) rows.push(field('🌍', `Languages: ${d.languages}`));
      if (d.insurance) rows.push(field('💳', `Insurance: ${d.insurance}`));
      if (d.price_range) rows.push(field('💰', `Price: ${d.price_range}`));
      break;
    case 'restaurants-dining':
      if (d.cuisine) rows.push(field('🍴', d.cuisine));
      if (d.price_range) rows.push(field('💰', d.price_range));
      if (d.delivery) rows.push(field('🛵', `Delivery: ${d.delivery}`));
      if (d.popular_dishes) rows.push(field('⭐', `Popular: ${d.popular_dishes}`));
      break;
    case 'bars-cafes':
      if (d.type) rows.push(field('🍸', d.type));
      if (d.price_range) rows.push(field('💰', d.price_range));
      if (d.delivery) rows.push(field('🛵', `Delivery: ${d.delivery}`));
      break;
    case 'golf-clubs':
      if (d.holes) rows.push(field('⛳', `${d.holes} holes`));
      if (d['green_fees_(thb)'] || d.green_fees) rows.push(field('💰', `Green fees: ${d['green_fees_(thb)'] || d.green_fees} THB`));
      if (d.caddy) rows.push(field('🎒', `Caddy: ${d.caddy}`));
      if (d.visitor_bookings) rows.push(field('📅', `Bookings: ${d.visitor_bookings}`));
      break;
    case 'schools-education':
      if (d.curriculum) rows.push(field('📚', d.curriculum));
      if (d.ages) rows.push(field('👶', `Ages: ${d.ages}`));
      if (d.fees) rows.push(field('💰', `Fees: ${d.fees}`));
      if (d.boarding) rows.push(field('🏠', `Boarding: ${d.boarding}`));
      break;
    case 'gyms-fitness':
      if (d.facilities) rows.push(field('🏋️', d.facilities));
      if (d.membership_cost) rows.push(field('💰', `Membership: ${d.membership_cost}`));
      if (d.classes_offered) rows.push(field('🧘', `Classes: ${d.classes_offered}`));
      break;
    case 'car-motorbike-rental':
      if (d.vehicles_available) rows.push(field('🚗', d.vehicles_available));
      if (d.rates) rows.push(field('💰', `Rates: ${d.rates}`));
      if (d['intl_licence'] || d.international_licence) rows.push(field('📋', `Intl licence: ${d['intl_licence'] || d.international_licence}`));
      break;
    case 'facebook-groups':
      if (d.facebook_url || d['facebook_url_(link)']) {
        const fbUrl = d.facebook_url || d['facebook_url_(link)'];
        rows.push(field('👥', `<a href="${escAttr(fbUrl)}" target="_blank" rel="noopener">${escHtml(fbUrl)}</a>`));
      }
      if (d.member_count) rows.push(field('👤', `${d.member_count} members`));
      if (d.purpose) rows.push(field('📌', d.purpose));
      break;
    case 'banks-atms':
      if (d.services_for_foreigners) rows.push(field('🏦', d.services_for_foreigners));
      if (d.fx_exchange) rows.push(field('💱', `FX: ${d.fx_exchange}`));
      break;
    default:
      if (d.services) rows.push(field('ℹ️', d.services));
      if (d.price_range || d.price) rows.push(field('💰', d.price_range || d.price));
      break;
  }

  // Notes fallback
  if (d.notes && rows.length < 3) rows.push(field('📝', d.notes));

  // Fallback if nothing found
  if (rows.length === 0) rows.push(field('ℹ️', 'Contact for details'));

  // Filter out "Contact for details" entries if we have real data
  const realRows = rows.filter(r => !r.includes('Contact for details'));
  return (realRows.length > 0 ? realRows : rows).join('');
}

function field(icon, value) {
  if (!value || value === 'null' || value === null) return '';
  const strVal = String(value).trim();
  if (!strVal || strVal === 'null') return '';
  return `<div class="listing-field"><span class="field-icon">${icon}</span><span class="field-val">${escHtml(strVal).replace(/https?:\/\/\S+/g, m => `<a href="${m}" target="_blank" rel="noopener">${m}</a>`)}</span></div>`;
}

// override field to allow pre-built HTML links
function field(icon, value, rawHtml = false) {
  if (!value || value === 'null' || value === null) return '';
  const strVal = String(value).trim();
  if (!strVal || strVal === 'null') return '';
  const display = rawHtml ? strVal : escHtml(strVal);
  return `<div class="listing-field"><span class="field-icon">${icon}</span><span class="field-val">${display}</span></div>`;
}

// ── Ratings ───────────────────────────────────────────────────────────────────
async function loadRating(listingId) {
  try {
    const data = await fetch(`/api/reviews/avg?listing_id=${encodeURIComponent(listingId)}`).then(r => r.json());
    const el = document.getElementById(`rating-${listingId}`);
    if (!el) return;
    if (data.count === 0) {
      el.innerHTML = `<span style="color:#aaa; font-size:0.82rem; font-style:italic;">No reviews yet — be the first!</span>`;
    } else {
      el.innerHTML = `
        <div class="rating-summary">
          ${renderStars(data.avg)}
          <span class="rating-score">${data.avg}</span>
          <span class="rating-count">(${data.count} review${data.count !== 1 ? 's' : ''})</span>
        </div>`;
    }
    if (!reviewsCache[listingId]) reviewsCache[listingId] = {};
    reviewsCache[listingId].avg = data.avg;
    reviewsCache[listingId].count = data.count;
  } catch {}
}

function renderStars(avg) {
  const full = Math.floor(avg);
  const half = avg - full >= 0.5 ? 1 : 0;
  const empty = 5 - full - half;
  return `<span class="stars">
    ${'<span class="filled">★</span>'.repeat(full)}
    ${half ? '<span class="half">½</span>' : ''}
    ${'<span>★</span>'.repeat(empty)}
  </span>`;
}

// ── Reviews toggle ────────────────────────────────────────────────────────────
async function toggleReviews(listingId, listingName, category) {
  const section = document.getElementById(`reviews-${listingId}`);
  const isOpen = section.style.display !== 'none';

  if (isOpen) {
    section.style.display = 'none';
    expandedReviews[listingId] = false;
    return;
  }

  section.style.display = 'block';
  expandedReviews[listingId] = true;

  if (reviewsCache[listingId]?.loaded) {
    renderReviewsSection(listingId, reviewsCache[listingId].reviews);
    return;
  }

  section.innerHTML = `<div style="text-align:center; padding:12px; color:#888; font-size:0.85rem;">Loading reviews…</div>`;

  try {
    const reviews = await fetch(`/api/reviews/approved?listing_id=${encodeURIComponent(listingId)}`).then(r => r.json());
    reviewsCache[listingId] = { ...(reviewsCache[listingId] || {}), reviews, loaded: true };
    renderReviewsSection(listingId, reviews);
  } catch {
    section.innerHTML = `<p style="color:#888; font-size:0.85rem;">Could not load reviews.</p>`;
  }
}

function renderReviewsSection(listingId, reviews) {
  const section = document.getElementById(`reviews-${listingId}`);
  const showAll = expandedReviews[`${listingId}_all`] || false;
  const visible = showAll ? reviews : reviews.slice(0, 3);

  if (reviews.length === 0) {
    section.innerHTML = `<p class="no-reviews">No reviews yet — be the first!</p>`;
    return;
  }

  const cards = visible.map(r => `
    <div class="review-item">
      <div class="rev-header">
        <span class="rev-name">${escHtml(r.reviewer_name)}</span>
        <div style="display:flex; align-items:center; gap:8px;">
          ${renderStars(r.rating)}
          <span class="rev-date">${formatDate(r.submitted_at)}</span>
        </div>
      </div>
      <div class="rev-text">${escHtml(r.review_text)}</div>
    </div>`).join('');

  const moreBtn = reviews.length > 3 && !showAll
    ? `<button class="show-more-reviews" onclick="showAllReviews('${listingId}')">Show all ${reviews.length} reviews</button>`
    : '';

  section.innerHTML = `<h4>Community Reviews</h4>${cards}${moreBtn}`;
}

function showAllReviews(listingId) {
  expandedReviews[`${listingId}_all`] = true;
  renderReviewsSection(listingId, reviewsCache[listingId]?.reviews || []);
}

// ── Review Modal ──────────────────────────────────────────────────────────────
function openReviewModal(listingId, listingName, category) {
  document.getElementById('rv-listing-id').value = listingId;
  document.getElementById('rv-listing-name').value = listingName;
  document.getElementById('rv-category').value = category;
  document.getElementById('rv-listing-display').value = listingName;
  document.getElementById('rv-name').value = '';
  document.getElementById('rv-text').value = '';
  document.getElementById('rv-rating').value = '0';
  document.getElementById('rv-char-count').textContent = '0';
  document.querySelectorAll('#star-picker span').forEach(s => s.classList.remove('active'));
  openModal('review-modal');
}

function openEditModal(listingId, listingName) {
  document.getElementById('ed-listing-id').value = listingId;
  document.getElementById('ed-listing-name').value = listingName;
  document.getElementById('ed-listing-display').value = listingName;
  document.getElementById('ed-name').value = '';
  document.getElementById('ed-email').value = '';
  document.getElementById('ed-desc').value = '';
  openModal('edit-modal');
}

function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}

// ── Modal setup ───────────────────────────────────────────────────────────────
function setupModals() {
  // Close on overlay click
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
      if (e.target === overlay) closeModal(overlay.id);
    });
  });

  // Star picker
  const starPicker = document.getElementById('star-picker');
  starPicker.querySelectorAll('span').forEach((star, i) => {
    star.addEventListener('click', () => {
      const val = i + 1;
      document.getElementById('rv-rating').value = val;
      starPicker.querySelectorAll('span').forEach((s, j) => {
        s.classList.toggle('active', j < val);
      });
    });
    star.addEventListener('mouseover', () => {
      starPicker.querySelectorAll('span').forEach((s, j) => {
        s.classList.toggle('active', j <= i);
      });
    });
    star.addEventListener('mouseout', () => {
      const currentVal = parseInt(document.getElementById('rv-rating').value) || 0;
      starPicker.querySelectorAll('span').forEach((s, j) => {
        s.classList.toggle('active', j < currentVal);
      });
    });
  });

  // Char count
  document.getElementById('rv-text').addEventListener('input', function() {
    document.getElementById('rv-char-count').textContent = this.value.length;
  });

  // Review form submit
  document.getElementById('review-form').addEventListener('submit', async e => {
    e.preventDefault();
    const rating = parseInt(document.getElementById('rv-rating').value);
    if (!rating) { showToast('Please select a star rating.', 'error'); return; }

    const payload = {
      listing_id: document.getElementById('rv-listing-id').value,
      listing_name: document.getElementById('rv-listing-name').value,
      category: document.getElementById('rv-category').value,
      reviewer_name: document.getElementById('rv-name').value,
      rating,
      review_text: document.getElementById('rv-text').value,
    };

    const btn = e.target.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Submitting…';

    try {
      const res = await fetch('/api/reviews/submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (res.ok) {
        closeModal('review-modal');
        showToast(data.message, 'success');
      } else {
        showToast(data.error || 'Submission failed.', 'error');
      }
    } catch {
      showToast('Network error. Please try again.', 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Submit Review';
    }
  });

  // Edit form submit
  document.getElementById('edit-form').addEventListener('submit', async e => {
    e.preventDefault();
    const payload = {
      listing_id: document.getElementById('ed-listing-id').value,
      listing_name: document.getElementById('ed-listing-name').value,
      submitter_name: document.getElementById('ed-name').value,
      submitter_email: document.getElementById('ed-email').value,
      edit_description: document.getElementById('ed-desc').value,
    };

    const btn = e.target.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Submitting…';

    try {
      const res = await fetch('/api/edits/submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (res.ok) {
        closeModal('edit-modal');
        showToast(data.message, 'success');
      } else {
        showToast(data.error || 'Submission failed.', 'error');
      }
    } catch {
      showToast('Network error. Please try again.', 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Submit Suggestion';
    }
  });

  // Escape key
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
    }
  });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escAttr(str) {
  if (!str) return '';
  return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

function formatDate(dt) {
  if (!dt) return '';
  try {
    return new Date(dt).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  } catch { return dt; }
}

function showToast(msg, type = 'success') {
  const toast = document.getElementById('toast');
  toast.textContent = msg;
  toast.className = `toast ${type} show`;
  setTimeout(() => toast.classList.remove('show'), 4500);
}

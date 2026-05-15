'use strict';
const settings = require('../lib/settings');

const SOCIAL_DOMAINS = {
  facebook: /(?:^|\.)facebook\.com$|(?:^|\.)fb\.com$/,
  instagram: /(?:^|\.)instagram\.com$/,
  tiktok: /(?:^|\.)tiktok\.com$/,
};

const robotsCache = new Map();

function hostnameOf(u) {
  try { return new URL(u).hostname.toLowerCase(); } catch { return null; }
}

function sameDomain(a, b) {
  const ha = hostnameOf(a);
  const hb = hostnameOf(b);
  if (!ha || !hb) return false;
  const norm = (h) => h.replace(/^www\./, '');
  return norm(ha) === norm(hb);
}

async function fetchWithLimits(url, { timeoutMs, maxBytes, ua }) {
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(url, {
      headers: { 'User-Agent': ua, 'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' },
      redirect: 'follow',
      signal: controller.signal,
    });
    if (!res.ok) return { ok: false, status: res.status, body: '' };
    const ct = res.headers.get('content-type') || '';
    if (!ct.includes('html') && !ct.includes('xml') && !ct.includes('text')) {
      return { ok: false, status: res.status, body: '', reason: 'non_html' };
    }
    const reader = res.body.getReader();
    const chunks = [];
    let total = 0;
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      total += value.length;
      if (total > maxBytes) { try { await reader.cancel(); } catch {} break; }
      chunks.push(value);
    }
    const buf = Buffer.concat(chunks.map(Buffer.from));
    return { ok: true, status: res.status, body: buf.toString('utf8'), url: res.url };
  } catch (e) {
    return { ok: false, error: e.name === 'AbortError' ? 'timeout' : e.message };
  } finally {
    clearTimeout(t);
  }
}

async function isAllowedByRobots(url, ua) {
  const host = hostnameOf(url);
  if (!host) return true;
  const key = `${host}::${ua}`;
  if (robotsCache.has(key)) return robotsCache.get(key);
  let allowed = true;
  try {
    const robotsUrl = `${new URL(url).origin}/robots.txt`;
    const res = await fetch(robotsUrl, { headers: { 'User-Agent': ua } });
    if (res.ok) {
      const text = await res.text();
      const path = new URL(url).pathname || '/';
      allowed = parseRobotsAllowed(text, ua, path);
    }
  } catch {}
  robotsCache.set(key, allowed);
  return allowed;
}

function parseRobotsAllowed(text, ua, path) {
  const lines = text.split(/\r?\n/).map(l => l.replace(/#.*$/, '').trim()).filter(Boolean);
  const groups = [];
  let cur = null;
  for (const line of lines) {
    const m = line.match(/^([A-Za-z-]+)\s*:\s*(.*)$/);
    if (!m) continue;
    const key = m[1].toLowerCase();
    const val = m[2].trim();
    if (key === 'user-agent') {
      if (!cur || cur.rules.length) { cur = { agents: [val.toLowerCase()], rules: [] }; groups.push(cur); }
      else cur.agents.push(val.toLowerCase());
    } else if (key === 'disallow' || key === 'allow') {
      if (!cur) { cur = { agents: ['*'], rules: [] }; groups.push(cur); }
      cur.rules.push({ type: key, path: val });
    }
  }
  const uaLow = ua.toLowerCase();
  const match = groups.filter(g => g.agents.some(a => a === '*' || uaLow.includes(a)));
  if (!match.length) return true;
  for (const g of match) {
    for (const r of g.rules) {
      if (r.type === 'disallow' && r.path && path.startsWith(r.path)) return false;
    }
  }
  return true;
}

function extractStructuredData(html) {
  const matches = [...html.matchAll(/<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)];
  const out = [];
  for (const m of matches) {
    try { out.push(JSON.parse(m[1].trim())); } catch {}
  }
  return out;
}

function extractLinks(html, baseUrl) {
  const links = new Set();
  const re = /<a[^>]+href=["']([^"']+)["']/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    try {
      const abs = new URL(m[1], baseUrl).toString();
      links.add(abs);
    } catch {}
  }
  return [...links];
}

function pickSocialLinks(links) {
  const out = {};
  for (const url of links) {
    const host = hostnameOf(url);
    if (!host) continue;
    for (const [key, re] of Object.entries(SOCIAL_DOMAINS)) {
      if (!out[key] && re.test(host)) out[key] = url;
    }
  }
  return out;
}

function extractEmails(html) {
  const text = html.replace(/<[^>]+>/g, ' ');
  const set = new Set();
  const re = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
  let m;
  while ((m = re.exec(text)) !== null) {
    const email = m[0].toLowerCase();
    if (email.endsWith('.png') || email.endsWith('.jpg') || email.endsWith('.gif')) continue;
    set.add(email);
  }
  return [...set];
}

async function enrich(websiteUrl) {
  const cfg = await settings.get('enrichment');
  const ua = cfg.user_agent;
  const timeoutMs = cfg.website_fetch_timeout_ms;
  const maxBytes = cfg.website_max_bytes;

  if (!websiteUrl || !/^https?:\/\//i.test(websiteUrl)) {
    return { ok: false, error: 'invalid_url' };
  }

  if (!(await isAllowedByRobots(websiteUrl, ua))) {
    return { ok: false, error: 'robots_disallow' };
  }

  const home = await fetchWithLimits(websiteUrl, { timeoutMs, maxBytes, ua });
  if (!home.ok) return { ok: false, error: home.error || `status_${home.status}` };

  const finalUrl = home.url || websiteUrl;
  const homeLinks = extractLinks(home.body, finalUrl);
  const jsonLd = extractStructuredData(home.body);
  const homeSocial = pickSocialLinks(homeLinks);
  const homeEmails = extractEmails(home.body);

  let contactSocial = {};
  let contactEmails = [];
  const contactCandidate = homeLinks.find(u => sameDomain(u, finalUrl) && /\/(contact|about)/i.test(new URL(u).pathname));
  if (contactCandidate && await isAllowedByRobots(contactCandidate, ua)) {
    const c = await fetchWithLimits(contactCandidate, { timeoutMs, maxBytes, ua });
    if (c.ok) {
      const cLinks = extractLinks(c.body, c.url || contactCandidate);
      contactSocial = pickSocialLinks(cLinks);
      contactEmails = extractEmails(c.body);
    }
  }

  const social = {
    facebook: homeSocial.facebook || contactSocial.facebook || null,
    instagram: homeSocial.instagram || contactSocial.instagram || null,
    tiktok: homeSocial.tiktok || contactSocial.tiktok || null,
  };

  return {
    ok: true,
    source_url: finalUrl,
    fetched_at: new Date().toISOString(),
    structured_data: jsonLd,
    social,
    emails: [...new Set([...homeEmails, ...contactEmails])].slice(0, 10),
    contact_page: contactCandidate || null,
  };
}

module.exports = { enrich, isAllowedByRobots, parseRobotsAllowed, sameDomain, hostnameOf, extractLinks, extractEmails, pickSocialLinks };

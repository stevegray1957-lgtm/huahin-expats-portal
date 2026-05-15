'use strict';

const STRICT_CATEGORIES = {
  'medical-dental': ['business_status', 'phone'],
  'visa-legal':     ['phone'],
};

function score(candidate) {
  const breakdown = {
    name_present:        !!candidate.business_name,
    category_mapped:     !!candidate.mapped_directory_category,
    address_present:     !!candidate.full_address,
    coordinates_present: candidate.latitude != null && candidate.longitude != null,
    contact_route:       !!(candidate.phone || candidate.website_url),
    web_or_social:       !!(candidate.website_url || candidate.facebook_url || candidate.instagram_url),
    source_verified:     candidate.source && candidate.source_place_id,
    duplicate_free:      !candidate.duplicate_match_listing_id,
    strict_category_ok:  true,
  };

  const strict = STRICT_CATEGORIES[candidate.mapped_directory_category];
  if (strict) {
    breakdown.strict_category_ok = strict.every(k => !!candidate[k] || (k === 'business_status' && candidate.business_status));
  }

  const checks = Object.values(breakdown);
  const pct = checks.filter(Boolean).length / checks.length;
  return { score: Math.round(pct * 100) / 100, breakdown };
}

module.exports = { score, STRICT_CATEGORIES };

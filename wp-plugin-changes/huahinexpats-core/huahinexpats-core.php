<?php
/**
 * Plugin Name: HuaHinExpats Core
 * Plugin URI:  https://huahinexpats.co
 * Description: Core functionality for HuaHinExpats.Co — CPTs, taxonomies, meta fields, and admin tools.
 * Version:     1.0.0
 * Author:      HuaHinExpats
 * Text Domain: huahinexpats-core
 *
 * @package HuaHinExpats
 */

defined( 'ABSPATH' ) || exit;

define( 'HHEC_VERSION', '1.0.0' );
define( 'HHEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'HHEC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Make plugin available for translation.
 *
 * Loaded on init (priority 1) so the locale is set before lookup.
 * Translation files go in: huahinexpats-core/languages/huahinexpats-core-{LOCALE}.mo
 * See languages/README.md for naming + how to generate the .pot template.
 */
add_action( 'init', function () {
	load_plugin_textdomain(
		'huahinexpats-core',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}, 1 );

require_once HHEC_DIR . 'inc/helpers.php';
require_once HHEC_DIR . 'inc/phone-normaliser.php';
require_once HHEC_DIR . 'inc/email-identity.php';
require_once HHEC_DIR . 'inc/email-outreach.php';
require_once HHEC_DIR . 'inc/events.php';
require_once HHEC_DIR . 'inc/shortcodes.php';
require_once HHEC_DIR . 'inc/cpts.php';
require_once HHEC_DIR . 'inc/taxonomies.php';
require_once HHEC_DIR . 'inc/meta-boxes.php';
require_once HHEC_DIR . 'inc/editorial-notes.php';
require_once HHEC_DIR . 'inc/enrichment-queue.php';
require_once HHEC_DIR . 'inc/editorial-review.php';
require_once HHEC_DIR . 'inc/admin-columns.php';
require_once HHEC_DIR . 'inc/admin-image-dashboard.php';
require_once HHEC_DIR . 'inc/seed-data.php';
require_once HHEC_DIR . 'inc/migration.php';
require_once HHEC_DIR . 'inc/reviews.php';
require_once HHEC_DIR . 'inc/search-log.php';
require_once HHEC_DIR . 'inc/listings-importer.php';
require_once HHEC_DIR . 'inc/premium.php';
require_once HHEC_DIR . 'inc/claims.php';
require_once HHEC_DIR . 'inc/owner.php';
require_once HHEC_DIR . 'inc/subscriptions.php';
require_once HHEC_DIR . 'inc/stripe.php';
require_once HHEC_DIR . 'inc/affiliate-dashboard.php';
require_once HHEC_DIR . 'inc/newsletter.php';
require_once HHEC_DIR . 'inc/image-manager.php';
require_once HHEC_DIR . 'inc/admin-revenue.php';
require_once HHEC_DIR . 'inc/admin-outreach.php';
require_once HHEC_DIR . 'inc/outreach-cron.php';
require_once HHEC_DIR . 'inc/deliverability.php';
require_once HHEC_DIR . 'inc/conversion-engine.php';
require_once HHEC_DIR . 'inc/auto-optimiser.php';
require_once HHEC_DIR . 'inc/data-quality.php';
require_once HHEC_DIR . 'inc/data-upgrade.php';
require_once HHEC_DIR . 'inc/trust.php';
require_once HHEC_DIR . 'inc/address-policy.php';
require_once HHEC_DIR . 'inc/geocode-log.php';
require_once HHEC_DIR . 'inc/geocoder.php';
require_once HHEC_DIR . 'inc/address-tools.php';
require_once HHEC_DIR . 'inc/moderation.php';
require_once HHEC_DIR . 'inc/trust-dashboard.php';
require_once HHEC_DIR . 'inc/candidates.php';
require_once HHEC_DIR . 'inc/settings.php';
require_once HHEC_DIR . 'inc/category-editor.php';
require_once HHEC_DIR . 'inc/category-visibility.php';
require_once HHEC_DIR . 'inc/roles.php';
require_once HHEC_DIR . 'inc/today.php';
require_once HHEC_DIR . 'inc/outcome-engine.php';
require_once HHEC_DIR . 'inc/playbook.php';
require_once HHEC_DIR . 'inc/execution-mode.php';

// Importer (admin only — no front-end overhead).
if ( is_admin() ) {
	require_once HHEC_DIR . 'inc/importer-helpers.php';
	require_once HHEC_DIR . 'inc/importer-mapper.php';
	require_once HHEC_DIR . 'inc/importer-logger.php';
	require_once HHEC_DIR . 'inc/importer.php';
	require_once HHEC_DIR . 'inc/importer-admin.php';
	require_once HHEC_DIR . 'inc/admin-candidates.php';
	require_once HHEC_DIR . 'inc/admin-paste-csv.php';

	// Email Settings admin page (Postmark + transactional routing).
	require_once HHEC_DIR . 'inc/admin-email-settings.php';
}

/**
 * Flush rewrite rules on activation.
 */
function hhec_activate() {
	hhec_register_cpts();
	hhec_register_taxonomies();
	hhec_create_reviews_table();
	hhec_create_search_log_table();
	hhec_create_claims_table();
	hhec_create_subscriptions_table();
	hhec_create_newsletter_table();
	hhec_create_email_tables();
	hhec_create_events_table();
	hhec_create_candidates_table();
	hhec_create_geocode_log_table();
	hhec_ensure_designer_role();
	hhec_sync_admin_email();
	hhec_register_listing_owner_role();
	hhec_ensure_owner_dashboard_page();
	hhec_ensure_monetisation_pages();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'hhec_activate' );

/**
 * Clean up on deactivation.
 */
function hhec_deactivate() {
	wp_clear_scheduled_hook( 'hhec_premium_expiry_check' );
	wp_clear_scheduled_hook( 'hhec_outreach_daily_run' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'hhec_deactivate' );

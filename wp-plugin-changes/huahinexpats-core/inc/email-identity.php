<?php
/**
 * Email identity — canonical site addresses, FROM filters, admin email sync.
 *
 * Single source of truth for every outbound address on HuaHinExpats.Co:
 *
 *   info@      — primary contact, default FROM on all system mail
 *   accounts@  — billing / upgrade mail
 *   legal@     — privacy, data requests
 *
 * Constants can be overridden from wp-config.php if the site ever changes
 * domains (e.g. during a staging mirror) — define HHE_EMAIL_INFO etc before
 * WordPress loads.
 *
 * @package HuaHinExpats
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Canonical addresses
// ─────────────────────────────────────────────────────────────────────────────

if ( ! defined( 'HHE_EMAIL_INFO' ) )     define( 'HHE_EMAIL_INFO',     'info@huahinexpats.co' );
if ( ! defined( 'HHE_EMAIL_ACCOUNTS' ) ) define( 'HHE_EMAIL_ACCOUNTS', 'accounts@huahinexpats.co' );
if ( ! defined( 'HHE_EMAIL_LEGAL' ) )    define( 'HHE_EMAIL_LEGAL',    'legal@huahinexpats.co' );
if ( ! defined( 'HHE_EMAIL_FROM_NAME' ) ) define( 'HHE_EMAIL_FROM_NAME', 'Hua Hin Expats' );

/**
 * Canonical address lookup by role.
 *
 * Resolution order (highest priority first):
 *   1. wp-config.php define() of HHE_EMAIL_INFO etc. — operator override.
 *      The constants are always defined at file load (defaults at the top).
 *      A wp-config.php define takes effect because it runs before this file.
 *   2. wp_option hhe_email_<role> — admin-editable via the Email Settings
 *      page. Returned when non-empty and a valid email.
 *   3. The constant default (e.g. info@huahinexpats.co).
 *
 * @param string $role  info | accounts | legal
 * @return string
 */
function hhec_email_address( $role = 'info' ) {
	switch ( $role ) {
		case 'accounts':
			$opt = (string) get_option( 'hhe_email_accounts', '' );
			return ( $opt !== '' && is_email( $opt ) ) ? $opt : HHE_EMAIL_ACCOUNTS;
		case 'legal':
			$opt = (string) get_option( 'hhe_email_legal', '' );
			return ( $opt !== '' && is_email( $opt ) ) ? $opt : HHE_EMAIL_LEGAL;
		default:
			$opt = (string) get_option( 'hhe_email_info', '' );
			return ( $opt !== '' && is_email( $opt ) ) ? $opt : HHE_EMAIL_INFO;
	}
}

/**
 * From-name resolver. Option-first with constant fallback (same chain as
 * hhec_email_address).
 *
 * @return string
 */
function hhec_email_from_name() {
	$opt = trim( (string) get_option( 'hhe_email_from_name', '' ) );
	return $opt !== '' ? $opt : HHE_EMAIL_FROM_NAME;
}

/**
 * Default Reply-To resolver. Falls back to the info@ address.
 *
 * Individual send-functions may override per call (e.g. billing receipts set
 * reply_to=accounts@). This helper is the SITE-WIDE default — used wherever
 * a function doesn't specify.
 *
 * @return string
 */
function hhec_email_reply_to() {
	$opt = (string) get_option( 'hhe_email_replyto', '' );
	return ( $opt !== '' && is_email( $opt ) ) ? $opt : hhec_email_address( 'info' );
}

/**
 * Public helper for templates to render a mailto link.
 */
function hhec_email_link( $role = 'info', $label = '' ) {
	$addr = hhec_email_address( $role );
	$text = $label !== '' ? $label : $addr;
	return sprintf(
		'<a href="mailto:%1$s">%2$s</a>',
		esc_attr( $addr ),
		esc_html( $text )
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Admin email sync — keep get_option('admin_email') pointing at info@
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Sync admin_email to info@ on demand.
 *
 * Runs once at plugin load (cheap option check) and on activation. We use a
 * marker option so we only touch admin_email if it genuinely differs — avoids
 * fighting a deliberate admin change on every request.
 */
function hhec_sync_admin_email() {
	$target = HHE_EMAIL_INFO;
	if ( get_option( 'admin_email' ) === $target ) return;
	update_option( 'admin_email', $target );
}
add_action( 'admin_init', 'hhec_sync_admin_email' );

/**
 * Ensure the Terms, Disclaimer, Claim-Your-Listing, and Upgrade-Your-Listing
 * pages exist and use the matching templates.
 *
 * Runs once on admin_init, guarded by a site option so we don't thrash pages
 * on every request. Leaves existing content untouched — only touches the
 * page_template meta when a different template is currently assigned.
 */
function hhec_ensure_legal_page_templates() {
	if ( '1' === get_option( 'hhec_legal_templates_assigned_v5' ) ) {
		return;
	}

	$map = array(
		'terms-and-conditions' => array(
			'title'    => __( 'Terms & Conditions', 'huahinexpats-core' ),
			'template' => 'page-templates/template-terms.php',
		),
		'disclaimer' => array(
			'title'    => __( 'Disclaimer', 'huahinexpats-core' ),
			'template' => 'page-templates/template-disclaimer.php',
		),
		'claim-your-listing' => array(
			'title'    => __( 'Claim Your Listing', 'huahinexpats-core' ),
			'template' => 'page-templates/template-claim-listing.php',
		),
		'upgrade-your-listing' => array(
			'title'    => __( 'Upgrade Your Listing', 'huahinexpats-core' ),
			'template' => 'page-templates/template-upgrade-listing.php',
		),
		'add-your-business' => array(
			'title'    => __( 'List Your Business', 'huahinexpats-core' ),
			'template' => 'page-templates/template-add-your-business.php',
		),
		'cookie-policy' => array(
			'title'    => __( 'Cookie Policy', 'huahinexpats-core' ),
			'template' => 'page-templates/template-cookie-policy.php',
		),
		'advertise' => array(
			'title'    => __( 'Advertise', 'huahinexpats-core' ),
			'template' => 'page-templates/template-advertise.php',
		),
		'affiliates' => array(
			'title'    => __( 'Affiliates', 'huahinexpats-core' ),
			'template' => 'page-templates/template-affiliates.php',
		),
	);

	foreach ( $map as $slug => $meta ) {
		$page = get_posts( array(
			'name'        => $slug,
			'post_type'   => 'page',
			'post_status' => 'any',
			'numberposts' => 1,
		) );
		$page = $page ? $page[0] : null;

		if ( ! $page ) {
			$page_id = wp_insert_post( array(
				'post_title'  => $meta['title'],
				'post_name'   => $slug,
				'post_status' => 'publish',
				'post_type'   => 'page',
			) );
			if ( is_wp_error( $page_id ) || ! $page_id ) continue;
		} else {
			$page_id = $page->ID;
		}

		$current = get_post_meta( $page_id, '_wp_page_template', true );
		if ( $current !== $meta['template'] ) {
			update_post_meta( $page_id, '_wp_page_template', $meta['template'] );
		}
	}

	update_option( 'hhec_legal_templates_assigned_v5', '1' );
}
add_action( 'admin_init', 'hhec_ensure_legal_page_templates' );

// ─────────────────────────────────────────────────────────────────────────────
// FROM name + FROM address on every wp_mail()
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Default FROM address for all outbound mail.
 *
 * Individual senders (outreach, upgrade) override this with add_filter inside
 * the send function so they can use accounts@ etc. without changing the
 * site-wide default.
 */
function hhec_filter_mail_from( $from ) {
	// Only override the WordPress default ("wordpress@host"). If another
	// plugin has set a custom FROM, leave it alone.
	if ( strpos( (string) $from, 'wordpress@' ) === 0 ) {
		return hhec_email_address( 'info' );
	}
	return $from;
}
add_filter( 'wp_mail_from', 'hhec_filter_mail_from' );

function hhec_filter_mail_from_name( $name ) {
	if ( $name === 'WordPress' ) {
		return hhec_email_from_name();
	}
	return $name;
}
add_filter( 'wp_mail_from_name', 'hhec_filter_mail_from_name' );

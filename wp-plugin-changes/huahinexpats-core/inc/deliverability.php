<?php
/**
 * Email Deliverability — SMTP, DNS auth checks, click + reply tracking
 *
 * Three concerns in one file because they all answer the same operational
 * question: "are our outreach emails actually landing in inboxes?"
 *
 *   1. SMTP integration via phpmailer_init filter
 *   2. DNS authentication probes (SPF, DKIM selector, DMARC) with caching
 *   3. Click tracking REST route + reply webhook REST route
 *   4. Delivery / bounce / reply metric helpers (read from email_log + events)
 *
 * Settings live in wp_options under hhe_smtp_*. Provider presets prefill
 * host/port for SendGrid, SES, Mailgun.
 *
 * @package HuaHinExpats
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Settings accessors
// ─────────────────────────────────────────────────────────────────────────────

function hhec_smtp_settings() {
	return array(
		'enabled'    => '1' === (string) get_option( 'hhe_smtp_enabled', '0' ),
		'provider'   => (string) get_option( 'hhe_smtp_provider', 'custom' ),
		'host'       => (string) get_option( 'hhe_smtp_host', '' ),
		'port'       => (int)    get_option( 'hhe_smtp_port', 587 ),
		'encryption' => (string) get_option( 'hhe_smtp_encryption', 'tls' ),
		'username'   => (string) get_option( 'hhe_smtp_username', '' ),
		'password'   => (string) get_option( 'hhe_smtp_password', '' ),
	);
}

/**
 * Provider host/port presets — used by the settings UI to prefill fields when
 * the operator picks a provider. Returning the array also lets us validate
 * that a chosen provider is one we have a preset for.
 */
function hhec_smtp_provider_presets() {
	return array(
		'postmark' => array( 'host' => 'smtp.postmarkapp.com',                'port' => 587, 'encryption' => 'tls', 'username_hint' => __( 'Server API token (same value goes in password)', 'huahinexpats-core' ) ),
		'sendgrid' => array( 'host' => 'smtp.sendgrid.net',                   'port' => 587, 'encryption' => 'tls', 'username_hint' => __( 'apikey (literal string)', 'huahinexpats-core' ) ),
		'ses'      => array( 'host' => 'email-smtp.us-east-1.amazonaws.com',  'port' => 587, 'encryption' => 'tls', 'username_hint' => __( 'SES SMTP credentials user', 'huahinexpats-core' ) ),
		'mailgun'  => array( 'host' => 'smtp.mailgun.org',                    'port' => 587, 'encryption' => 'tls', 'username_hint' => __( 'postmaster@your.domain', 'huahinexpats-core' ) ),
		'custom'   => array( 'host' => '',                                    'port' => 587, 'encryption' => 'tls', 'username_hint' => '' ),
	);
}

/**
 * Scrub known secret values from a string before display or storage.
 *
 * Used wherever a string that could include credential fragments may
 * surface in the admin UI or get persisted to an option — primarily
 * PHPMailer error messages captured via wp_mail_failed, which can echo
 * SMTP server auth-failure responses verbatim.
 *
 * Replaces, in order:
 *   - hhe_smtp_password value
 *   - hhe_smtp_username value (when it looks like a Postmark token, i.e.
 *     not a plain account name)
 *
 * If neither option is set, the input is returned unchanged.
 *
 * @param string $string
 * @return string
 */
function hhec_smtp_redact_secret( $string ) {
	$string = (string) $string;
	if ( $string === '' ) return $string;

	$secrets = array();

	$pw = (string) get_option( 'hhe_smtp_password', '' );
	if ( $pw !== '' && strlen( $pw ) >= 6 ) {
		$secrets[] = $pw;
	}

	$un = (string) get_option( 'hhe_smtp_username', '' );
	// Only redact username if it looks like a token (no @, no spaces, long).
	// Plain SMTP usernames like "smtp_user" or "apikey" aren't secret on their
	// own and we don't want false-positive redaction in error messages.
	if ( $un !== '' && strlen( $un ) >= 16 && strpos( $un, '@' ) === false && strpos( $un, ' ' ) === false ) {
		$secrets[] = $un;
	}

	// Webhook secrets (reply + bounce). Defence in depth.
	$reply_secret = (string) get_option( 'hhe_email_reply_webhook_secret', '' );
	if ( $reply_secret !== '' && strlen( $reply_secret ) >= 8 ) {
		$secrets[] = $reply_secret;
	}
	$bounce_secret = (string) get_option( 'hhe_smtp_bounce_webhook_secret', '' );
	if ( $bounce_secret !== '' && strlen( $bounce_secret ) >= 8 ) {
		$secrets[] = $bounce_secret;
	}

	if ( ! $secrets ) return $string;

	// Sort by length desc so longer prefixes don't get masked by shorter ones.
	usort( $secrets, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

	return str_replace( $secrets, '[REDACTED]', $string );
}

// ─────────────────────────────────────────────────────────────────────────────
// SMTP — hook PHPMailer
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'phpmailer_init', 'hhec_smtp_setup_phpmailer' );

function hhec_smtp_setup_phpmailer( $phpmailer ) {
	$cfg = hhec_smtp_settings();
	if ( ! $cfg['enabled'] || ! $cfg['host'] || ! $cfg['username'] || ! $cfg['password'] ) return;

	$phpmailer->isSMTP();
	$phpmailer->Host       = $cfg['host'];
	$phpmailer->Port       = (int) $cfg['port'];
	$phpmailer->SMTPAuth   = true;
	$phpmailer->Username   = $cfg['username'];
	$phpmailer->Password   = $cfg['password'];
	$phpmailer->SMTPSecure = ( 'ssl' === $cfg['encryption'] ) ? 'ssl' : ( 'tls' === $cfg['encryption'] ? 'tls' : '' );
	if ( '' === $phpmailer->SMTPSecure ) {
		$phpmailer->SMTPAutoTLS = false;
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// DNS authentication probes (SPF / DKIM / DMARC)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve the domain we should be probing — "from" address domain, falling
 * back to the WP site host. Cached in a transient for 6h.
 */
function hhec_deliverability_domain() {
	$from = function_exists( 'hhec_email_address' ) ? hhec_email_address( 'info' ) : get_option( 'admin_email' );
	if ( strpos( (string) $from, '@' ) !== false ) {
		return strtolower( substr( $from, strpos( $from, '@' ) + 1 ) );
	}
	return strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );
}

/**
 * Run all three checks. Cached so the admin page can render fast — explicit
 * "re-check" action busts the cache.
 *
 * @param bool $force_refresh
 * @return array { domain, spf:[pass:bool, value:string], dkim:[...], dmarc:[...], checked_at }
 */
function hhec_deliverability_checks( $force_refresh = false ) {
	$domain = hhec_deliverability_domain();
	$cache_key = 'hhec_dns_checks_' . md5( $domain );

	if ( ! $force_refresh ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) return $cached;
	}

	$result = array(
		'domain'    => $domain,
		'spf'       => hhec_dns_check_spf( $domain ),
		'dkim'      => hhec_dns_check_dkim( $domain ),
		'dmarc'     => hhec_dns_check_dmarc( $domain ),
		'checked_at'=> current_time( 'mysql' ),
	);

	set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
	return $result;
}

function hhec_dns_check_spf( $domain ) {
	$records = @dns_get_record( $domain, DNS_TXT );
	if ( ! is_array( $records ) ) return array( 'pass' => false, 'value' => '', 'note' => 'lookup_failed' );

	foreach ( $records as $r ) {
		$txt = (string) ( $r['txt'] ?? '' );
		if ( strpos( $txt, 'v=spf1' ) === 0 ) {
			return array( 'pass' => true, 'value' => $txt, 'note' => '' );
		}
	}
	return array( 'pass' => false, 'value' => '', 'note' => 'no_spf_record' );
}

function hhec_dns_check_dmarc( $domain ) {
	$records = @dns_get_record( '_dmarc.' . $domain, DNS_TXT );
	if ( ! is_array( $records ) ) return array( 'pass' => false, 'value' => '', 'note' => 'lookup_failed' );

	foreach ( $records as $r ) {
		$txt = (string) ( $r['txt'] ?? '' );
		if ( strpos( $txt, 'v=DMARC1' ) === 0 ) {
			return array( 'pass' => true, 'value' => $txt, 'note' => '' );
		}
	}
	return array( 'pass' => false, 'value' => '', 'note' => 'no_dmarc_record' );
}

/**
 * DKIM is selector-based — there's no single "is DKIM set?" check. We probe
 * the common provider selectors (sendgrid, mailgun, default) and any custom
 * selector configured by the operator.
 */
function hhec_dns_check_dkim( $domain ) {
	$selectors = array(
		(string) get_option( 'hhe_smtp_dkim_selector', '' ),
		'default', 's1', 'k1',
		'mte1', // SES default
		's1.smtp', 's2.smtp', // Mailgun
		'sg', 's1.sg', 's2.sg', // SendGrid
	);
	$selectors = array_filter( array_unique( $selectors ) );

	foreach ( $selectors as $sel ) {
		$records = @dns_get_record( $sel . '._domainkey.' . $domain, DNS_TXT );
		if ( ! is_array( $records ) ) continue;
		foreach ( $records as $r ) {
			$txt = (string) ( $r['txt'] ?? '' );
			if ( false !== stripos( $txt, 'v=DKIM1' ) || false !== stripos( $txt, 'k=rsa' ) ) {
				return array( 'pass' => true, 'value' => $txt, 'note' => 'selector=' . $sel, 'selector' => $sel );
			}
		}
	}
	return array( 'pass' => false, 'value' => '', 'note' => 'no_matching_selector', 'selector' => '' );
}

// ─────────────────────────────────────────────────────────────────────────────
// Click tracking — wraps outbound URLs through a recording redirect
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build a tracked URL that, when clicked, records an outreach_click event
 * for the listing and 302s to the destination.
 *
 * @param string $dest_url
 * @param int    $listing_id
 * @return string
 */
function hhec_track_click_url( $dest_url, $listing_id ) {
	$listing_id = (int) $listing_id;
	$payload    = base64_encode( $dest_url );
	$token      = wp_hash( $listing_id . '|' . $payload );

	return add_query_arg( array(
		'lid' => $listing_id,
		'd'   => rawurlencode( $payload ),
		't'   => $token,
	), rest_url( 'hhe/v1/email-click' ) );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'hhe/v1', '/email-click', array(
		'methods'             => 'GET',
		'callback'            => 'hhec_handle_email_click',
		'permission_callback' => '__return_true',
	) );

	register_rest_route( 'hhe/v1', '/email-reply', array(
		'methods'             => 'POST',
		'callback'            => 'hhec_handle_email_reply',
		'permission_callback' => '__return_true', // shared-secret check inside
	) );
} );

function hhec_handle_email_click( WP_REST_Request $req ) {
	$lid     = absint( $req->get_param( 'lid' ) );
	$payload = (string) $req->get_param( 'd' );
	$token   = (string) $req->get_param( 't' );

	if ( ! $lid || '' === $payload || '' === $token ) {
		return new WP_REST_Response( array( 'error' => 'bad_request' ), 400 );
	}

	$expected = wp_hash( $lid . '|' . $payload );
	if ( ! hash_equals( $expected, $token ) ) {
		return new WP_REST_Response( array( 'error' => 'bad_token' ), 403 );
	}

	$dest = base64_decode( $payload, true );
	if ( ! $dest || ! wp_http_validate_url( $dest ) ) {
		return new WP_REST_Response( array( 'error' => 'bad_dest' ), 400 );
	}

	if ( function_exists( 'hhec_record_listing_event' ) ) {
		hhec_record_listing_event( $lid, 'outreach_click', array( 'url' => $dest ) );
	}

	wp_redirect( esc_url_raw( $dest ), 302 );
	exit;
}

/**
 * Generic email-reply webhook — providers (SendGrid Inbound Parse, Mailgun
 * Routes, etc.) post here when a recipient replies. We mark the listing as
 * "responded" without further parsing.
 *
 * Auth: shared-secret query param ?secret=… matched against
 * hhe_email_reply_webhook_secret option. Configure once and lock the URL.
 */
function hhec_handle_email_reply( WP_REST_Request $req ) {
	$expected = (string) get_option( 'hhe_email_reply_webhook_secret', '' );
	$got      = (string) $req->get_param( 'secret' );
	if ( '' === $expected || ! hash_equals( $expected, $got ) ) {
		return new WP_REST_Response( array( 'error' => 'bad_secret' ), 403 );
	}

	$body = $req->get_json_params();
	if ( ! is_array( $body ) ) $body = $req->get_params();

	// Provider-agnostic: try common shapes.
	$from = sanitize_email( $body['from']    ?? $body['sender'] ?? '' );
	$msg  = sanitize_textarea_field( (string) ( $body['text'] ?? $body['body-plain'] ?? $body['stripped-text'] ?? '' ) );
	if ( ! is_email( $from ) ) {
		return new WP_REST_Response( array( 'error' => 'bad_from' ), 400 );
	}

	// Find listings with this email and flip status.
	global $wpdb;
	$listing_ids = $wpdb->get_col( $wpdb->prepare( "
		SELECT post_id FROM {$wpdb->postmeta}
		WHERE meta_key = '_hhe_email' AND meta_value = %s
	", strtolower( $from ) ) );

	$flipped = 0;
	foreach ( $listing_ids as $lid ) {
		$lid = (int) $lid;
		if ( function_exists( 'hhec_set_outreach_status' ) ) {
			hhec_set_outreach_status( $lid, 'responded' );
			update_post_meta( $lid, '_hhe_email_reply_at', current_time( 'mysql' ) );
			update_post_meta( $lid, '_hhe_email_reply_excerpt', wp_trim_words( $msg, 30 ) );
			$flipped++;
		}
	}

	return new WP_REST_Response( array( 'matched_listings' => count( $listing_ids ), 'flipped' => $flipped ), 200 );
}

// ─────────────────────────────────────────────────────────────────────────────
// Delivery / reply / click metrics — read from email_log + postmeta
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Aggregate deliverability numbers for the dashboard. Bounces aren't tracked
 * unless the provider posts to a webhook (or sets status='failed' in our log
 * via wp_mail_failed). We surface what we can with honest labels.
 *
 * @return array
 */
function hhec_deliverability_metrics() {
	global $wpdb;
	$log_table = $wpdb->prefix . 'hhe_email_log';

	$cutoff_7  = gmdate( 'Y-m-d H:i:s', time() - 7  * DAY_IN_SECONDS );
	$cutoff_30 = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

	$total_7 = (int) $wpdb->get_var( $wpdb->prepare( "
		SELECT COUNT(*) FROM {$log_table}
		WHERE category IN ('outreach_invitation','outreach_reminder','outreach_final_reminder')
		  AND dry_run = 0
		  AND sent_at >= %s
	", $cutoff_7 ) );

	$delivered_7 = (int) $wpdb->get_var( $wpdb->prepare( "
		SELECT COUNT(*) FROM {$log_table}
		WHERE category IN ('outreach_invitation','outreach_reminder','outreach_final_reminder')
		  AND dry_run = 0 AND status = 'sent'
		  AND sent_at >= %s
	", $cutoff_7 ) );

	$failed_7 = (int) $wpdb->get_var( $wpdb->prepare( "
		SELECT COUNT(*) FROM {$log_table}
		WHERE category IN ('outreach_invitation','outreach_reminder','outreach_final_reminder')
		  AND dry_run = 0 AND status = 'failed'
		  AND sent_at >= %s
	", $cutoff_7 ) );

	$suppressed_7 = (int) $wpdb->get_var( $wpdb->prepare( "
		SELECT COUNT(*) FROM {$log_table}
		WHERE category IN ('outreach_invitation','outreach_reminder','outreach_final_reminder')
		  AND status = 'suppressed'
		  AND sent_at >= %s
	", $cutoff_7 ) );

	// Replies: count listings flipped to responded OR with _hhe_email_reply_at in last 7 days.
	$replies_7 = (int) $wpdb->get_var( $wpdb->prepare( "
		SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
		WHERE meta_key = '_hhe_email_reply_at'
		  AND meta_value >= %s
	", $cutoff_7 ) );

	// Click events from the events table — query loosely (wp_hhe_events not all installs).
	$clicks_7 = 0;
	$events_table = $wpdb->prefix . 'hhe_events';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $events_table ) ) === $events_table ) {
		$clicks_7 = (int) $wpdb->get_var( $wpdb->prepare( "
			SELECT COUNT(*) FROM {$events_table}
			WHERE event_type = 'outreach_click' AND created_at >= %s
		", $cutoff_7 ) );
	}

	$delivery_rate = $total_7 > 0 ? round( ( $delivered_7 / $total_7 ) * 100, 1 ) : 0.0;
	$bounce_rate   = $total_7 > 0 ? round( ( $failed_7    / $total_7 ) * 100, 1 ) : 0.0;
	$reply_rate    = $delivered_7 > 0 ? round( ( $replies_7 / $delivered_7 ) * 100, 1 ) : 0.0;
	$click_rate    = $delivered_7 > 0 ? round( ( $clicks_7  / $delivered_7 ) * 100, 1 ) : 0.0;

	return array(
		'total_7'       => $total_7,
		'delivered_7'   => $delivered_7,
		'failed_7'      => $failed_7,
		'suppressed_7'  => $suppressed_7,
		'replies_7'     => $replies_7,
		'clicks_7'      => $clicks_7,
		'delivery_rate' => $delivery_rate,
		'bounce_rate'   => $bounce_rate,
		'reply_rate'    => $reply_rate,
		'click_rate'    => $click_rate,
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Mark wp_mail failures — bumps log row to status='failed' so bounce metric works
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_mail_failed', 'hhec_mark_log_failed' );

function hhec_mark_log_failed( $error ) {
	global $wpdb;
	$log = $wpdb->prefix . 'hhe_email_log';

	// $error is a WP_Error with the most recent recipient + subject in error_data.
	if ( ! is_wp_error( $error ) ) return;
	$data = $error->get_error_data();
	if ( ! is_array( $data ) ) return;
	$to = '';
	if ( isset( $data['to'] ) ) {
		$to = is_array( $data['to'] ) ? (string) reset( $data['to'] ) : (string) $data['to'];
	}
	$subject = isset( $data['subject'] ) ? (string) $data['subject'] : '';
	if ( ! $to ) return;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update(
		$log,
		array( 'status' => 'failed' ),
		array( 'recipient' => $to, 'subject' => $subject, 'status' => 'sent' )
	);
}

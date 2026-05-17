<?php
/**
 * Stripe — settings, API helpers, Checkout launcher, billing portal,
 * and webhook handler
 *
 * All Stripe communication uses WordPress HTTP API (wp_remote_*) — no
 * SDK dependency. Secrets are stored in wp_options and never exposed to
 * the theme layer.
 *
 * Webhook endpoint: POST /wp-json/hhe/v1/stripe-webhook
 * Checkout launcher: admin-post.php action=hhe_create_checkout
 * Billing portal:    admin-post.php action=hhe_billing_portal
 *
 * @package HuaHinExpats
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Settings helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Current Stripe environment ('test' or 'live'). Defaults to 'test' so a
 * fresh install can never accidentally process real cards.
 *
 * @return string
 */
function hhec_get_stripe_mode() {
	$mode = (string) get_option( 'hhe_stripe_mode', 'test' );
	return in_array( $mode, array( 'test', 'live' ), true ) ? $mode : 'test';
}

/**
 * Settings keys whose value depends on the active mode (separate test/live
 * storage). Display labels and the mode itself are shared.
 *
 * @return string[]
 */
function hhec_stripe_mode_scoped_keys() {
	return array(
		'secret_key',
		'webhook_secret',
		'price_verified_monthly',
		'price_verified_annual',
		'price_premium_monthly',
		'price_premium_annual',
		'price_premium_plus_monthly',
		'price_premium_plus_annual',
	);
}

/**
 * Read a single Stripe setting from wp_options.
 *
 * Mode-scoped keys resolve based on hhec_get_stripe_mode():
 *   - test → reads hhe_stripe_<key> (legacy slot reused as the test set)
 *   - live → reads hhe_stripe_live_<key>
 *
 * Mode-agnostic keys (display labels) read hhe_stripe_<key> directly.
 *
 * @param  string $key  Suffix after hhe_stripe_.
 * @return string
 */
function hhec_get_stripe_setting( $key ) {
	if ( in_array( $key, hhec_stripe_mode_scoped_keys(), true ) ) {
		$mode = hhec_get_stripe_mode();
		$opt  = ( 'live' === $mode ) ? 'hhe_stripe_live_' . $key : 'hhe_stripe_' . $key;
		return (string) get_option( $opt, '' );
	}
	return (string) get_option( 'hhe_stripe_' . $key, '' );
}

/**
 * Map a Stripe Price ID to an internal tier slug.
 * Returns null if the price ID is not recognised.
 *
 * @param  string      $price_id
 * @return string|null  'verified' | 'premium' | 'premium_plus' | null
 */
function hhec_tier_from_price_id( $price_id ) {
	if ( ! $price_id ) return null;
	$map = array(
		hhec_get_stripe_setting( 'price_verified_monthly' )     => 'verified',
		hhec_get_stripe_setting( 'price_verified_annual' )      => 'verified',
		hhec_get_stripe_setting( 'price_premium_monthly' )      => 'premium',
		hhec_get_stripe_setting( 'price_premium_annual' )       => 'premium',
		hhec_get_stripe_setting( 'price_premium_plus_monthly' ) => 'premium_plus',
		hhec_get_stripe_setting( 'price_premium_plus_annual' )  => 'premium_plus',
	);
	// Remove empty keys to avoid false matches.
	$map = array_filter( $map, 'strlen', ARRAY_FILTER_USE_KEY );
	return $map[ $price_id ] ?? null;
}

// ─────────────────────────────────────────────────────────────────────────────
// HTTP API wrapper
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Make an authenticated request to the Stripe API.
 * Returns decoded response array or WP_Error on failure.
 *
 * Pass $idempotency_key on POSTs to prevent duplicate side-effects when the
 * caller retries (double-click, network retry, etc.). Stripe caches the first
 * response for 24h against the key — same key + same params = cached response,
 * same key + different params = error from Stripe. GETs are inherently
 * idempotent so the key is ignored on non-POSTs.
 *
 * @param string $method           GET | POST
 * @param string $endpoint         Path after /v1/, e.g. 'checkout/sessions'
 * @param array  $body             Request body (POST) or query string (GET)
 * @param string $idempotency_key  Optional; sets Idempotency-Key header on POST.
 * @return array|WP_Error
 */
function hhec_stripe_api_request( $method, $endpoint, array $body = array(), $idempotency_key = '' ) {
	$secret_key = hhec_get_stripe_setting( 'secret_key' );
	if ( ! $secret_key ) {
		return new WP_Error( 'stripe_no_key', __( 'Stripe secret key is not configured.', 'huahinexpats-core' ) );
	}

	$url     = 'https://api.stripe.com/v1/' . ltrim( $endpoint, '/' );
	$headers = array(
		'Authorization' => 'Bearer ' . $secret_key,
		'Stripe-Version' => '2023-10-16',
	);

	if ( strtoupper( $method ) === 'POST' ) {
		$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$response = wp_remote_post( $url, array(
			'headers' => $headers,
			'body'    => $body,
			'timeout' => 30,
		) );
	} else {
		if ( ! empty( $body ) ) {
			$url .= '?' . http_build_query( $body );
		}
		$response = wp_remote_get( $url, array(
			'headers' => $headers,
			'timeout' => 30,
		) );
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code >= 400 ) {
		$message = $data['error']['message'] ?? __( 'Unknown Stripe error.', 'huahinexpats-core' );
		return new WP_Error( 'stripe_api_error', $message, array( 'http_status' => $code ) );
	}

	return $data;
}

// ─────────────────────────────────────────────────────────────────────────────
// Stripe Checkout Session creation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a Stripe Checkout Session for a subscription upgrade.
 *
 * @param  int    $listing_id
 * @param  string $tier      'premium' | 'premium_plus'
 * @param  string $interval  'monthly' | 'annual'
 * @param  int    $user_id
 * @return array|WP_Error  Full Stripe session object or error.
 */
function hhec_create_stripe_checkout_session( $listing_id, $tier, $interval, $user_id ) {
	$allowed_tiers     = array( 'verified', 'premium', 'premium_plus' );
	$allowed_intervals = array( 'monthly', 'annual' );

	if ( ! in_array( $tier, $allowed_tiers, true ) || ! in_array( $interval, $allowed_intervals, true ) ) {
		return new WP_Error( 'stripe_invalid_params', __( 'Invalid tier or interval.', 'huahinexpats-core' ) );
	}

	$price_key = 'price_' . $tier . '_' . $interval;
	$price_id  = hhec_get_stripe_setting( $price_key );
	if ( ! $price_id ) {
		return new WP_Error(
			'stripe_no_price',
			sprintf(
				/* translators: 1: tier, 2: interval */
				__( 'Stripe Price ID not configured for %1$s / %2$s.', 'huahinexpats-core' ),
				$tier,
				$interval
			)
		);
	}

	$user = get_user_by( 'id', absint( $user_id ) );
	if ( ! $user ) {
		return new WP_Error( 'stripe_no_user', __( 'User not found.', 'huahinexpats-core' ) );
	}

	// Retrieve or create Stripe customer. Idempotency-Key is stable per WP user
	// so a retried customer-creation call returns the same Stripe customer.
	$customer_id = get_user_meta( $user_id, '_hhe_stripe_customer_id', true );
	if ( ! $customer_id ) {
		$customer = hhec_stripe_api_request(
			'POST',
			'customers',
			array(
				'email'                => $user->user_email,
				'name'                 => $user->display_name,
				'metadata[wp_user_id]' => $user_id,
				'metadata[site_url]'   => home_url(),
			),
			'hhe_customer_user_' . absint( $user_id )
		);
		if ( is_wp_error( $customer ) ) return $customer;
		$customer_id = $customer['id'];
		update_user_meta( $user_id, '_hhe_stripe_customer_id', sanitize_text_field( $customer_id ) );
	}

	$portal_url  = hhec_owner_dashboard_url();
	$success_url = add_query_arg(
		array( 'stripe' => 'success', 'listing_id' => absint( $listing_id ) ),
		$portal_url
	);
	$cancel_url  = add_query_arg( 'stripe', 'cancelled', $portal_url );

	// Idempotency-Key bucketed per listing+tier+interval+UTC date — same-day
	// double-clicks return the same Stripe Checkout Session; next-day retries
	// (after the 24h Stripe Checkout expiry) get a fresh session naturally.
	$idem_key = sprintf(
		'hhe_checkout_%d_%s_%s_%s',
		absint( $listing_id ),
		$tier,
		$interval,
		gmdate( 'Y-m-d' )
	);

	return hhec_stripe_api_request(
		'POST',
		'checkout/sessions',
		array(
			'mode'                                  => 'subscription',
			'customer'                              => $customer_id,
			'line_items[0][price]'                  => $price_id,
			'line_items[0][quantity]'               => 1,
			'success_url'                           => $success_url,
			'cancel_url'                            => $cancel_url,
			'client_reference_id'                   => absint( $listing_id ),
			'metadata[listing_id]'                  => absint( $listing_id ),
			'metadata[tier]'                        => $tier,
			'metadata[user_id]'                     => $user_id,
			'metadata[site_url]'                    => home_url(),
		),
		$idem_key
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Checkout launcher — admin-post.php
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_post_hhe_create_checkout', 'hhec_handle_create_checkout' );

function hhec_handle_create_checkout() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( hhec_owner_dashboard_url() ) );
		exit;
	}

	$user_id    = get_current_user_id();
	$listing_id = absint( $_POST['listing_id'] ?? 0 );
	$tier       = sanitize_key( $_POST['tier'] ?? '' );
	$interval   = sanitize_key( $_POST['interval'] ?? '' );
	$portal_url = hhec_owner_dashboard_url();

	// Nonce.
	if ( ! $listing_id
		|| ! isset( $_POST['hhe_checkout_nonce'] )
		|| ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['hhe_checkout_nonce'] ) ),
			'hhe_checkout_' . $listing_id
		)
	) {
		wp_safe_redirect( add_query_arg( 'stripe', 'error', $portal_url ) );
		exit;
	}

	// Tier + interval whitelist.
	if ( ! in_array( $tier, array( 'premium', 'premium_plus' ), true )
		|| ! in_array( $interval, array( 'monthly', 'annual' ), true )
	) {
		wp_safe_redirect( add_query_arg( 'stripe', 'error', $portal_url ) );
		exit;
	}

	// Ownership check — must be the approved owner of this listing.
	if ( ! current_user_can( 'manage_options' )
		&& ! hhec_user_owns_listing( $user_id, $listing_id )
	) {
		wp_safe_redirect( $portal_url );
		exit;
	}

	$session = hhec_create_stripe_checkout_session( $listing_id, $tier, $interval, $user_id );

	if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
		wp_safe_redirect( add_query_arg( 'stripe', 'error', $portal_url ) );
		exit;
	}

	// Phase 8 analytics: record checkout_started before redirecting off-site.
	if ( function_exists( 'hhec_record_listing_event' ) ) {
		hhec_record_listing_event( $listing_id, 'checkout_started', array( 'tier' => $tier, 'interval' => $interval ) );
	}

	// External redirect — wp_redirect() not wp_safe_redirect() (Stripe domain).
	wp_redirect( esc_url_raw( $session['url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Billing portal launcher — admin-post.php
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_post_hhe_billing_portal', 'hhec_handle_billing_portal' );

function hhec_handle_billing_portal() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( hhec_owner_dashboard_url() ) );
		exit;
	}

	$user_id    = get_current_user_id();
	$listing_id = absint( $_POST['listing_id'] ?? 0 );
	$portal_url = hhec_owner_dashboard_url();

	if ( ! $listing_id
		|| ! isset( $_POST['hhe_billing_portal_nonce'] )
		|| ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['hhe_billing_portal_nonce'] ) ),
			'hhe_billing_portal_' . $listing_id
		)
	) {
		wp_safe_redirect( $portal_url );
		exit;
	}

	if ( ! current_user_can( 'manage_options' )
		&& ! hhec_user_owns_listing( $user_id, $listing_id )
	) {
		wp_safe_redirect( $portal_url );
		exit;
	}

	$customer_id = get_user_meta( $user_id, '_hhe_stripe_customer_id', true );
	if ( ! $customer_id ) {
		wp_safe_redirect( add_query_arg( 'stripe', 'no_billing', $portal_url ) );
		exit;
	}

	$session = hhec_stripe_api_request( 'POST', 'billing_portal/sessions', array(
		'customer'   => $customer_id,
		'return_url' => $portal_url,
	) );

	if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
		wp_safe_redirect( add_query_arg( 'stripe', 'error', $portal_url ) );
		exit;
	}

	wp_redirect( esc_url_raw( $session['url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Stripe Webhook — REST endpoint
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', 'hhec_register_stripe_webhook_route' );

function hhec_register_stripe_webhook_route() {
	register_rest_route( 'hhe/v1', '/stripe-webhook', array(
		'methods'             => 'POST',
		'callback'            => 'hhec_handle_stripe_webhook',
		'permission_callback' => '__return_true', // auth via Stripe-Signature header
	) );
}

function hhec_handle_stripe_webhook( WP_REST_Request $request ) {
	$payload    = $request->get_body();
	$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$secret     = hhec_get_stripe_setting( 'webhook_secret' );

	if ( ! $secret ) {
		return new WP_REST_Response( array( 'error' => 'Webhook secret not configured.' ), 500 );
	}

	// Signature verification ALWAYS comes before event de-dupe so unsigned
	// payloads can't probe the dedup log and never reach the dispatcher.
	if ( ! hhec_stripe_verify_signature( $payload, $sig_header, $secret ) ) {
		return new WP_REST_Response( array( 'error' => 'Signature verification failed.' ), 400 );
	}

	$event = json_decode( $payload, true );
	if ( empty( $event['type'] ) ) {
		return new WP_REST_Response( array( 'error' => 'Invalid event payload.' ), 400 );
	}

	// Event de-dup: Stripe retries on 5xx and occasionally double-fires on 200.
	// Most handlers are idempotent by design (UPSERT pattern, gated welcome email)
	// but the dedup log prevents secondary effects like duplicate analytics rows.
	$event_id = isset( $event['id'] ) ? sanitize_text_field( $event['id'] ) : '';
	if ( $event_id && hhec_stripe_event_seen( $event_id ) ) {
		// Return 200 so Stripe stops retrying; signal dedup in the body for our logs.
		return new WP_REST_Response( array( 'received' => true, 'deduplicated' => true ), 200 );
	}

	hhec_process_stripe_event( $event );

	if ( $event_id ) {
		hhec_stripe_record_event( $event_id );
	}

	// Bump the timestamp the Revenue panel uses for the "webhook event seen"
	// readiness check. Verified-only — invalid signatures never reach here.
	update_option( 'hhe_stripe_last_webhook_at', current_time( 'mysql', true ), false );

	return new WP_REST_Response( array( 'received' => true ), 200 );
}

// ─────────────────────────────────────────────────────────────────────────────
// Webhook event de-duplication
//
// Storage: wp_options 'hhe_stripe_processed_event_ids' as { event_id => unix_ts }.
// Autoload disabled to keep the option off the boot path (only the webhook
// receiver reads it). Trimmed to the most recent 30 days, hard-capped at 1000
// entries to bound option size (~30 KB max).
// ─────────────────────────────────────────────────────────────────────────────

const HHEC_STRIPE_DEDUP_OPTION    = 'hhe_stripe_processed_event_ids';
const HHEC_STRIPE_DEDUP_RETENTION = 30 * DAY_IN_SECONDS;
const HHEC_STRIPE_DEDUP_HARD_CAP  = 1000;

function hhec_stripe_event_seen( $event_id ) {
	if ( ! $event_id ) return false;
	$log = get_option( HHEC_STRIPE_DEDUP_OPTION, array() );
	return is_array( $log ) && isset( $log[ $event_id ] );
}

function hhec_stripe_record_event( $event_id ) {
	if ( ! $event_id ) return;

	$log = get_option( HHEC_STRIPE_DEDUP_OPTION, array() );
	if ( ! is_array( $log ) ) $log = array();

	$log[ $event_id ] = time();

	// Drop entries older than the retention window.
	$cutoff = time() - HHEC_STRIPE_DEDUP_RETENTION;
	foreach ( $log as $eid => $ts ) {
		if ( (int) $ts < $cutoff ) {
			unset( $log[ $eid ] );
		}
	}

	// Hard cap on entries — keep the most recent if we somehow exceed it.
	if ( count( $log ) > HHEC_STRIPE_DEDUP_HARD_CAP ) {
		asort( $log ); // ascending by timestamp
		$log = array_slice( $log, -HHEC_STRIPE_DEDUP_HARD_CAP, null, true );
	}

	update_option( HHEC_STRIPE_DEDUP_OPTION, $log, false );
}

/**
 * Verify a Stripe webhook signature using HMAC-SHA256.
 * Returns false if invalid or timestamp is >5 minutes old.
 *
 * @param  string $payload     Raw request body.
 * @param  string $sig_header  Value of Stripe-Signature header.
 * @param  string $secret      Webhook signing secret.
 * @return bool
 */
function hhec_stripe_verify_signature( $payload, $sig_header, $secret ) {
	if ( ! $sig_header || ! $secret ) return false;

	$parts     = explode( ',', $sig_header );
	$timestamp = null;
	$sigs      = array();

	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( strncmp( $part, 't=', 2 ) === 0 ) {
			$timestamp = (int) substr( $part, 2 );
		} elseif ( strncmp( $part, 'v1=', 3 ) === 0 ) {
			$sigs[] = substr( $part, 3 );
		}
	}

	if ( ! $timestamp || empty( $sigs ) ) return false;

	// Reject events older than 5 minutes.
	if ( abs( time() - $timestamp ) > 300 ) return false;

	$signed_payload = $timestamp . '.' . $payload;
	$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

	foreach ( $sigs as $sig ) {
		if ( hash_equals( $expected, $sig ) ) return true;
	}

	return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// Webhook event dispatcher
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Route a verified Stripe event to the appropriate handler.
 * All handlers are idempotent — safe to call multiple times.
 *
 * @param array $event Decoded event object.
 */
function hhec_process_stripe_event( array $event ) {
	$type   = $event['type'] ?? '';
	$object = $event['data']['object'] ?? array();

	switch ( $type ) {
		case 'checkout.session.completed':
			hhec_webhook_checkout_completed( $object );
			break;
		case 'invoice.payment_succeeded':
			hhec_webhook_invoice_paid( $object );
			break;
		case 'customer.subscription.updated':
			hhec_webhook_subscription_updated( $object );
			break;
		case 'customer.subscription.deleted':
			hhec_webhook_subscription_deleted( $object );
			break;
		case 'invoice.payment_failed':
			hhec_webhook_invoice_failed( $object );
			break;
		case 'charge.refunded':
			hhec_webhook_charge_refunded( $object );
			break;
		case 'charge.dispute.created':
			hhec_webhook_charge_disputed( $object );
			break;
		// Unknown events are silently ignored — return 200 so Stripe stops retrying.
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Individual event handlers
// ─────────────────────────────────────────────────────────────────────────────

function hhec_webhook_checkout_completed( array $session ) {
	// Only handle subscription-mode checkouts.
	if ( ( $session['mode'] ?? '' ) !== 'subscription' ) return;

	$stripe_sub_id  = $session['subscription'] ?? '';
	$customer_id    = $session['customer'] ?? '';
	$listing_id     = absint( $session['metadata']['listing_id'] ?? $session['client_reference_id'] ?? 0 );
	$tier           = sanitize_key( $session['metadata']['tier'] ?? '' );
	$user_id        = absint( $session['metadata']['user_id'] ?? 0 );
	$is_first_time  = false; // Track if this is the user's first paid checkout for this listing.

	if ( $listing_id ) {
		$is_first_time = ! get_post_meta( $listing_id, '_hhe_upgrade_email_sent', true );
	}

	if ( ! $stripe_sub_id || ! $listing_id || ! $tier ) return;

	// Retrieve subscription to get current period dates.
	$sub = hhec_stripe_api_request( 'GET', 'subscriptions/' . $stripe_sub_id );
	if ( is_wp_error( $sub ) ) return;

	$price_id    = $sub['items']['data'][0]['price']['id'] ?? '';
	$period_start = absint( $sub['current_period_start'] ?? 0 );
	$period_end   = absint( $sub['current_period_end'] ?? 0 );

	// Resolve tier from price ID if metadata tier is missing or unrecognised.
	if ( ! in_array( $tier, array( 'verified', 'premium', 'premium_plus' ), true ) ) {
		$tier = hhec_tier_from_price_id( $price_id ) ?: 'premium';
	}

	hhec_upsert_subscription( array(
		'listing_id'             => $listing_id,
		'user_id'                => $user_id,
		'stripe_customer_id'     => sanitize_text_field( $customer_id ),
		'stripe_subscription_id' => sanitize_text_field( $stripe_sub_id ),
		'stripe_price_id'        => sanitize_text_field( $price_id ),
		'tier'                   => $tier,
		'status'                 => 'active',
		'current_period_start'   => $period_start ? gmdate( 'Y-m-d H:i:s', $period_start ) : null,
		'current_period_end'     => $period_end   ? gmdate( 'Y-m-d H:i:s', $period_end ) : null,
	) );

	// Store Stripe customer ID on the WP user for future checkouts.
	if ( $user_id && $customer_id ) {
		update_user_meta( $user_id, '_hhe_stripe_customer_id', sanitize_text_field( $customer_id ) );
	}

	hhec_apply_subscription_tier( $listing_id, $tier, $period_end );

	// Phase 8 analytics: record payment_completed.
	if ( function_exists( 'hhec_record_listing_event' ) ) {
		hhec_record_listing_event( $listing_id, 'payment_completed', array( 'tier' => $tier ) );
	}

	// Send the "your listing is now upgraded" email — once per listing,
	// gated by _hhe_upgrade_email_sent so resubscribes don't re-trigger.
	if ( $is_first_time && $user_id ) {
		hhec_send_upgrade_success_email( $listing_id, $tier, $user_id, $period_end );
		update_post_meta( $listing_id, '_hhe_upgrade_email_sent', current_time( 'mysql' ) );
	}
}

/**
 * Email sent the first time a listing transitions to a paid tier. Cancellations
 * don't clear the flag — re-upgrades after cancel won't double-send. Reset by
 * deleting _hhe_upgrade_email_sent on a listing if you want to re-fire.
 */
function hhec_send_upgrade_success_email( $listing_id, $tier, $user_id, $period_end_unix ) {
	$user = get_user_by( 'id', absint( $user_id ) );
	if ( ! $user ) return;

	$listing_title = get_the_title( (int) $listing_id );
	$dashboard_url = hhec_owner_dashboard_url();
	$pricing_url   = home_url( '/pricing/' );
	$site_name     = get_bloginfo( 'name' );
	$tier_label_map = array(
		'premium_plus' => __( 'Premium Plus', 'huahinexpats-core' ),
		'premium'      => __( 'Premium', 'huahinexpats-core' ),
		'verified'     => __( 'Verified', 'huahinexpats-core' ),
	);
	$tier_label    = $tier_label_map[ $tier ] ?? __( 'Premium', 'huahinexpats-core' );
	$next_billing  = $period_end_unix > 0 ? date_i18n( get_option( 'date_format' ), $period_end_unix ) : '';

	$placement_map = array(
		'premium_plus' => __( 'Top placement on category pages', 'huahinexpats-core' ),
		'premium'      => __( 'Boosted ranking in your category', 'huahinexpats-core' ),
		'verified'     => __( 'Priority above free listings in your category', 'huahinexpats-core' ),
	);
	$gallery_map = array(
		'premium_plus' => __( 'Up to 12 photos in your gallery', 'huahinexpats-core' ),
		'premium'      => __( 'Up to 8 photos in your gallery', 'huahinexpats-core' ),
		'verified'     => __( 'Up to 3 photos in your gallery', 'huahinexpats-core' ),
	);

	/* translators: 1: listing name, 2: tier label */
	$subject = sprintf( __( 'Your listing is now upgraded, %1$s · %2$s', 'huahinexpats-core' ), $listing_title, $tier_label );

	$lines = array(
		sprintf( __( 'Hi %s,', 'huahinexpats-core' ), $user->display_name ?: $user->user_login ),
		'',
		sprintf(
			/* translators: 1: listing name, 2: tier label */
			__( 'Great news, your listing "%1$s" is now upgraded to %2$s.', 'huahinexpats-core' ),
			$listing_title,
			$tier_label
		),
		'',
		__( 'What changes immediately:', 'huahinexpats-core' ),
		'  • ' . ( $placement_map[ $tier ] ?? $placement_map['premium'] ),
		'  • ' . ( $gallery_map[ $tier ] ?? $gallery_map['premium'] ),
		'  • ' . __( 'Highlighted card with a paid-tier badge', 'huahinexpats-core' ),
		'  • ' . __( 'Priority email support', 'huahinexpats-core' ),
		'',
		$next_billing
			? sprintf( __( 'Your next billing date is %s. Cancel any time from your dashboard.', 'huahinexpats-core' ), $next_billing )
			: __( 'Cancel any time from your dashboard.', 'huahinexpats-core' ),
		'',
		__( 'Manage your listing:', 'huahinexpats-core' ),
		'  ' . $dashboard_url,
		'',
		__( 'Pricing details:', 'huahinexpats-core' ),
		'  ' . $pricing_url,
		'',
		'---',
		sprintf( __( 'The %s team', 'huahinexpats-core' ), $site_name ),
	);

	// Route through hhec_email_send() so the receipt sends from accounts@
	// (Postmark routing & reply-to alignment), respects suppression, and
	// is logged with category billing_upgrade_success. respects_test=false
	// keeps billing-critical mail flowing even when outreach test mode is on.
	if ( function_exists( 'hhec_email_send' ) ) {
		hhec_email_send( array(
			'to'            => $user->user_email,
			'subject'       => $subject,
			'body_text'     => implode( "\n", $lines ),
			'from_role'     => 'accounts',
			'reply_to'      => hhec_email_address( 'accounts' ),
			'category'      => 'billing_upgrade_success',
			'listing_id'    => (int) $listing_id,
			'respects_test' => false,
			'respects_supp' => false,
		) );
	} else {
		wp_mail(
			$user->user_email,
			$subject,
			implode( "\n", $lines ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}
}

function hhec_webhook_invoice_paid( array $invoice ) {
	$stripe_sub_id = $invoice['subscription'] ?? '';
	if ( ! $stripe_sub_id ) return; // one-time payment, ignore

	$sub = hhec_stripe_api_request( 'GET', 'subscriptions/' . $stripe_sub_id );
	if ( is_wp_error( $sub ) ) return;

	$period_end = absint( $sub['current_period_end'] ?? 0 );
	$price_id   = $sub['items']['data'][0]['price']['id'] ?? '';
	$existing   = hhec_get_subscription_by_stripe_id( $stripe_sub_id );

	if ( ! $existing ) return; // subscription not yet in our table (checkout event will arrive separately)

	$tier = hhec_tier_from_price_id( $price_id ) ?: $existing->tier;

	hhec_upsert_subscription( array(
		'stripe_subscription_id' => $stripe_sub_id,
		'stripe_price_id'        => sanitize_text_field( $price_id ),
		'tier'                   => $tier,
		'status'                 => 'active',
		'current_period_end'     => $period_end ? gmdate( 'Y-m-d H:i:s', $period_end ) : null,
	) );

	hhec_apply_subscription_tier( (int) $existing->listing_id, $tier, $period_end );
}

function hhec_webhook_subscription_updated( array $sub ) {
	$stripe_sub_id = $sub['id'] ?? '';
	if ( ! $stripe_sub_id ) return;

	$existing = hhec_get_subscription_by_stripe_id( $stripe_sub_id );
	if ( ! $existing ) return;

	$price_id   = $sub['items']['data'][0]['price']['id'] ?? $existing->stripe_price_id;
	$period_end = absint( $sub['current_period_end'] ?? 0 );
	$tier       = hhec_tier_from_price_id( $price_id ) ?: $existing->tier;

	// Map Stripe subscription status to our status.
	$stripe_status = $sub['status'] ?? '';
	$status_map    = array(
		'active'            => 'active',
		'past_due'          => 'past_due',
		'canceled'          => 'cancelled',
		'incomplete'        => 'past_due',
		'incomplete_expired'=> 'expired',
		'unpaid'            => 'past_due',
	);
	$our_status = $status_map[ $stripe_status ] ?? $existing->status;

	hhec_upsert_subscription( array(
		'stripe_subscription_id' => $stripe_sub_id,
		'stripe_price_id'        => sanitize_text_field( $price_id ),
		'tier'                   => $tier,
		'status'                 => $our_status,
		'current_period_end'     => $period_end ? gmdate( 'Y-m-d H:i:s', $period_end ) : null,
	) );

	if ( in_array( $our_status, array( 'active', 'past_due' ), true ) ) {
		hhec_apply_subscription_tier( (int) $existing->listing_id, $tier, $period_end );
	}
}

function hhec_webhook_subscription_deleted( array $sub ) {
	$stripe_sub_id = $sub['id'] ?? '';
	if ( ! $stripe_sub_id ) return;

	$existing = hhec_get_subscription_by_stripe_id( $stripe_sub_id );
	if ( ! $existing ) return;

	hhec_upsert_subscription( array(
		'stripe_subscription_id' => $stripe_sub_id,
		'status'                 => 'cancelled',
	) );

	hhec_apply_subscription_tier( (int) $existing->listing_id, 'free', 0 );
}

function hhec_webhook_invoice_failed( array $invoice ) {
	$stripe_sub_id = $invoice['subscription'] ?? '';
	if ( ! $stripe_sub_id ) return;

	$existing = hhec_get_subscription_by_stripe_id( $stripe_sub_id );
	if ( ! $existing ) return;

	// Mark as past_due only — do not immediately downgrade.
	// The daily cron will hard-downgrade once _hhe_premium_expires passes.
	hhec_upsert_subscription( array(
		'stripe_subscription_id' => $stripe_sub_id,
		'status'                 => 'past_due',
	) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Phase 3 — Refund + dispute handling
//
// Both handlers route through:
//   1. signature verification (already enforced upstream)
//   2. Phase 2 event-id dedup (already enforced upstream)
//   3. listing resolution via metadata → invoice → local sub table
//   4. side effect (downgrade for full refund only) + admin email + analytics event
//
// Ambiguous mappings never guess — they email the operator and return without
// touching listing state. The same applies if the related invoice or charge
// can't be fetched from the Stripe API.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve a listing_id from a Stripe charge object.
 *
 * Order: charge.metadata.listing_id → invoice.subscription → local sub table.
 * Returns null when the mapping is ambiguous.
 */
function hhec_stripe_resolve_listing_from_charge( array $charge ) {
	// 1. Direct charge metadata (rare — we don't set this normally, but respect it if present).
	$lid = absint( $charge['metadata']['listing_id'] ?? 0 );
	if ( $lid ) return $lid;

	// 2. Subscription charges have an invoice → fetch it → get subscription → look up local table.
	$invoice_id = isset( $charge['invoice'] ) ? sanitize_text_field( $charge['invoice'] ) : '';
	if ( $invoice_id ) {
		$invoice = hhec_stripe_api_request( 'GET', 'invoices/' . $invoice_id );
		if ( ! is_wp_error( $invoice ) && ! empty( $invoice['subscription'] ) ) {
			$sub = hhec_get_subscription_by_stripe_id( sanitize_text_field( $invoice['subscription'] ) );
			if ( $sub ) {
				return (int) $sub->listing_id;
			}
		}
	}

	return null;
}

/**
 * Resolve a listing_id from a Stripe dispute object — chases dispute → charge.
 */
function hhec_stripe_resolve_listing_from_dispute( array $dispute ) {
	$charge_id = isset( $dispute['charge'] ) ? sanitize_text_field( $dispute['charge'] ) : '';
	if ( ! $charge_id ) return null;

	$charge = hhec_stripe_api_request( 'GET', 'charges/' . $charge_id );
	if ( is_wp_error( $charge ) ) return null;

	return hhec_stripe_resolve_listing_from_charge( $charge );
}

/**
 * Find the local subscription row for a given charge.
 * Returns the row object (with id, listing_id, stripe_subscription_id, etc.)
 * or null if not resolvable.
 */
function hhec_stripe_subscription_from_charge( array $charge ) {
	$invoice_id = isset( $charge['invoice'] ) ? sanitize_text_field( $charge['invoice'] ) : '';
	if ( ! $invoice_id ) return null;

	$invoice = hhec_stripe_api_request( 'GET', 'invoices/' . $invoice_id );
	if ( is_wp_error( $invoice ) || empty( $invoice['subscription'] ) ) return null;

	return hhec_get_subscription_by_stripe_id( sanitize_text_field( $invoice['subscription'] ) );
}

/**
 * charge.refunded handler.
 *
 * Full refund (refunded === true OR amount_refunded === amount): downgrade to free,
 * mark local sub cancelled, email admin.
 *
 * Partial refund: log + email admin only. Do NOT change tier.
 */
function hhec_webhook_charge_refunded( array $charge ) {
	$listing_id = hhec_stripe_resolve_listing_from_charge( $charge );

	if ( ! $listing_id ) {
		hhec_send_admin_notice_unmapped_event( 'charge.refunded', $charge );
		return;
	}

	$amount          = (int) ( $charge['amount'] ?? 0 );
	$amount_refunded = (int) ( $charge['amount_refunded'] ?? 0 );
	$is_full         = ! empty( $charge['refunded'] ) || ( $amount > 0 && $amount === $amount_refunded );

	if ( $is_full ) {
		// Mark the local subscription cancelled if we can find it.
		$sub_row = hhec_stripe_subscription_from_charge( $charge );
		if ( $sub_row && ! empty( $sub_row->stripe_subscription_id ) ) {
			hhec_upsert_subscription( array(
				'stripe_subscription_id' => sanitize_text_field( $sub_row->stripe_subscription_id ),
				'status'                 => 'cancelled',
			) );
		}

		// Downgrade the listing to free immediately for full refunds.
		hhec_apply_subscription_tier( $listing_id, 'free', 0 );
	}

	if ( function_exists( 'hhec_record_listing_event' ) ) {
		hhec_record_listing_event( $listing_id, 'payment_refunded', array(
			'charge_id'       => $charge['id'] ?? '',
			'amount'          => $amount,
			'amount_refunded' => $amount_refunded,
			'currency'        => $charge['currency'] ?? '',
			'full'            => $is_full ? 1 : 0,
		) );
	}

	hhec_send_admin_notice_refund( $listing_id, $charge, $is_full );
}

/**
 * charge.dispute.created handler.
 *
 * Marks the local subscription past_due. Does NOT auto-downgrade — the operator
 * decides after Stripe's dispute resolution. The daily premium-expiry cron will
 * downgrade naturally if the period expires before resolution.
 */
function hhec_webhook_charge_disputed( array $dispute ) {
	$listing_id = hhec_stripe_resolve_listing_from_dispute( $dispute );

	if ( ! $listing_id ) {
		hhec_send_admin_notice_unmapped_event( 'charge.dispute.created', $dispute );
		return;
	}

	// Mark sub past_due if we can chase the linkage.
	$charge_id = isset( $dispute['charge'] ) ? sanitize_text_field( $dispute['charge'] ) : '';
	if ( $charge_id ) {
		$charge = hhec_stripe_api_request( 'GET', 'charges/' . $charge_id );
		if ( ! is_wp_error( $charge ) ) {
			$sub_row = hhec_stripe_subscription_from_charge( $charge );
			if ( $sub_row && ! empty( $sub_row->stripe_subscription_id ) ) {
				hhec_upsert_subscription( array(
					'stripe_subscription_id' => sanitize_text_field( $sub_row->stripe_subscription_id ),
					'status'                 => 'past_due',
				) );
			}
		}
	}

	if ( function_exists( 'hhec_record_listing_event' ) ) {
		hhec_record_listing_event( $listing_id, 'payment_disputed', array(
			'dispute_id' => $dispute['id'] ?? '',
			'charge_id'  => $dispute['charge'] ?? '',
			'amount'     => (int) ( $dispute['amount'] ?? 0 ),
			'currency'   => $dispute['currency'] ?? '',
			'reason'     => $dispute['reason'] ?? '',
			'status'     => $dispute['status'] ?? '',
		) );
	}

	hhec_send_admin_notice_dispute( $listing_id, $dispute );
}

// ─── Admin notice emails ─────────────────────────────────────────────────────

function hhec_send_admin_notice_refund( $listing_id, array $charge, $is_full ) {
	$listing_id = absint( $listing_id );
	$title      = get_the_title( $listing_id ) ?: '(unknown)';
	$edit_url   = $listing_id ? admin_url( 'post.php?post=' . $listing_id . '&action=edit' ) : '';
	$type       = $is_full ? 'FULL refund' : 'PARTIAL refund';
	$action     = $is_full
		? "Listing has been downgraded to FREE automatically."
		: "Tier was NOT changed (partial refund). Review and adjust if appropriate.";

	$amount    = (int) ( $charge['amount'] ?? 0 );
	$refunded  = (int) ( $charge['amount_refunded'] ?? 0 );
	$currency  = strtoupper( $charge['currency'] ?? '' );

	$body = implode( "\n", array(
		"Stripe {$type} received",
		'',
		"Listing: {$title} (#{$listing_id})",
		$edit_url ? "Edit: {$edit_url}" : '',
		'',
		'Charge:    ' . ( $charge['id']       ?? '' ),
		'Customer:  ' . ( $charge['customer'] ?? '' ),
		'Invoice:   ' . ( $charge['invoice']  ?? '' ),
		sprintf( 'Amount:    %.2f %s', $amount / 100,   $currency ),
		sprintf( 'Refunded:  %.2f %s', $refunded / 100, $currency ),
		'',
		$action,
	) );

	if ( function_exists( 'hhec_email_send' ) ) {
		hhec_email_send( array(
			'to'            => get_option( 'admin_email' ),
			'subject'       => sprintf( '[HuaHinExpats] Stripe %s on listing #%d', $type, $listing_id ),
			'body_text'     => $body,
			'from_role'     => 'accounts',
			'reply_to'      => hhec_email_address( 'accounts' ),
			'category'      => 'billing_refund_admin',
			'listing_id'    => (int) $listing_id,
			'respects_test' => false,
			'respects_supp' => false,
		) );
	} else {
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[HuaHinExpats] Stripe %s on listing #%d', $type, $listing_id ),
			$body
		);
	}
}

function hhec_send_admin_notice_dispute( $listing_id, array $dispute ) {
	$listing_id = absint( $listing_id );
	$title      = get_the_title( $listing_id ) ?: '(unknown)';
	$edit_url   = $listing_id ? admin_url( 'post.php?post=' . $listing_id . '&action=edit' ) : '';

	$amount   = (int) ( $dispute['amount'] ?? 0 );
	$currency = strtoupper( $dispute['currency'] ?? '' );

	$body = implode( "\n", array(
		'Stripe DISPUTE OPENED',
		'',
		"Listing:   {$title} (#{$listing_id})",
		$edit_url ? "Edit: {$edit_url}" : '',
		'',
		'Dispute:   ' . ( $dispute['id']     ?? '' ),
		'Charge:    ' . ( $dispute['charge'] ?? '' ),
		sprintf( 'Amount:    %.2f %s', $amount / 100, $currency ),
		'Reason:    ' . ( $dispute['reason'] ?? '' ),
		'Status:    ' . ( $dispute['status'] ?? '' ),
		'',
		'Local subscription marked past_due. Listing tier was NOT changed.',
		'Review the dispute in your Stripe Dashboard. The daily expiry cron will',
		'downgrade naturally if the billing period ends before resolution.',
	) );

	if ( function_exists( 'hhec_email_send' ) ) {
		hhec_email_send( array(
			'to'            => get_option( 'admin_email' ),
			'subject'       => sprintf( '[HuaHinExpats] Stripe DISPUTE on listing #%d', $listing_id ),
			'body_text'     => $body,
			'from_role'     => 'accounts',
			'reply_to'      => hhec_email_address( 'accounts' ),
			'category'      => 'billing_dispute_admin',
			'listing_id'    => (int) $listing_id,
			'respects_test' => false,
			'respects_supp' => false,
		) );
	} else {
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[HuaHinExpats] Stripe DISPUTE on listing #%d', $listing_id ),
			$body
		);
	}
}

function hhec_send_admin_notice_unmapped_event( $event_type, array $object ) {
	$body = implode( "\n", array(
		"Stripe webhook event received but could not be mapped to a listing.",
		'',
		"Event type: {$event_type}",
		'Object id:  ' . ( $object['id']         ?? '' ),
		'Customer:   ' . ( $object['customer']   ?? '' ),
		'Charge:     ' . ( $object['charge']     ?? '' ),
		'Invoice:    ' . ( $object['invoice']    ?? '' ),
		'',
		'Action: review this event manually in the Stripe Dashboard. No site state was changed.',
	) );

	if ( function_exists( 'hhec_email_send' ) ) {
		hhec_email_send( array(
			'to'            => get_option( 'admin_email' ),
			'subject'       => sprintf( '[HuaHinExpats] Stripe event needs review: %s', $event_type ),
			'body_text'     => $body,
			'from_role'     => 'accounts',
			'reply_to'      => hhec_email_address( 'accounts' ),
			'category'      => 'billing_unmapped_event',
			'respects_test' => false,
			'respects_supp' => false,
		) );
	} else {
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[HuaHinExpats] Stripe event needs review: %s', $event_type ),
			$body
		);
	}
}

/**
 * Admin notice — surface unconfigured Stripe state on every Hua Hin Expats
 * admin screen. Renders only for users with manage_options. Phase 7: revenue
 * cannot flow until secrets + at least one Premium price ID are entered.
 */
add_action( 'admin_notices', 'hhec_stripe_config_admin_notice' );

function hhec_stripe_config_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || empty( $screen->id ) ) return;

	// Show on our admin pages + the plugins screen so it's discoverable.
	$show_on = array(
		'plugins',
		'toplevel_page_hhe-reviews',
	);
	$is_hhe_subpage = strpos( (string) $screen->id, 'hhe-' ) !== false;
	if ( ! $is_hhe_subpage && ! in_array( $screen->id, $show_on, true ) ) return;

	$missing = array();
	if ( ! hhec_get_stripe_setting( 'secret_key' )            ) $missing[] = __( 'Secret Key', 'huahinexpats-core' );
	if ( ! hhec_get_stripe_setting( 'webhook_secret' )        ) $missing[] = __( 'Webhook Secret', 'huahinexpats-core' );
	if ( ! hhec_get_stripe_setting( 'price_verified_monthly' )
	  && ! hhec_get_stripe_setting( 'price_premium_monthly' )
	  && ! hhec_get_stripe_setting( 'price_premium_plus_monthly' ) ) {
		$missing[] = __( 'at least one Price ID', 'huahinexpats-core' );
	}
	if ( empty( $missing ) ) return;

	$settings_url = admin_url( 'admin.php?page=hhe-stripe-settings' );
	$mode         = hhec_get_stripe_mode();
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php
				printf(
					/* translators: %s: 'test' or 'live' */
					esc_html__( 'Stripe %s mode is not yet configured.', 'huahinexpats-core' ),
					esc_html( strtoupper( $mode ) )
				);
			?></strong>
			<?php
			printf(
				/* translators: %s: comma-separated list of missing settings */
				esc_html__( 'Upgrades cannot be processed until you add: %s.', 'huahinexpats-core' ),
				esc_html( implode( ', ', $missing ) )
			);
			?>
			<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Stripe Settings →', 'huahinexpats-core' ); ?></a>
		</p>
	</div>
	<?php
}

// ─────────────────────────────────────────────────────────────────────────────
// Stripe Settings — Hua Hin Expats → Stripe Settings
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'hhec_stripe_settings_menu' );

function hhec_stripe_settings_menu() {
	add_submenu_page(
		'hhe-reviews',
		__( 'Stripe Settings', 'huahinexpats-core' ),
		__( 'Stripe Settings', 'huahinexpats-core' ),
		'manage_options',
		'hhe-stripe-settings',
		'hhec_stripe_settings_page'
	);
}

function hhec_stripe_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$saved = false;
	$mode_keys = hhec_stripe_mode_scoped_keys();

	if ( isset( $_POST['hhe_stripe_save'] ) ) {
		check_admin_referer( 'hhe_stripe_settings_save' );

		// Mode toggle.
		$new_mode = ( ( $_POST['hhe_stripe_mode'] ?? '' ) === 'live' ) ? 'live' : 'test';
		update_option( 'hhe_stripe_mode', $new_mode );

		// Secret fields per mode — empty input means "keep existing".
		foreach ( array( 'secret_key', 'webhook_secret' ) as $key ) {
			foreach ( array( 'test', 'live' ) as $m ) {
				$opt = ( 'live' === $m ) ? 'hhe_stripe_live_' . $key : 'hhe_stripe_' . $key;
				$raw = trim( wp_unslash( $_POST[ $opt ] ?? '' ) );
				if ( '' !== $raw ) {
					update_option( $opt, sanitize_text_field( $raw ) );
				}
			}
		}

		// Price IDs per mode — always overwrite (plain-text, not secret).
		$price_keys = array( 'price_verified_monthly', 'price_verified_annual', 'price_premium_monthly', 'price_premium_annual', 'price_premium_plus_monthly', 'price_premium_plus_annual' );
		foreach ( $price_keys as $key ) {
			foreach ( array( 'test', 'live' ) as $m ) {
				$opt = ( 'live' === $m ) ? 'hhe_stripe_live_' . $key : 'hhe_stripe_' . $key;
				$raw = sanitize_text_field( wp_unslash( $_POST[ $opt ] ?? '' ) );
				update_option( $opt, $raw );
			}
		}

		// Mode-agnostic display labels.
		foreach ( array( 'hhe_stripe_display_verified', 'hhe_stripe_display_premium', 'hhe_stripe_display_premium_plus' ) as $opt ) {
			$raw = sanitize_text_field( wp_unslash( $_POST[ $opt ] ?? '' ) );
			update_option( $opt, $raw );
		}

		$saved = true;
	}

	$current_mode  = hhec_get_stripe_mode();
	$webhook_url   = rest_url( 'hhe/v1/stripe-webhook' );
	$has_test_key  = ! empty( get_option( 'hhe_stripe_secret_key', '' ) );
	$has_live_key  = ! empty( get_option( 'hhe_stripe_live_secret_key', '' ) );
	$has_test_whs  = ! empty( get_option( 'hhe_stripe_webhook_secret', '' ) );
	$has_live_whs  = ! empty( get_option( 'hhe_stripe_live_webhook_secret', '' ) );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Stripe Settings', 'huahinexpats-core' ); ?></h1>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'huahinexpats-core' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'hhe_stripe_settings_save' ); ?>

			<h2 style="margin-top:20px;"><?php esc_html_e( 'Environment', 'huahinexpats-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Active mode', 'huahinexpats-core' ); ?></th>
					<td>
						<fieldset>
							<label style="margin-right:24px;">
								<input type="radio" name="hhe_stripe_mode" value="test" <?php checked( $current_mode, 'test' ); ?>>
								<strong><?php esc_html_e( 'Test mode', 'huahinexpats-core' ); ?></strong>
								 <span style="color:#666;"> · <?php esc_html_e( 'safe for development. No real charges.', 'huahinexpats-core' ); ?></span>
							</label>
							<label>
								<input type="radio" name="hhe_stripe_mode" value="live" <?php checked( $current_mode, 'live' ); ?>>
								<strong style="color:#b32d00;"><?php esc_html_e( 'Live mode', 'huahinexpats-core' ); ?></strong>
								 <span style="color:#666;"> · <?php esc_html_e( 'charges real cards. Live keys must be filled below.', 'huahinexpats-core' ); ?></span>
							</label>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Both key sets persist across mode changes. Switch back to Test any time.', 'huahinexpats-core' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php
			$mode_groups = array(
				'test' => array(
					'heading'   => __( 'Test mode keys', 'huahinexpats-core' ),
					'colour'    => '#0073aa',
					'sk_opt'    => 'hhe_stripe_secret_key',
					'whs_opt'   => 'hhe_stripe_webhook_secret',
					'price_opt' => 'hhe_stripe_',
					'has_sk'    => $has_test_key,
					'has_whs'   => $has_test_whs,
					'sk_ph'     => 'sk_test_...',
					'whs_ph'    => 'whsec_...',
				),
				'live' => array(
					'heading'   => __( 'Live mode keys', 'huahinexpats-core' ),
					'colour'    => '#b32d00',
					'sk_opt'    => 'hhe_stripe_live_secret_key',
					'whs_opt'   => 'hhe_stripe_live_webhook_secret',
					'price_opt' => 'hhe_stripe_live_',
					'has_sk'    => $has_live_key,
					'has_whs'   => $has_live_whs,
					'sk_ph'     => 'sk_live_...',
					'whs_ph'    => 'whsec_...',
				),
			);

			$price_fields = array(
				'price_verified_monthly'     => __( 'Verified, Monthly', 'huahinexpats-core' ),
				'price_verified_annual'      => __( 'Verified, Annual', 'huahinexpats-core' ),
				'price_premium_monthly'      => __( 'Premium, Monthly', 'huahinexpats-core' ),
				'price_premium_annual'       => __( 'Premium, Annual', 'huahinexpats-core' ),
				'price_premium_plus_monthly' => __( 'Premium Plus, Monthly', 'huahinexpats-core' ),
				'price_premium_plus_annual'  => __( 'Premium Plus, Annual', 'huahinexpats-core' ),
			);

			foreach ( $mode_groups as $mode_slug => $g ) :
				$is_active = ( $current_mode === $mode_slug );
			?>
			<h2 style="margin-top:32px;border-left:4px solid <?php echo esc_attr( $g['colour'] ); ?>;padding-left:10px;">
				<?php echo esc_html( $g['heading'] ); ?>
				<?php if ( $is_active ) : ?>
					<span style="font-size:13px;background:<?php echo esc_attr( $g['colour'] ); ?>;color:#fff;padding:2px 8px;border-radius:3px;margin-left:8px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;">Active</span>
				<?php endif; ?>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $g['sk_opt'] ); ?>"><?php esc_html_e( 'Secret Key', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="password" id="<?php echo esc_attr( $g['sk_opt'] ); ?>" name="<?php echo esc_attr( $g['sk_opt'] ); ?>" value=""
							placeholder="<?php echo $g['has_sk'] ? esc_attr__( 'Leave blank to keep current value', 'huahinexpats-core' ) : esc_attr( $g['sk_ph'] ); ?>"
							class="regular-text">
						<?php if ( $g['has_sk'] ) : ?>
							<span style="color:#46b450;margin-left:8px;">✓ <?php esc_html_e( 'Key saved', 'huahinexpats-core' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $g['whs_opt'] ); ?>"><?php esc_html_e( 'Webhook Signing Secret', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="password" id="<?php echo esc_attr( $g['whs_opt'] ); ?>" name="<?php echo esc_attr( $g['whs_opt'] ); ?>" value=""
							placeholder="<?php echo $g['has_whs'] ? esc_attr__( 'Leave blank to keep current value', 'huahinexpats-core' ) : esc_attr( $g['whs_ph'] ); ?>"
							class="regular-text">
						<?php if ( $g['has_whs'] ) : ?>
							<span style="color:#46b450;margin-left:8px;">✓ <?php esc_html_e( 'Secret saved', 'huahinexpats-core' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php foreach ( $price_fields as $key => $label ) :
					$opt = $g['price_opt'] . $key;
					$val = get_option( $opt, '' );
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<input type="text" id="<?php echo esc_attr( $opt ); ?>" name="<?php echo esc_attr( $opt ); ?>"
							value="<?php echo esc_attr( $val ); ?>" placeholder="price_..."
							class="regular-text" style="font-family:monospace;">
					</td>
				</tr>
				<?php endforeach; ?>
			</table>
			<?php endforeach; ?>

			<h2 style="margin-top:32px;"><?php esc_html_e( 'Webhook URL', 'huahinexpats-core' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Add this URL to both your Test-mode and Live-mode webhook endpoints in the Stripe Dashboard:', 'huahinexpats-core' ); ?><br>
				<code style="font-size:13px;"><?php echo esc_html( $webhook_url ); ?></code>
			</p>

			<h2 style="margin-top:32px;"><?php esc_html_e( 'Display Prices (shared)', 'huahinexpats-core' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Optional labels shown to owners on the upgrade panel (e.g. "฿990/month"). Same label across test and live modes.', 'huahinexpats-core' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hhe_stripe_display_verified"><?php esc_html_e( 'Verified display price', 'huahinexpats-core' ); ?></label></th>
					<td><input type="text" id="hhe_stripe_display_verified" name="hhe_stripe_display_verified" value="<?php echo esc_attr( get_option( 'hhe_stripe_display_verified', '' ) ); ?>" placeholder="฿490/month" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_stripe_display_premium"><?php esc_html_e( 'Premium display price', 'huahinexpats-core' ); ?></label></th>
					<td><input type="text" id="hhe_stripe_display_premium" name="hhe_stripe_display_premium" value="<?php echo esc_attr( get_option( 'hhe_stripe_display_premium', '' ) ); ?>" placeholder="฿1,490/month" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_stripe_display_premium_plus"><?php esc_html_e( 'Premium Plus display price', 'huahinexpats-core' ); ?></label></th>
					<td><input type="text" id="hhe_stripe_display_premium_plus" name="hhe_stripe_display_premium_plus" value="<?php echo esc_attr( get_option( 'hhe_stripe_display_premium_plus', '' ) ); ?>" placeholder="฿2,990/month" class="regular-text"></td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'huahinexpats-core' ), 'primary', 'hhe_stripe_save' ); ?>
		</form>
	</div>
	<?php
}

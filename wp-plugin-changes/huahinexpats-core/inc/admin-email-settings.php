<?php
/**
 * Email Settings admin page (Phase 1 — Postmark + transactional routing).
 *
 * Hua Hin Expats → Email Settings
 *
 * Single screen for:
 *   - Transactional SMTP credentials (Postmark by default)
 *   - Canonical role addresses (info / accounts / legal + From-name + Reply-To)
 *   - Test recipient and one-shot test-send button
 *   - DNS authentication status (re-uses hhec_deliverability_checks)
 *   - Read-only routing inventory
 *   - Static DNS records checklist
 *
 * Secret handling rules (enforced in this file):
 *   - Password field is write-only — never echoed back after save.
 *   - "Leave blank to keep existing" semantics on the password input.
 *   - Test-send errors are passed through hhec_smtp_redact_secret() before
 *     being stored to an option or rendered to the page.
 *   - Nothing on this page reads or writes the wp_hhe_email_log row body
 *     beyond what hhec_email_send() already stores (HTML-stripped, 500 char cap).
 *
 * @package HuaHinExpats
 */

defined( 'ABSPATH' ) || exit;

const HHEC_EMAIL_SETTINGS_PAGE    = 'hhe-email-settings';
const HHEC_EMAIL_SETTINGS_CAP     = 'manage_options';
const HHEC_EMAIL_SETTINGS_NONCE   = 'hhec_email_settings_save';
const HHEC_EMAIL_TEST_NONCE       = 'hhec_email_settings_test';
const HHEC_EMAIL_DNS_RECHECK_NONCE = 'hhec_email_dns_recheck';
const HHEC_EMAIL_TEST_RESULT_OPT  = 'hhec_email_settings_last_test';

// ─────────────────────────────────────────────────────────────────────────────
// Menu registration
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'hhec_email_settings_register_menu', 50 );

function hhec_email_settings_register_menu() {
	add_submenu_page(
		'hhe-reviews',
		__( 'Email Settings', 'huahinexpats-core' ),
		__( 'Email Settings', 'huahinexpats-core' ),
		HHEC_EMAIL_SETTINGS_CAP,
		HHEC_EMAIL_SETTINGS_PAGE,
		'hhec_email_settings_render_page'
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// admin-post handlers
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_post_hhec_email_settings_save', 'hhec_email_settings_handle_save' );
add_action( 'admin_post_hhec_email_settings_test', 'hhec_email_settings_handle_test' );

function hhec_email_settings_handle_save() {
	if ( ! current_user_can( HHEC_EMAIL_SETTINGS_CAP ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'huahinexpats-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( HHEC_EMAIL_SETTINGS_NONCE );

	// Provider — must be a known preset key.
	$provider = isset( $_POST['hhe_smtp_provider'] ) ? sanitize_key( wp_unslash( $_POST['hhe_smtp_provider'] ) ) : 'postmark';
	$presets  = function_exists( 'hhec_smtp_provider_presets' ) ? hhec_smtp_provider_presets() : array();
	if ( ! isset( $presets[ $provider ] ) ) $provider = 'postmark';
	update_option( 'hhe_smtp_provider', $provider );

	// Master toggle.
	update_option( 'hhe_smtp_enabled', isset( $_POST['hhe_smtp_enabled'] ) ? '1' : '0' );

	// Host / port / encryption.
	$host = isset( $_POST['hhe_smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['hhe_smtp_host'] ) ) : '';
	update_option( 'hhe_smtp_host', $host );

	$port = isset( $_POST['hhe_smtp_port'] ) ? absint( wp_unslash( $_POST['hhe_smtp_port'] ) ) : 587;
	if ( $port < 1 || $port > 65535 ) $port = 587;
	update_option( 'hhe_smtp_port', $port );

	$enc = isset( $_POST['hhe_smtp_encryption'] ) ? sanitize_key( wp_unslash( $_POST['hhe_smtp_encryption'] ) ) : 'tls';
	if ( ! in_array( $enc, array( 'tls', 'ssl', 'none' ), true ) ) $enc = 'tls';
	update_option( 'hhe_smtp_encryption', $enc );

	// Username — stored as-is. Treated as identifier, not secret.
	$username = isset( $_POST['hhe_smtp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['hhe_smtp_username'] ) ) : '';
	update_option( 'hhe_smtp_username', $username );

	// Password — write-only. Blank input = "keep existing".
	$pw_input = isset( $_POST['hhe_smtp_password'] ) ? (string) wp_unslash( $_POST['hhe_smtp_password'] ) : '';
	if ( $pw_input !== '' ) {
		update_option( 'hhe_smtp_password', $pw_input );
	}

	// DKIM selector — feeds the DNS probe.
	$dkim = isset( $_POST['hhe_smtp_dkim_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['hhe_smtp_dkim_selector'] ) ) : '';
	update_option( 'hhe_smtp_dkim_selector', $dkim );

	// Addresses — only stored when valid; blank clears the override (resolver
	// falls back to the constant).
	$address_keys = array(
		'hhe_email_info'     => sanitize_email( wp_unslash( $_POST['hhe_email_info']     ?? '' ) ),
		'hhe_email_accounts' => sanitize_email( wp_unslash( $_POST['hhe_email_accounts'] ?? '' ) ),
		'hhe_email_legal'    => sanitize_email( wp_unslash( $_POST['hhe_email_legal']    ?? '' ) ),
		'hhe_email_replyto'  => sanitize_email( wp_unslash( $_POST['hhe_email_replyto']  ?? '' ) ),
		'hhe_email_test_recipient' => sanitize_email( wp_unslash( $_POST['hhe_email_test_recipient'] ?? '' ) ),
	);
	foreach ( $address_keys as $opt_key => $val ) {
		if ( $val === '' ) {
			delete_option( $opt_key );
		} elseif ( is_email( $val ) ) {
			update_option( $opt_key, $val );
		}
	}

	// From name.
	$from_name = isset( $_POST['hhe_email_from_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['hhe_email_from_name'] ) ) ) : '';
	if ( $from_name === '' ) {
		delete_option( 'hhe_email_from_name' );
	} else {
		update_option( 'hhe_email_from_name', $from_name );
	}

	// Bust DNS-check cache so the panel reflects any new domain immediately.
	if ( function_exists( 'hhec_deliverability_domain' ) ) {
		$domain = hhec_deliverability_domain();
		delete_transient( 'hhec_dns_checks_' . md5( $domain ) );
	}

	wp_safe_redirect( add_query_arg(
		array( 'page' => HHEC_EMAIL_SETTINGS_PAGE, 'updated' => '1' ),
		admin_url( 'admin.php' )
	) );
	exit;
}

function hhec_email_settings_handle_test() {
	if ( ! current_user_can( HHEC_EMAIL_SETTINGS_CAP ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'huahinexpats-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( HHEC_EMAIL_TEST_NONCE );

	$recipient = (string) get_option( 'hhe_email_test_recipient', '' );
	$redirect  = add_query_arg(
		array( 'page' => HHEC_EMAIL_SETTINGS_PAGE, 'tested' => '1' ),
		admin_url( 'admin.php' )
	);

	if ( ! is_email( $recipient ) ) {
		hhec_email_settings_store_test_result( array(
			'ok'      => false,
			'when'    => current_time( 'mysql' ),
			'sent_to' => '',
			'error'   => __( 'Test recipient is missing or invalid. Set it on this page and save before testing.', 'huahinexpats-core' ),
		) );
		wp_safe_redirect( $redirect );
		exit;
	}

	// Capture wp_mail_failed for this send only.
	$captured = null;
	$capture  = function ( $err ) use ( &$captured ) {
		if ( is_wp_error( $err ) ) {
			$captured = $err->get_error_message();
		}
	};
	add_action( 'wp_mail_failed', $capture, 1 );

	$subject = sprintf(
		/* translators: %s site name */
		__( '[%s] Test email — Postmark / SMTP verification', 'huahinexpats-core' ),
		get_bloginfo( 'name' )
	);
	$body_lines = array(
		__( 'This is a test message from the Hua Hin Expats email settings page.', 'huahinexpats-core' ),
		'',
		__( 'If you received it, transactional email transport is configured correctly.', 'huahinexpats-core' ),
		'',
		'Site:  ' . home_url( '/' ),
		'When:  ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
		'From:  ' . hhec_email_from_name() . ' <' . hhec_email_address( 'info' ) . '>',
	);
	$body = implode( "\n", $body_lines );

	$sent = false;
	if ( function_exists( 'hhec_email_send' ) ) {
		$sent = (bool) hhec_email_send( array(
			'to'            => $recipient,
			'subject'       => $subject,
			'body_text'     => $body,
			'from_role'     => 'info',
			'reply_to'      => hhec_email_address( 'info' ),
			'category'      => 'admin_smtp_test',
			// Bypass the outreach test-mode redirect — we want the test to
			// actually reach the test recipient, not bounce back to admin.
			'respects_test' => false,
			// Bypass suppression — the test recipient may have unsubscribed
			// previously; we still want to verify transport.
			'respects_supp' => false,
		) );
	} else {
		$sent = (bool) wp_mail( $recipient, $subject, $body );
	}

	remove_action( 'wp_mail_failed', $capture, 1 );

	hhec_email_settings_store_test_result( array(
		'ok'      => ( $sent && $captured === null ),
		'when'    => current_time( 'mysql' ),
		'sent_to' => $recipient,
		// Redact any captured PHPMailer message before storing/rendering.
		'error'   => $captured !== null
			? ( function_exists( 'hhec_smtp_redact_secret' ) ? hhec_smtp_redact_secret( (string) $captured ) : '[error captured]' )
			: '',
	) );

	wp_safe_redirect( $redirect );
	exit;
}

function hhec_email_settings_store_test_result( array $result ) {
	// Non-autoloaded so the option doesn't bloat every page load.
	update_option( HHEC_EMAIL_TEST_RESULT_OPT, $result, false );
}

// ─────────────────────────────────────────────────────────────────────────────
// Routing inventory — read-only table on the settings page.
// ─────────────────────────────────────────────────────────────────────────────

function hhec_email_routing_inventory() {
	return array(
		array( 'label' => __( 'Listing claim form submission',          'huahinexpats-core' ), 'role' => 'info'     ),
		array( 'label' => __( 'Claim approval confirmation',            'huahinexpats-core' ), 'role' => 'info'     ),
		array( 'label' => __( 'Outreach invitation',                    'huahinexpats-core' ), 'role' => 'info'     ),
		array( 'label' => __( 'Outreach reminder',                      'huahinexpats-core' ), 'role' => 'info'     ),
		array( 'label' => __( 'Outreach final reminder',                'huahinexpats-core' ), 'role' => 'info'     ),
		array( 'label' => __( 'Reader review submission notice',        'huahinexpats-core' ), 'role' => 'info'     ),
		array( 'label' => __( 'Owner notifications (default)',          'huahinexpats-core' ), 'role' => 'info'     ),
		array( 'label' => __( 'WordPress core (password reset, etc.)',  'huahinexpats-core' ), 'role' => 'info'     ),
		array( 'label' => __( 'Upgrade promotion',                      'huahinexpats-core' ), 'role' => 'accounts' ),
		array( 'label' => __( 'Stripe — upgrade success receipt',       'huahinexpats-core' ), 'role' => 'accounts' ),
		array( 'label' => __( 'Stripe — refund admin notice',           'huahinexpats-core' ), 'role' => 'accounts' ),
		array( 'label' => __( 'Stripe — dispute admin notice',          'huahinexpats-core' ), 'role' => 'accounts' ),
		array( 'label' => __( 'Stripe — unmapped-event admin notice',   'huahinexpats-core' ), 'role' => 'accounts' ),
		array( 'label' => __( 'Premium expiry warning',                 'huahinexpats-core' ), 'role' => 'accounts' ),
		array( 'label' => __( 'Legal / privacy / takedown (reserved)',  'huahinexpats-core' ), 'role' => 'legal'    ),
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Render
// ─────────────────────────────────────────────────────────────────────────────

function hhec_email_settings_render_page() {
	if ( ! current_user_can( HHEC_EMAIL_SETTINGS_CAP ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'huahinexpats-core' ), '', array( 'response' => 403 ) );
	}

	// Honour DNS re-check link (nonce-protected).
	if ( isset( $_GET['recheck'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['recheck'] ) ), HHEC_EMAIL_DNS_RECHECK_NONCE )
		&& function_exists( 'hhec_deliverability_checks' )
	) {
		hhec_deliverability_checks( true );
	}

	$cfg = function_exists( 'hhec_smtp_settings' ) ? hhec_smtp_settings() : array(
		'enabled' => false, 'provider' => 'postmark', 'host' => '', 'port' => 587,
		'encryption' => 'tls', 'username' => '', 'password' => '',
	);
	$presets       = function_exists( 'hhec_smtp_provider_presets' ) ? hhec_smtp_provider_presets() : array();
	$provider_keys = array_keys( $presets );
	$current_hint  = isset( $presets[ $cfg['provider'] ]['username_hint'] ) ? $presets[ $cfg['provider'] ]['username_hint'] : '';

	$info_email     = (string) hhec_email_address( 'info' );
	$accounts_email = (string) hhec_email_address( 'accounts' );
	$legal_email    = (string) hhec_email_address( 'legal' );
	$replyto_email  = (string) hhec_email_reply_to();
	$from_name      = (string) hhec_email_from_name();
	$test_recipient = (string) get_option( 'hhe_email_test_recipient', '' );
	$dkim_selector  = (string) get_option( 'hhe_smtp_dkim_selector', '' );

	$has_password = (bool) get_option( 'hhe_smtp_password', '' );

	$test_result = get_option( HHEC_EMAIL_TEST_RESULT_OPT, array() );
	if ( ! is_array( $test_result ) ) $test_result = array();

	$dns = function_exists( 'hhec_deliverability_checks' ) ? hhec_deliverability_checks() : array();

	$just_saved  = ! empty( $_GET['updated'] );
	$just_tested = ! empty( $_GET['tested'] );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Email Settings', 'huahinexpats-core' ); ?></h1>

		<?php if ( $just_saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Email settings saved.', 'huahinexpats-core' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $just_tested ) : ?>
			<?php if ( ! empty( $test_result['ok'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php
					echo esc_html( sprintf(
						/* translators: %s recipient */
						__( 'Test email sent to %s.', 'huahinexpats-core' ),
						$test_result['sent_to'] ?? ''
					) );
				?></p></div>
			<?php else : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Test email failed.', 'huahinexpats-core' ); ?></strong></p>
					<?php if ( ! empty( $test_result['error'] ) ) : ?>
						<p><code style="white-space:pre-wrap;"><?php echo esc_html( $test_result['error'] ); ?></code></p>
						<p class="description"><?php esc_html_e( 'Credentials are redacted in this error.', 'huahinexpats-core' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<p class="description"><?php esc_html_e(
			'Configure SMTP transport, canonical role addresses, and run a test send. Tokens and passwords are never echoed back to this page after save.',
			'huahinexpats-core'
		); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
			<?php wp_nonce_field( HHEC_EMAIL_SETTINGS_NONCE ); ?>
			<input type="hidden" name="action" value="hhec_email_settings_save" />

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Transport', 'huahinexpats-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hhe_smtp_enabled"><?php esc_html_e( 'Transactional mail', 'huahinexpats-core' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" name="hhe_smtp_enabled" id="hhe_smtp_enabled" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?> />
							<?php esc_html_e( 'Enabled — when ON, outbound mail uses the configured SMTP relay.', 'huahinexpats-core' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When OFF, WordPress falls back to native PHP mail() — usually delivers poorly and may be blocked entirely.', 'huahinexpats-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_smtp_provider"><?php esc_html_e( 'Provider', 'huahinexpats-core' ); ?></label></th>
					<td>
						<select name="hhe_smtp_provider" id="hhe_smtp_provider">
							<?php foreach ( $provider_keys as $pk ) : ?>
								<option value="<?php echo esc_attr( $pk ); ?>" <?php selected( $cfg['provider'], $pk ); ?>><?php echo esc_html( ucfirst( $pk ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Postmark is the recommended provider. For Postmark, paste the Server API token in BOTH the username and password fields.', 'huahinexpats-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_smtp_host"><?php esc_html_e( 'SMTP host', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="text" name="hhe_smtp_host" id="hhe_smtp_host" class="regular-text" value="<?php echo esc_attr( $cfg['host'] ); ?>" placeholder="smtp.postmarkapp.com" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_smtp_port"><?php esc_html_e( 'SMTP port', 'huahinexpats-core' ); ?></label></th>
					<td><input type="number" name="hhe_smtp_port" id="hhe_smtp_port" class="small-text" min="1" max="65535" value="<?php echo esc_attr( (int) $cfg['port'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_smtp_encryption"><?php esc_html_e( 'Encryption', 'huahinexpats-core' ); ?></label></th>
					<td>
						<select name="hhe_smtp_encryption" id="hhe_smtp_encryption">
							<option value="tls"  <?php selected( $cfg['encryption'], 'tls' ); ?>>TLS (STARTTLS)</option>
							<option value="ssl"  <?php selected( $cfg['encryption'], 'ssl' ); ?>>SSL</option>
							<option value="none" <?php selected( $cfg['encryption'], 'none' ); ?>><?php esc_html_e( 'None (not recommended)', 'huahinexpats-core' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_smtp_username"><?php esc_html_e( 'SMTP username / API token', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="text" name="hhe_smtp_username" id="hhe_smtp_username" class="regular-text" value="<?php echo esc_attr( $cfg['username'] ); ?>" autocomplete="off" spellcheck="false" />
						<?php if ( $current_hint ) : ?>
							<p class="description"><?php echo esc_html( $current_hint ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_smtp_password"><?php esc_html_e( 'SMTP password / API token', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="password" name="hhe_smtp_password" id="hhe_smtp_password" class="regular-text" value="" autocomplete="new-password" spellcheck="false"
							placeholder="<?php echo $has_password ? esc_attr__( '(saved — leave blank to keep)', 'huahinexpats-core' ) : esc_attr__( 'paste token here', 'huahinexpats-core' ); ?>" />
						<p class="description"><?php esc_html_e( 'Never displayed after save. Leave blank on subsequent saves to keep the current value.', 'huahinexpats-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_smtp_dkim_selector"><?php esc_html_e( 'DKIM selector', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="text" name="hhe_smtp_dkim_selector" id="hhe_smtp_dkim_selector" class="regular-text" value="<?php echo esc_attr( $dkim_selector ); ?>" spellcheck="false" />
						<p class="description"><?php esc_html_e( 'Paste the selector Postmark generates for your domain — the part before "._domainkey" in the CNAME record name. Used only by the DNS check panel below.', 'huahinexpats-core' ); ?></p>
					</td>
				</tr>
			</table>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Addresses', 'huahinexpats-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hhe_email_from_name"><?php esc_html_e( 'From name', 'huahinexpats-core' ); ?></label></th>
					<td><input type="text" name="hhe_email_from_name" id="hhe_email_from_name" class="regular-text" value="<?php echo esc_attr( $from_name ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_email_info"><?php esc_html_e( 'From email (info / contact / claim)', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="email" name="hhe_email_info" id="hhe_email_info" class="regular-text" value="<?php echo esc_attr( $info_email ); ?>" />
						<p class="description"><?php esc_html_e( 'General contact, claim invitations, claim confirmations, owner notifications. Default From: for system mail.', 'huahinexpats-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_email_replyto"><?php esc_html_e( 'Default Reply-To', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="email" name="hhe_email_replyto" id="hhe_email_replyto" class="regular-text" value="<?php echo esc_attr( $replyto_email ); ?>" />
						<p class="description"><?php esc_html_e( 'Default reply destination. Individual send-functions may override (e.g. billing mail replies route to accounts@).', 'huahinexpats-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_email_accounts"><?php esc_html_e( 'Accounts email (billing)', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="email" name="hhe_email_accounts" id="hhe_email_accounts" class="regular-text" value="<?php echo esc_attr( $accounts_email ); ?>" />
						<p class="description"><?php esc_html_e( 'Upgrade receipts, refund/dispute admin notices, premium expiry warnings.', 'huahinexpats-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_email_legal"><?php esc_html_e( 'Legal email (privacy / compliance)', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="email" name="hhe_email_legal" id="hhe_email_legal" class="regular-text" value="<?php echo esc_attr( $legal_email ); ?>" />
						<p class="description"><?php esc_html_e( 'Reserved for data-export, account-deletion, takedown notices. No automated flows in this phase.', 'huahinexpats-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hhe_email_test_recipient"><?php esc_html_e( 'Test recipient', 'huahinexpats-core' ); ?></label></th>
					<td>
						<input type="email" name="hhe_email_test_recipient" id="hhe_email_test_recipient" class="regular-text" value="<?php echo esc_attr( $test_recipient ); ?>" />
						<p class="description"><?php esc_html_e( 'Receives messages produced by the "Send test email" button below.', 'huahinexpats-core' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save email settings', 'huahinexpats-core' ); ?></button>
			</p>
		</form>

		<h2 style="margin-top:2em;"><?php esc_html_e( 'Routing rules', 'huahinexpats-core' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Which role each mail type sends from. Read-only — reflects the code paths today. Resolved addresses below update automatically when you change the address fields above.', 'huahinexpats-core' ); ?></p>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr>
				<th><?php esc_html_e( 'Mail type', 'huahinexpats-core' ); ?></th>
				<th><?php esc_html_e( 'Role', 'huahinexpats-core' ); ?></th>
				<th><?php esc_html_e( 'Resolved address', 'huahinexpats-core' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( hhec_email_routing_inventory() as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td><code><?php echo esc_html( $row['role'] ); ?></code></td>
						<td><code><?php echo esc_html( hhec_email_address( $row['role'] ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2 style="margin-top:2em;"><?php esc_html_e( 'Send test email', 'huahinexpats-core' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1.5em;">
			<?php wp_nonce_field( HHEC_EMAIL_TEST_NONCE ); ?>
			<input type="hidden" name="action" value="hhec_email_settings_test" />
			<p><?php echo esc_html( sprintf(
				/* translators: %s recipient */
				__( 'Sends one test message to %s using the saved transport and From: settings. The test bypasses outreach test-mode and the suppression list.', 'huahinexpats-core' ),
				$test_recipient ?: __( '(no recipient configured)', 'huahinexpats-core' )
			) ); ?></p>
			<button type="submit" class="button" <?php disabled( ! $test_recipient || empty( $cfg['enabled'] ) ); ?>>
				<?php esc_html_e( 'Send test email', 'huahinexpats-core' ); ?>
			</button>
			<?php if ( empty( $cfg['enabled'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Enable transactional mail above before testing.', 'huahinexpats-core' ); ?></p>
			<?php endif; ?>
		</form>

		<?php if ( ! empty( $test_result ) ) : ?>
			<h3><?php esc_html_e( 'Last test result', 'huahinexpats-core' ); ?></h3>
			<table class="form-table" role="presentation" style="max-width:900px;">
				<tr><th><?php esc_html_e( 'When', 'huahinexpats-core' ); ?></th>
					<td><code><?php echo esc_html( $test_result['when'] ?? '' ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Recipient', 'huahinexpats-core' ); ?></th>
					<td><code><?php echo esc_html( $test_result['sent_to'] ?? '' ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Result', 'huahinexpats-core' ); ?></th>
					<td><?php
						if ( ! empty( $test_result['ok'] ) ) {
							echo '<strong style="color:#067d22;">' . esc_html__( 'OK', 'huahinexpats-core' ) . '</strong>';
						} else {
							echo '<strong style="color:#c70016;">' . esc_html__( 'FAILED', 'huahinexpats-core' ) . '</strong>';
						}
					?></td></tr>
				<?php if ( ! empty( $test_result['error'] ) ) : ?>
					<tr><th><?php esc_html_e( 'Error (redacted)', 'huahinexpats-core' ); ?></th>
						<td><code style="white-space:pre-wrap;"><?php echo esc_html( $test_result['error'] ); ?></code></td></tr>
				<?php endif; ?>
			</table>
		<?php endif; ?>

		<h2 style="margin-top:2em;"><?php esc_html_e( 'DNS authentication', 'huahinexpats-core' ); ?></h2>
		<?php if ( ! empty( $dns ) ) : ?>
			<table class="form-table" role="presentation" style="max-width:900px;">
				<tr><th style="width:160px;"><?php esc_html_e( 'Domain checked', 'huahinexpats-core' ); ?></th>
					<td><code><?php echo esc_html( $dns['domain'] ?? '' ); ?></code></td></tr>
				<tr><th>SPF</th><td><?php echo hhec_email_settings_render_dns_row( $dns['spf'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — output already escaped inside the helper. ?></td></tr>
				<tr><th>DKIM</th><td><?php echo hhec_email_settings_render_dns_row( $dns['dkim'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
				<tr><th>DMARC</th><td><?php echo hhec_email_settings_render_dns_row( $dns['dmarc'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
				<tr><th><?php esc_html_e( 'Checked at', 'huahinexpats-core' ); ?></th>
					<td><code><?php echo esc_html( $dns['checked_at'] ?? '' ); ?></code></td></tr>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url(
					add_query_arg( array( 'page' => HHEC_EMAIL_SETTINGS_PAGE ), admin_url( 'admin.php' ) ),
					HHEC_EMAIL_DNS_RECHECK_NONCE,
					'recheck'
				) ); ?>"><?php esc_html_e( 'Re-check DNS', 'huahinexpats-core' ); ?></a>
				<span class="description" style="margin-left:.5em;"><?php esc_html_e( 'Bypasses the 6-hour cache.', 'huahinexpats-core' ); ?></span>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'DNS check not available.', 'huahinexpats-core' ); ?></p>
		<?php endif; ?>

		<h3 style="margin-top:1.5em;"><?php esc_html_e( 'DNS records to add (Postmark)', 'huahinexpats-core' ); ?></h3>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr>
				<th><?php esc_html_e( 'Record', 'huahinexpats-core' ); ?></th>
				<th><?php esc_html_e( 'Type', 'huahinexpats-core' ); ?></th>
				<th><?php esc_html_e( 'Name', 'huahinexpats-core' ); ?></th>
				<th><?php esc_html_e( 'Value', 'huahinexpats-core' ); ?></th>
			</tr></thead>
			<tbody>
				<tr>
					<td><strong>SPF</strong></td>
					<td>TXT</td>
					<td><code>@</code></td>
					<td><code>v=spf1 include:spf.mtasv.net ~all</code></td>
				</tr>
				<tr>
					<td><strong>DKIM</strong></td>
					<td>CNAME</td>
					<td><code>&lt;selector&gt;._domainkey</code></td>
					<td><code>&lt;selector&gt;.dkim.postmarkapp.com</code> — copy exact values from Postmark → Sender Signatures → DNS</td>
				</tr>
				<tr>
					<td><strong>Return-Path</strong></td>
					<td>CNAME</td>
					<td><code>pm-bounces</code></td>
					<td><code>pm.mtasv.net</code> — then enable "Custom Return-Path" in each Postmark Server</td>
				</tr>
				<tr>
					<td><strong>DMARC</strong></td>
					<td>TXT</td>
					<td><code>_dmarc</code></td>
					<td><code>v=DMARC1; p=none; rua=mailto:dmarc-aggregate@&lt;domain&gt;; fo=1</code> — start here, progress to <code>p=quarantine</code> then <code>p=reject</code> over 4–6 weeks</td>
				</tr>
				<tr>
					<td><strong>Postmark verification</strong></td>
					<td>—</td>
					<td>—</td>
					<td><?php esc_html_e( 'Manual confirmation in Postmark dashboard once DKIM and Return-Path records propagate. Each role address (info / accounts / legal) also requires individual Sender Signature verification.', 'huahinexpats-core' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Render one row of DNS-check status. Returns escaped HTML.
 */
function hhec_email_settings_render_dns_row( $row ) {
	if ( empty( $row ) || ! is_array( $row ) ) {
		return '<em>' . esc_html__( 'no result', 'huahinexpats-core' ) . '</em>';
	}
	$pass  = ! empty( $row['pass'] );
	$badge = $pass
		? '<span style="color:#067d22;font-weight:600;">&#10003; PASS</span>'
		: '<span style="color:#c70016;font-weight:600;">&#10007; FAIL</span>';
	$note = '';
	if ( ! empty( $row['note'] ) ) {
		$note = ' <code>(' . esc_html( $row['note'] ) . ')</code>';
	}
	$value = '';
	if ( ! empty( $row['value'] ) ) {
		$value = '<div style="margin-top:.5em;"><code style="white-space:pre-wrap;word-break:break-all;">' . esc_html( $row['value'] ) . '</code></div>';
	}
	return $badge . $note . $value;
}

#!/usr/bin/env bash
# Postmark Phase 1 smoke test.
#
# Run from the WordPress root AFTER copying the Phase 1 files into
# wp-content/plugins/huahinexpats-core/ and before completing the
# manual configuration in WP Admin.
#
# Requires WP-CLI on the PATH (`wp` command).
#
# Usage:
#   bash postmark-phase-1-smoke-test.sh
#
# Optional env vars:
#   WP_CLI=/path/to/wp           — override the wp binary
#   SEND_TEST=1                  — also send a test email if a
#                                  hhe_email_test_recipient option is set
#                                  AND hhe_smtp_enabled = '1'.
#
# Exit codes:
#   0 = all required checks passed (some optional checks may have warned)
#   1 = at least one required check failed

set -u

WP_CLI="${WP_CLI:-wp}"
PASS=0
FAIL=0
WARN=0

red()    { printf "\033[31m%s\033[0m\n" "$*"; }
green()  { printf "\033[32m%s\033[0m\n" "$*"; }
yellow() { printf "\033[33m%s\033[0m\n" "$*"; }
blue()   { printf "\033[36m%s\033[0m\n" "$*"; }

require_check() {
  local label="$1"; shift
  local result; result="$("$@" 2>/dev/null)"
  if [[ "$result" == "OK"* ]]; then
    green "  PASS  $label  →  $result"
    PASS=$((PASS+1))
  else
    red   "  FAIL  $label  →  ${result:-<empty>}"
    FAIL=$((FAIL+1))
  fi
}

optional_check() {
  local label="$1"; shift
  local result; result="$("$@" 2>/dev/null)"
  if [[ "$result" == "OK"* ]]; then
    green "  OK    $label  →  $result"
    PASS=$((PASS+1))
  else
    yellow "  WARN  $label  →  ${result:-<empty>}"
    WARN=$((WARN+1))
  fi
}

heading() {
  echo
  blue "── $* ──"
}

# ─────────────────────────────────────────────────────────────────────
# Pre-flight
# ─────────────────────────────────────────────────────────────────────

heading "Pre-flight"

if ! command -v "$WP_CLI" >/dev/null 2>&1; then
  red "  FAIL  wp-cli not found on PATH"
  echo
  echo "Set WP_CLI=/path/to/wp or install WP-CLI:"
  echo "  https://wp-cli.org/"
  exit 1
fi

if ! "$WP_CLI" core is-installed >/dev/null 2>&1; then
  red "  FAIL  WordPress not detected in current directory"
  echo
  echo "Run this script from your WordPress root (the directory containing"
  echo "wp-config.php), or set WP_CLI_CONFIG_PATH appropriately."
  exit 1
fi

green "  wp-cli OK  ($(${WP_CLI} cli version 2>/dev/null))"
green "  WP install detected  ($(${WP_CLI} option get siteurl 2>/dev/null))"

# ─────────────────────────────────────────────────────────────────────
# Plugin active
# ─────────────────────────────────────────────────────────────────────

heading "Plugin"

require_check "huahinexpats-core is active" \
  "$WP_CLI" eval 'echo is_plugin_active("huahinexpats-core/huahinexpats-core.php") ? "OK active" : "FAIL not active";' --skip-plugins=false --skip-themes=false

# Alternative check that does not rely on is_plugin_active() — for hosts
# where the wp-admin/includes/plugin.php is not auto-loaded in eval.
require_check "plugin in active list" \
  bash -c "$WP_CLI plugin list --status=active --field=name 2>/dev/null | grep -qx huahinexpats-core && echo 'OK active' || echo 'FAIL missing from active list'"

# ─────────────────────────────────────────────────────────────────────
# Phase 1 functions defined
# ─────────────────────────────────────────────────────────────────────

heading "Phase 1 functions defined"

# Functions in inc/email-identity.php
require_check "hhec_email_address" \
  "$WP_CLI" eval 'echo function_exists("hhec_email_address") ? "OK" : "FAIL";'

require_check "hhec_email_from_name (new in Phase 1)" \
  "$WP_CLI" eval 'echo function_exists("hhec_email_from_name") ? "OK" : "FAIL";'

require_check "hhec_email_reply_to (new in Phase 1)" \
  "$WP_CLI" eval 'echo function_exists("hhec_email_reply_to") ? "OK" : "FAIL";'

# Functions in inc/deliverability.php
require_check "hhec_smtp_settings" \
  "$WP_CLI" eval 'echo function_exists("hhec_smtp_settings") ? "OK" : "FAIL";'

require_check "hhec_smtp_provider_presets includes postmark (new in Phase 1)" \
  "$WP_CLI" eval '$p=hhec_smtp_provider_presets(); echo isset($p["postmark"]) && $p["postmark"]["host"]==="smtp.postmarkapp.com" ? "OK postmark@smtp.postmarkapp.com" : "FAIL postmark preset missing or wrong host";'

require_check "hhec_smtp_redact_secret (new in Phase 1)" \
  "$WP_CLI" eval 'echo function_exists("hhec_smtp_redact_secret") ? "OK" : "FAIL";'

# Functions in inc/admin-email-settings.php (admin context required)
require_check "hhec_email_settings_render_page (new in Phase 1)" \
  "$WP_CLI" eval 'echo function_exists("hhec_email_settings_render_page") ? "OK" : "FAIL";' \
  --user=1

require_check "hhec_email_settings_handle_save (new in Phase 1)" \
  "$WP_CLI" eval 'echo function_exists("hhec_email_settings_handle_save") ? "OK" : "FAIL";' \
  --user=1

require_check "hhec_email_settings_handle_test (new in Phase 1)" \
  "$WP_CLI" eval 'echo function_exists("hhec_email_settings_handle_test") ? "OK" : "FAIL";' \
  --user=1

require_check "hhec_email_routing_inventory (new in Phase 1)" \
  "$WP_CLI" eval 'echo function_exists("hhec_email_routing_inventory") ? "OK count=".count(hhec_email_routing_inventory()) : "FAIL";' \
  --user=1

# ─────────────────────────────────────────────────────────────────────
# Settings menu hook
# ─────────────────────────────────────────────────────────────────────

heading "Admin menu hook (Phase 1 only fires in admin context)"

require_check "admin_menu has hhec_email_settings_register_menu hook" \
  "$WP_CLI" eval '
    $hooked = false;
    if (has_action("admin_menu", "hhec_email_settings_register_menu") !== false) $hooked = true;
    echo $hooked ? "OK hooked" : "FAIL not hooked";
  ' --user=1

# ─────────────────────────────────────────────────────────────────────
# Constants and resolver
# ─────────────────────────────────────────────────────────────────────

heading "Address resolver (with no admin overrides yet)"

require_check "HHE_EMAIL_INFO constant defined" \
  "$WP_CLI" eval 'echo defined("HHE_EMAIL_INFO") ? "OK ".HHE_EMAIL_INFO : "FAIL";'

require_check "HHE_EMAIL_ACCOUNTS constant defined" \
  "$WP_CLI" eval 'echo defined("HHE_EMAIL_ACCOUNTS") ? "OK ".HHE_EMAIL_ACCOUNTS : "FAIL";'

require_check "HHE_EMAIL_LEGAL constant defined" \
  "$WP_CLI" eval 'echo defined("HHE_EMAIL_LEGAL") ? "OK ".HHE_EMAIL_LEGAL : "FAIL";'

require_check "hhec_email_address(info) resolves" \
  "$WP_CLI" eval 'echo is_email(hhec_email_address("info")) ? "OK ".hhec_email_address("info") : "FAIL not an email";'

require_check "hhec_email_address(accounts) resolves" \
  "$WP_CLI" eval 'echo is_email(hhec_email_address("accounts")) ? "OK ".hhec_email_address("accounts") : "FAIL not an email";'

require_check "hhec_email_address(legal) resolves" \
  "$WP_CLI" eval 'echo is_email(hhec_email_address("legal")) ? "OK ".hhec_email_address("legal") : "FAIL not an email";'

# ─────────────────────────────────────────────────────────────────────
# Email log table
# ─────────────────────────────────────────────────────────────────────

heading "Email log table"

LOG_TABLE=$("$WP_CLI" eval 'global $wpdb; echo $wpdb->prefix . "hhe_email_log";' 2>/dev/null)
if [[ -n "$LOG_TABLE" ]]; then
  require_check "wp_hhe_email_log table exists" \
    bash -c "$WP_CLI db query \"SHOW TABLES LIKE '${LOG_TABLE}'\" --skip-column-names 2>/dev/null | grep -qx '${LOG_TABLE}' && echo 'OK' || echo 'FAIL not found'"

  optional_check "wp_hhe_email_log has from_address column" \
    bash -c "$WP_CLI db query \"SHOW COLUMNS FROM ${LOG_TABLE} LIKE 'from_address'\" --skip-column-names 2>/dev/null | grep -q from_address && echo 'OK' || echo 'WARN column missing'"
else
  yellow "  WARN  could not resolve log table prefix"
  WARN=$((WARN+1))
fi

# ─────────────────────────────────────────────────────────────────────
# Secret redaction
# ─────────────────────────────────────────────────────────────────────

heading "Secret redaction"

require_check "hhec_smtp_redact_secret returns input when no secrets set" \
  "$WP_CLI" eval '
    // We cannot truly test redaction without setting a fake secret;
    // verify the function does NOT crash and returns a string.
    $r = hhec_smtp_redact_secret("hello world");
    echo (is_string($r) && $r === "hello world") ? "OK noop" : "FAIL ".var_export($r,true);
  '

# Functional redaction probe: temporarily set a known-shape token,
# call the helper, verify it scrubs, then delete the option.
require_check "hhec_smtp_redact_secret scrubs a configured password" \
  "$WP_CLI" eval '
    $existing = get_option("hhe_smtp_password", false);
    update_option("hhe_smtp_password", "TEST-SECRET-VALUE-1234567890");
    $scrubbed = hhec_smtp_redact_secret("login failed: TEST-SECRET-VALUE-1234567890");
    if ($existing === false) {
      delete_option("hhe_smtp_password");
    } else {
      update_option("hhe_smtp_password", $existing);
    }
    echo (strpos($scrubbed, "TEST-SECRET-VALUE-1234567890") === false && strpos($scrubbed, "[REDACTED]") !== false)
      ? "OK redacted"
      : "FAIL scrubbed=".$scrubbed;
  '

# ─────────────────────────────────────────────────────────────────────
# Optional test send
# ─────────────────────────────────────────────────────────────────────

heading "Optional test send (requires configuration)"

if [[ "${SEND_TEST:-0}" != "1" ]]; then
  yellow "  SKIP  SEND_TEST not set to 1 — skipping the test send."
  echo "        To send a real test email after configuring credentials in WP Admin:"
  echo "          SEND_TEST=1 bash $0"
else
  ENABLED=$("$WP_CLI" option get hhe_smtp_enabled 2>/dev/null)
  RECIPIENT=$("$WP_CLI" option get hhe_email_test_recipient 2>/dev/null)

  if [[ "$ENABLED" != "1" ]]; then
    yellow "  SKIP  hhe_smtp_enabled is not '1' — enable transactional mail in WP Admin first"
  elif [[ -z "$RECIPIENT" ]]; then
    yellow "  SKIP  hhe_email_test_recipient is empty — set it on the Email Settings page"
  else
    blue "  Sending test email to: $RECIPIENT"
    SEND_RESULT=$("$WP_CLI" eval '
      $ok = (bool) hhec_email_send([
        "to"            => get_option("hhe_email_test_recipient"),
        "subject"       => "[smoke-test] Postmark Phase 1",
        "body_text"     => "Sent " . gmdate("c") . " by smoke-test script.",
        "from_role"     => "info",
        "category"      => "admin_smtp_test",
        "respects_test" => false,
        "respects_supp" => false,
      ]);
      echo $ok ? "OK send returned true" : "FAIL send returned false";
    ' --user=1 2>/dev/null)
    if [[ "$SEND_RESULT" == "OK"* ]]; then
      green "  PASS  $SEND_RESULT"
      green "        Check the recipient inbox AND Postmark Activity tab to confirm delivery."
      PASS=$((PASS+1))
    else
      red "  FAIL  $SEND_RESULT"
      FAIL=$((FAIL+1))
    fi
  fi
fi

# ─────────────────────────────────────────────────────────────────────
# Summary
# ─────────────────────────────────────────────────────────────────────

echo
blue "══════ Summary ══════"
echo "  Passed:  $PASS"
echo "  Warned:  $WARN"
echo "  Failed:  $FAIL"
echo

if [[ "$FAIL" -gt 0 ]]; then
  red "RESULT: FAIL — at least one required check failed. Roll back per §8 of the Apply Checklist."
  exit 1
fi

if [[ "$WARN" -gt 0 ]]; then
  yellow "RESULT: PASS WITH WARNINGS — review warnings before proceeding."
  exit 0
fi

green "RESULT: PASS — Phase 1 structural checks all green."
green "         Continue with manual verification per §5–§6 of the Apply Checklist."
exit 0

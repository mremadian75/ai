<?php
/**
 * Regression guard — usage-event self-heal + truthful reserve diagnostics.
 *
 * The persistent "usage_reservation_failed" was record_event() failing because
 * the ves_usage_events table was missing/outdated on the host: admins degrade
 * (work), every normal user is hard-blocked. record_event() now self-heals
 * (create table + retry), and the reserve error surfaces the SPECIFIC code
 * instead of the umbrella category. This is $wpdb-bound, so we guard the shipped
 * source structurally (alongside php -l).
 *
 * Run: php tests/test-ves-usage-event-self-heal.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

$billing = (string) file_get_contents(dirname(__DIR__) . '/includes/class-ves-billing.php');
$ajax    = (string) file_get_contents(dirname(__DIR__) . '/includes/class-ves-ajax-controller.php');

// ── 1. record_event self-heals the usage table and retries the insert ────────
$ok(strpos($billing, '$ves_ok = $wpdb->insert(') !== false, '1: record_event captures the insert result');
$ok(strpos($billing, '$ves_row = [') !== false && strpos($billing, '$ves_fmt = [') !== false, '1: insert row + format are captured for retry');
$ok(preg_match('/\$ves_ok === false && method_exists\(__CLASS__, \'create_tables\'\)/', $billing) === 1, '1: on insert failure it (re)creates the table');
$ok(preg_match('/self::create_tables\(\);\s*\$wpdb->insert\(self::table_name\(\), \$ves_row, \$ves_fmt\);/', $billing) === 1, '1: it retries the insert after self-heal');

// ── 2. The reserve error surfaces the SPECIFIC code (not the umbrella one) ───
$ok(strpos($ajax, "'reserve_code' => \$code,") !== false, '2: reserve failure carries the specific reserve_code in diagnostics');
$ok(strpos($ajax, "elseif (\$code !== '') {") !== false && strpos($ajax, "\$extra['error_category'] = \$code;") !== false, '2: non-exhaustion reserve failures expose the real code as the category');

// ── 3. Exhaustion still maps to the friendly demo prompt ─────────────────────
$ok(strpos($ajax, "'error_category' => 'free_quota_exhausted'") !== false || strpos($ajax, "\$extra['error_category'] = 'free_quota_exhausted'") !== false, '3: free-tier exhaustion still shows the demo prompt');

// ── 4. Degradation on infra faults is now SYMMETRIC (all users, not admin-only) ─
$ok(strpos($ajax, "in_array(\$code, ['usage_event_failed', 'missing_usage_key', 'usage_table_missing', 'db_error', 'credit_lock_timeout'], true)") !== false, '4: infra-fault degrade now applies to ALL users (no admin-only gate)');
$ok(strpos($ajax, "current_user_can('manage_options') && in_array(\$code, ['usage_event_failed'") === false, '4: the admin-only gate on infra-fault degrade is removed');

echo "\nUsage-event self-heal + reserve diagnostics: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }

<?php
/**
 * Anonymous gating — public lead-gen funnel.
 *
 * Regression for the live bug: with require_login = false (the default), anonymous
 * visitors reached the app shell instead of the lead form, and every run was
 * rejected ("La petición fue rechazada"). The gate must show the lead form to
 * anonymous users whenever the lead gate is active, regardless of require_login —
 * and must never gate a logged-in user.
 *
 * Run: php tests/test-ves-anonymous-gate.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

// Only the pieces VES_Shortcode::should_show_gate needs.
if (!function_exists('shortcode_atts')) { function shortcode_atts($d, $a, $s = '') { return array_merge((array) $d, (array) $a); } }
require_once dirname(__DIR__) . '/includes/class-ves-shortcode.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// args: (logged_in, lead_gate_active, require_login)

// ── 1. The live bug: anonymous + lead gate + require_login OFF → must gate ───
$ok(VES_Shortcode::should_show_gate(false, true, false) === true, '1: anonymous with lead gate is gated even when require_login is false');

// ── 2. Logged-in users are never gated ───────────────────────────────────────
$ok(VES_Shortcode::should_show_gate(true, true, false) === false, '2: logged-in user is not gated (lead gate on)');
$ok(VES_Shortcode::should_show_gate(true, true, true) === false, '2: logged-in user is not gated (require_login on)');
$ok(VES_Shortcode::should_show_gate(true, false, false) === false, '2: logged-in user is not gated (everything off)');

// ── 3. Legacy behaviour preserved when the lead gate is absent ───────────────
$ok(VES_Shortcode::should_show_gate(false, false, true) === true, '3: no lead gate + require_login on → still gated');
$ok(VES_Shortcode::should_show_gate(false, false, false) === false, '3: no lead gate + require_login off → not gated (legacy public browse)');

// ── 4. Return type is strictly boolean ───────────────────────────────────────
$ok(VES_Shortcode::should_show_gate(0, 1, 0) === true, '4: truthy/falsy inputs coerced to bool (anonymous + gate)');
$ok(VES_Shortcode::should_show_gate(0, 0, 0) === false, '4: all-off coerces to false');

echo "\nAnonymous gating (lead-gen funnel): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }

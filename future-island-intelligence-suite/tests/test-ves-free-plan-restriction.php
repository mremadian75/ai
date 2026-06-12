<?php
/**
 * Public lead-gen tier — free plan restriction + credits config.
 *
 * Asserts the FREE plan (what non-admin/public users get) is limited to the
 * Social module + TikTok/YouTube/Instagram providers, and grants a one-time
 * ~10-request credit allotment with no monthly refill. Admins are unaffected
 * because access checks bypass on manage_options (verified elsewhere).
 *
 * Run: php tests/test-ves-free-plan-restriction.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return false; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 0; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return false; } }

require_once dirname(__DIR__) . '/includes/class-ves-access-control.php';
require_once dirname(__DIR__) . '/includes/class-ves-billing.php';
require_once dirname(__DIR__) . '/includes/class-ves-config.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

$map = VES_Access_Control::get_access_map();
$ok(isset($map['free']), '0: free plan access map exists');
$free = $map['free'];

// ── 1. Only the Social module is enabled; the alternatives are locked ────────
$ok(($free['modules']['social'] ?? '') === VES_Access_Control::STATE_ENABLED, '1: social module is enabled for free tier');
$ok(($free['modules']['usage'] ?? '') === VES_Access_Control::STATE_ENABLED, '1: usage (credits) page stays visible');
foreach (['brand-audit', 'ads', 'creative', 'seo', 'trend', 'google', 'linkedin', 'memory', 'knowledge', 'brief-library'] as $m) {
    $state = $free['modules'][$m] ?? '';
    $ok($state !== VES_Access_Control::STATE_ENABLED, "1: module '$m' is NOT enabled for free tier (state=$state)");
}

// ── 2. Only TikTok / YouTube / Instagram providers are enabled ───────────────
foreach (['tiktok', 'youtube', 'instagram'] as $p) {
    $ok(($free['providers'][$p] ?? '') === VES_Access_Control::STATE_ENABLED, "2: provider '$p' enabled for free tier");
}
$leaked = [];
foreach (VES_Access_Control::PROVIDERS as $p) {
    if (in_array($p, ['tiktok', 'youtube', 'instagram'], true)) { continue; }
    if (($free['providers'][$p] ?? '') === VES_Access_Control::STATE_ENABLED) { $leaked[] = $p; }
}
$ok($leaked === [], '2: no other provider is enabled for free tier (' . implode(',', $leaked) . ')');
// Non-allowed providers are LOCKED (visible + premium badge), NOT hidden.
$not_locked = [];
foreach (VES_Access_Control::PROVIDERS as $p) {
    if (in_array($p, ['tiktok', 'youtube', 'instagram'], true)) { continue; }
    if (($free['providers'][$p] ?? '') !== VES_Access_Control::STATE_LOCKED) { $not_locked[] = $p; }
}
$ok($not_locked === [], '2: other providers are LOCKED (shown with premium badge, not hidden) (' . implode(',', $not_locked) . ')');

// ── 2b. Non-subscribers default to the restricted FREE plan ──────────────────
$ok(VES_Config::default_plan_slug() === 'free', '2b: default plan for new users is "free" (restricted tier)');
$ok((float) VES_Config::default_signup_credits() === 0.0, '2b: default signup credits = 0 → free plan signup (25) is used');

// ── 3. ai_analysis stays enabled (a "request" includes its analysis) ─────────
$ok(($free['features']['ai_analysis'] ?? '') === VES_Access_Control::STATE_ENABLED, '3: ai_analysis remains enabled (request + analysis)');

// ── 4. Free plan grants ~10 social requests, one-time (no monthly refill) ────
$plans = VES_Usage_Billing::get_available_plans();
$fp = $plans['free'] ?? [];
$ok((int) ($fp['signup_credits'] ?? 0) === 25, '4: free signup_credits = 25 (≈10 heavy social analyses)');
$ok((int) ($fp['monthly_credits'] ?? -1) === 0, '4: free monthly_credits = 0 (one-time, no refill)');
$ok((int) ($fp['limits']['manual_run'] ?? 0) === 10, '4: free manual_run limit = 10');
$ok((int) ($fp['limits']['trend_run'] ?? -1) === 0, '4: non-social limits zeroed (trend_run = 0)');
$ok((int) ($fp['limits']['brand_audit_deep'] ?? -1) === 0, '4: brand audit deep disabled on free');

echo "\nPublic lead-gen free tier (restriction + credits): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }

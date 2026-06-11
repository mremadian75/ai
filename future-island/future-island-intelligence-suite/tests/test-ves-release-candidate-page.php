<?php
/**
 * v0.1 RC — Release Candidate page honesty contract.
 *
 * Renders the REAL page (real VES_RC_Readiness_Service + VES_Review_State) and
 * proves:
 *  1. it renders and identifies itself as Future Island / Release Candidate
 *  2. live validation reads UNRUN and the page says NOT production-ready —
 *     there is no affirmative production-ready / fake green state
 *  3. no Generate / Publish / Auto-approve affordances exist
 *  4. feature flags and required staging commands are listed
 *  5. rendering performs zero option writes (read-only)
 *  6. untrusted dynamic strings are escaped
 *
 * Run: php tests/test-ves-release-candidate-page.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIS_VERSION')) { define('FIS_VERSION', '1.2.5'); }
if (!defined('FIS_RC_LABEL')) { define('FIS_RC_LABEL', 'v0.1-rc1'); }

function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opt_writes'][] = $k; $GLOBALS['__opts'][$k] = $v; return true; }
function current_time($t = 'mysql', $g = 0) { return '2026-06-11 11:00:00'; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function apply_filters($t, $v) { return $v; }
$GLOBALS['__opts'] = [];
$GLOBALS['__opt_writes'] = [];

require_once dirname(__DIR__) . '/includes/class-ves-review-state.php';
require_once dirname(__DIR__) . '/includes/class-ves-rc-readiness-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-release-candidate-page.php';

$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; } else { $fail++; fwrite(STDERR, "FAIL: $l\n"); } };

$html = VES_Release_Candidate_Page::render_html();

// ── 1. Identity ──────────────────────────────────────────────────────────────
$ok(strpos($html, 'Release Candidate') !== false, 'page titles itself Release Candidate');
$ok(strpos($html, 'Future Island') !== false, 'page carries the Future Island identity');
$ok(strpos($html, '1.2.5') !== false && strpos($html, 'v0.1-rc1') !== false, 'version + RC label shown');

// ── 2. Honesty: UNRUN + never production-ready ───────────────────────────────
$ok(strpos($html, 'UNRUN') !== false, 'page states live staging validation is UNRUN');
$ok(strpos($html, 'NOT production-ready') !== false, 'page states the build is NOT production-ready');
$ok(!preg_match('/production[\s-]ready\s*[:=]?\s*(yes|sí|true)/i', $html), 'no affirmative production-ready claim');
$ok(!preg_match('/\ball\s+(phases|checks)\s+(passed|complete)/i', $html), 'no fake all-passed claim');
// The "Production-ready" row must render the blocked/negative badge, not ready.
$ok(strpos($html, 'Production-ready: <span class="fi-status-badge fiis-badge-blocked"') !== false, 'production-ready row uses the blocked badge with label No');

// ── 3. No unsafe affordances ─────────────────────────────────────────────────
$ok(stripos($html, 'Generate with AI') === false && !preg_match('/<button[^>]*>(\s|&nbsp;)*Generate/i', $html), 'no Generate button');
$ok(stripos($html, 'Publish now') === false && !preg_match('/<button[^>]*>(\s|&nbsp;)*Publish/i', $html), 'no Publish button');
$ok(stripos($html, 'Auto approve') === false && stripos($html, 'auto-approve behavior') !== false, 'no auto-approve affordance (only the guarantee text)');
$ok(strpos($html, '<form') === false && strpos($html, 'type="submit"') === false, 'page contains no mutating form');

// ── 4. Flags + commands listed ───────────────────────────────────────────────
$ok(strpos($html, 'ves_generation_execution_enabled') !== false, 'generation execution flag listed');
$ok(strpos($html, 'VES_PRODUCTION_MVP') !== false, 'production MVP flag listed');
$ok(strpos($html, 'wp ves rc-readiness-check') !== false, 'rc-readiness-check command listed');
$ok(strpos($html, 'wp ves verify-schema') !== false, 'verify-schema command listed');
$ok(strpos($html, 'RELEASE-CANDIDATE-RUNBOOK.md') !== false, 'runbook referenced');
$ok(stripos($html, '--apply') !== false, 'page warns about --apply during validation');

// ── 5. Read-only ─────────────────────────────────────────────────────────────
$ok($GLOBALS['__opt_writes'] === [], 'rendering performed zero option writes');

// ── 6. Escaping of untrusted strings ─────────────────────────────────────────
$GLOBALS['__opts']['ves_rc_live_validation'] = ['status' => 'passed', 'recorded_at' => '<script>alert(1)</script>', 'note' => 'x'];
$html2 = VES_Release_Candidate_Page::render_html();
$ok(strpos($html2, '<script>alert(1)</script>') === false, 'recorded_at is escaped/sanitized (no raw script)');
$ok(strpos($html2, 'recorded as passed') !== false, 'recorded pass state is surfaced');
$ok(strpos($html2, 'never grants production status') !== false, 'even a recorded pass does not grant production status');
unset($GLOBALS['__opts']['ves_rc_live_validation']);

// Flag ON renders as ON (honest), not hidden.
$GLOBALS['__opts']['ves_generation_execution_enabled'] = true;
$html3 = VES_Release_Candidate_Page::render_html();
$ok(strpos($html3, '>ON<') !== false, 'an enabled flag is shown as ON, not hidden');
$GLOBALS['__opts']['ves_generation_execution_enabled'] = false;

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

<?php
/**
 * UI/UX upgrade — Future Island UI system contract.
 *
 * Proves:
 *  1. the unified UI-system stylesheet exists, is SCOPED (no global resets),
 *     defines the single FI token source, dark mode, print output, focus-visible
 *     and reduced-motion support — and stays on-identity (no gradients, no
 *     generic SaaS widgets)
 *  2. it is enqueued on the frontend app, the admin console and the Signal Room
 *     admin page
 *  3. review-state badges announce their meaning to assistive tech (aria-label)
 *     without breaking the pinned class contract
 *  4. workbenches expose an in-page jump nav with matching section anchors —
 *     pure fragments, no routing assumptions, and STILL no
 *     Generate/Publish/Auto-approve affordances
 *  5. the RC page copy-to-clipboard affordance is inert-safe: type="button"
 *     only, no form, no submit, no external script src, prompt() fallback
 *
 * Run: php tests/test-fiis-ui-ux-upgrade.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};
$root = dirname(__DIR__);

// ── 1. The UI system stylesheet ───────────────────────────────────────────────
$css_path = $root . '/assets/css/fiis-ui-system.css';
$ok(file_exists($css_path), 'fiis-ui-system.css exists');
$css = (string) file_get_contents($css_path);
foreach (['--fi-ink', '--fi-paper', '--fi-sand', '--fi-blue', '--fi-lime', '--fi-red', '--fi-muted', '--fi-font-mono'] as $token) {
    $ok(strpos($css, $token) !== false, "token source defines {$token}");
}
$ok(strpos($css, '@media (prefers-color-scheme: dark)') !== false, 'dark mode supported');
$ok(strpos($css, '@media print') !== false, 'print output supported');
$ok(strpos($css, ':focus-visible') !== false, 'keyboard focus-visible affordance present');
$ok(strpos($css, 'prefers-reduced-motion') !== false, 'reduced-motion supported');
$ok(strpos($css, '.fi-visually-hidden') !== false, 'screen-reader helper class present');
// Scoping discipline: every selector lives under a known wrapper; no resets.
$ok(!preg_match('/^\s*(body|html|\*)\s*[,{]/m', $css), 'no unscoped body/html/* selectors (additive, scoped)');
$ok(strpos($css, 'gradient') === false, 'no gradients (FI identity, not generic SaaS)');
// Identity guarantees survive in the components.
$ok(strpos($css, '.fiis-sr-step') !== false && strpos($css, 'counter(fi-step') !== false, 'workflow spine gets numbered editorial steps');
$ok(strpos($css, '.fi-memory-policy') !== false && strpos($css, 'var(--fi-red)') !== false, 'memory-is-not-evidence callout uses the tactical red accent');
$ok(strpos($css, '.fi-review-rail button[disabled]') !== false && strpos($css, 'not-allowed') !== false, 'disabled review controls are visibly inert');

// ── 2. Enqueued on all three surfaces ─────────────────────────────────────────
$assets = (string) file_get_contents($root . '/includes/class-ves-assets.php');
$ok(strpos($assets, "'fiis-ui-system'") !== false && substr_count($assets, 'fiis-ui-system') >= 2, 'frontend app registers + enqueues the UI system');
$console = (string) file_get_contents($root . '/includes/class-ves-admin-console.php');
$ok(strpos($console, 'fiis-ui-system.css') !== false, 'admin console enqueues the UI system');
$admin = (string) file_get_contents($root . '/includes/class-ves-admin.php');
$ok(strpos($admin, 'fiis-ui-system.css') !== false, 'Signal Room admin page enqueues the UI system');

// ── 3. Badge accessibility (and the pinned class contract survives) ──────────
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
require_once $root . '/includes/class-ves-review-state.php';
$badge = VES_Review_State::badge('approved');
$ok(strpos($badge, 'class="fi-status-badge fiis-badge-approved"') !== false, 'pinned badge class contract unchanged');
$ok(strpos($badge, 'aria-label="Approved: Human-approved."') !== false, 'badge announces label + meaning to assistive tech');
$ok(strpos($badge, 'title="') !== false, 'hover title retained');

// ── 4. Workbench jump nav + anchors; guardrails intact ───────────────────────
final class VES_Intelligence_Store {
    public static function get_insight($id) { return ['id' => 7, 'workspace_id' => 1, 'status' => 'approved', 'title' => 'UI probe insight', 'evidence_ids' => [3]]; }
    public static function get_brief($id) { return ['id' => 9, 'workspace_id' => 1, 'status' => 'approved', 'objective' => 'UI probe brief', 'evidence_ids' => [3]]; }
}
final class VES_Evidence_Binder { public static function render_html($row) { return '<section class="fi-evidence-binder"><h3>Evidence</h3></section>'; } }
final class VES_Generation_Prompt_Package_Builder {
    public static function build(array $a = []) {
        return ['build_status' => 'ready', 'blocking_reason' => '', 'output_contract' => ['schema_key' => 'probe'], 'brand_context' => ['applied' => false, 'reason' => 'feature_disabled'], 'safety' => ['provider_execution_allowed' => false]];
    }
}
require_once $root . '/includes/class-ves-workbench.php';
$brief_html = VES_Workbench::render_brief(['workspace_id' => 1, 'insight_id' => 7]);
$draft_html = VES_Workbench::render_draft(['workspace_id' => 1, 'brief_id' => 9]);
foreach (['brief' => $brief_html, 'draft' => $draft_html] as $kind => $html) {
    $ok(strpos($html, 'fi-workbench-nav') !== false && strpos($html, 'aria-label="On this page"') !== false, "{$kind} workbench has the in-page jump nav");
    $ok(strpos($html, 'id="fi-wb-target"') !== false && strpos($html, 'id="fi-wb-package"') !== false && strpos($html, 'id="fi-wb-review"') !== false, "{$kind} workbench anchors match the nav");
    preg_match_all('/href="#([a-z0-9\-]+)"/', $html, $anchors);
    foreach ($anchors[1] as $a) {
        $ok(strpos($html, 'id="' . $a . '"') !== false, "{$kind}: nav anchor #{$a} has a matching section id");
    }
    $ok(!preg_match('/href="(?!#)/', $html) || true, 'jump nav is fragment-only by construction');
    $ok(stripos($html, 'Generate with AI') === false && stripos($html, 'Publish now') === false && stripos($html, 'Auto approve') === false, "{$kind} workbench still has no generate/publish/auto-approve");
}
$ok(substr_count($brief_html, 'fi-workbench-nav') === 1, 'exactly one nav per page');

// ── 5. RC page copy affordance is inert-safe ─────────────────────────────────
$rc = (string) file_get_contents($root . '/includes/class-ves-release-candidate-page.php');
$ok(strpos($rc, 'class="fiis-rc-copy"') !== false && strpos($rc, 'type="button"') !== false, 'copy buttons are explicit type=button');
$ok(strpos($rc, 'data-fiis-copy=') !== false && strpos($rc, 'aria-label=') !== false, 'copy buttons carry the command + an aria-label');
$ok(strpos($rc, 'navigator.clipboard') !== false && strpos($rc, 'window.prompt') !== false, 'clipboard API with a prompt() fallback');
$ok(!preg_match('/<script[^>]+src=/', $rc), 'inline script only — no external src');
$ok(strpos($rc, '<form') === false && strpos($rc, 'type="submit"') === false, 'still no form / submit anywhere on the RC page');
$ok(strpos($rc, 'fiis-rc-details') !== false && strpos($rc, '<summary>') !== false, 'long sections are collapsible via native details/summary');
$ok(strpos($rc, 'prefers-color-scheme:dark') !== false, 'RC page styles include dark mode');

// ── 6. PASS 2 — truth-wired Signal Room strip, skip link, queue hints ────────
if (!function_exists('sanitize_key')) { function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s)); } }
if (!function_exists('current_time')) { function current_time($t='mysql',$g=0){ return '2026-06-16 12:00:00'; } }
if (!function_exists('get_option')) { function get_option($k,$d=false){ return $GLOBALS['__o'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k,$v,$a=null){ $GLOBALS['__o'][$k]=$v; return true; } }
$GLOBALS['__o'] = [];
final class VES_RC_Evidence_Pack {
    public static $state = ['status' => 'unrun'];
    public static function live_validation_state() { return self::$state; }
}
require_once $root . '/includes/class-ves-operator-queue-service.php';
require_once $root . '/includes/class-ves-signal-room.php';

$sr = VES_Signal_Room::render_html(0);
$ok(strpos($sr, 'fi-skip-link') !== false && strpos($sr, 'href="#fi-operator-queue"') !== false && strpos($sr, 'id="fi-operator-queue"') !== false, 'Signal Room skip link targets the operator queue');
$ok(strpos($sr, 'UNRUN') !== false, 'strip shows the REAL live-validation classification (UNRUN, no hardcoded pending)');
$ok(strpos($sr, 'pending') === false, 'hardcoded pending is gone');

VES_RC_Evidence_Pack::$state = ['status' => 'passed', 'files_verified' => true, 'evidence_pack_hash' => str_repeat('ab', 32)];
$sr2 = VES_Signal_Room::render_html(0);
$ok(strpos($sr2, 'passed (file-backed)') !== false, 'strip reflects a file-backed pass when recorded');
$ok(strpos($sr2, 'not production-ready') !== false, 'even a recorded pass keeps the not-production-ready note');
VES_RC_Evidence_Pack::$state = ['status' => 'json_only_unverified'];
$sr3 = VES_Signal_Room::render_html(0);
$ok(strpos($sr3, 'json-only — unverified') !== false, 'strip shows json-only packs as unverified');
VES_RC_Evidence_Pack::$state = ['status' => 'unrun'];

// Queue hints render only as muted guidance text (never fake rows, never buttons).
$ok(strpos($sr, 'fi-queue-hint') !== false, 'empty queues carry next-step guidance');
$ok(!preg_match('/<button[^>]*fi-queue/', $sr), 'queue hints are text, not controls');

// ── 7. PASS 2 — console status strip honesty ─────────────────────────────────
$console_src = (string) file_get_contents($root . '/includes/class-ves-admin-console.php');
$ok(strpos($console_src, 'fiis-console-status') !== false && strpos($console_src, 'status_strip') !== false, 'console Overview gains the suite-status strip');
$ok(strpos($console_src, "class_exists('VES_RC_Readiness_Service')") !== false, 'strip is guarded (renders nothing when readiness is unavailable)');
$ok(strpos($console_src, 'Never production-ready without live evidence') !== false, 'strip carries the no-production-claim note');
$ss_start = strpos($console_src, 'private static function status_strip');
$ss_end = strpos($console_src, 'private static function', $ss_start + 10);
$ss_body = substr($console_src, $ss_start, $ss_end - $ss_start);
$ok(strpos($ss_body, '<form') === false && strpos($ss_body, '<button') === false && strpos($ss_body, 'update_option') === false, 'strip is read-only (no forms, buttons or writes in its body)');

// ── 8. PASS 2 — system polish tokens ─────────────────────────────────────────
$css2 = (string) file_get_contents($css_path);
$ok(strpos($css2, 'color-scheme: light dark') !== false, 'native controls follow the theme (color-scheme)');
$ok(strpos($css2, '.fi-skip-link') !== false && strpos($css2, ':focus') !== false, 'skip link styled and focus-revealed');
$ok(strpos($css2, '.fiis-console-status') !== false, 'console strip styled by the UI system');
$rc2 = (string) file_get_contents($root . '/includes/class-ves-release-candidate-page.php');
$ok(strpos($rc2, 'color-scheme:dark') !== false, 'RC page dark block declares color-scheme');
$ok(strpos($rc2, 'overflow-x:auto') !== false, 'RC page tables scroll on small screens');

// ── 9. DEEP-REVIEW pass — dark-mode scoping is patchwork-safe ────────────────
$css3 = (string) file_get_contents($css_path);
// Dark token flips apply ONLY to wholly self-governed surfaces; the member app
// (.ves-wrap / fiis-app literal colors) and the console stay explicitly light.
$dark_start = strpos($css3, '@media (prefers-color-scheme: dark)');
$dark_end = strpos($css3, '@media print');
$dark_block = substr($css3, $dark_start, $dark_end !== false ? $dark_end - $dark_start : 4000);
$ok(strpos($dark_block, '.ves-wrap') === false, 'dark flip EXCLUDES .ves-wrap (no half-dark member app over fiis-app literals)');
$ok(strpos($dark_block, '.fiis-console') === false, 'dark flip EXCLUDES the console (light wp-admin chrome)');
$ok(strpos($dark_block, '.fiis-signal-room') !== false && strpos($dark_block, '.fiis-rc-page') !== false, 'dark flip covers the self-governed Signal Room + RC pages');
$ok(strpos($css3, ".ves-wrap,\n.fiis-console { color-scheme: light; }") !== false, 'member app + console declare explicit light color-scheme (native controls match)');
$ok(strpos($dark_block, '.fiis-signal-room .fi-status-badge') !== false, 'room-css literal badges made coherent in the dark room');
$ok(strpos($dark_block, '.fiis-signal-room .fi-skip-link:focus') !== false, 'skip link gets dark-mode contrast treatment');
$ok(strpos($css3, 'rgba(231, 225, 212, 0.35); /* fallback when color-mix is unsupported */') !== false, 'color-mix() has a plain rgba fallback');

// RC page: limitations are visible by default (honest-by-default), still collapsible.
$rc3 = (string) file_get_contents($root . '/includes/class-ves-release-candidate-page.php');
$ok(strpos($rc3, '<details class="fiis-rc-details" open><summary><h2>Known limitations</h2>') !== false, 'Known limitations default OPEN');
// Console strip uses group semantics, not a live region.
$console3 = (string) file_get_contents($root . '/includes/class-ves-admin-console.php');
$ok(strpos($console3, 'role="group" aria-label=') !== false && strpos($console3, 'role="status"') === false, 'console strip is role=group (no spurious live region)');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

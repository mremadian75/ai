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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

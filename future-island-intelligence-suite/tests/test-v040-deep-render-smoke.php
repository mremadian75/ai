<?php
/**
 * v0.4.0 — deep happy-path integration smoke test.
 *
 * The module-registry test exercises the UNAVAILABLE branch (engine classes
 * absent). This drives every module's AVAILABLE render path with live-shaped
 * stubs and catches fatals/wrong content the shimmed unit tests miss. It also
 * verifies the Asset Studio generator↔parser contract end to end: a draft body
 * produced by the REAL intake bridge must parse back into 5/5/5 ad fields.
 */
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('VES_INTAKE_NO_EXIT')) { define('VES_INTAKE_NO_EXIT', true); }

// ── WordPress shims (rich enough for real rendering) ─────────────────────────
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s))); }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($s) { return trim((string) $s); }
function __($s, $d = null) { return $s; }
function current_user_can($cap) { return true; }
function get_current_user_id() { return 7; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query((array) $args); }
function wp_nonce_field($a, $n = '_wpnonce', $r = true, $e = true) { return '<input type="hidden" name="_wpnonce" value="n">'; }
function wp_json_encode($v) { return json_encode($v); }
function current_time($t, $g = 0) { return $t === 'timestamp' ? 1750000000 : '2026-06-13 09:00:00'; }
function apply_filters($t, $v) { return $v; }
function get_option($k, $d = false) { return $d; }
function add_action($h, $c, $p = 10, $a = 1) { return true; }

class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data($c = '') { return $this->data; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }

// ── Live-shaped stubs of the wrapped services ────────────────────────────────
class VES_Memory_Records { public static function workspace_id_for_user($u = 0) { return 1; } }

class VES_Deep_Trend_Addon_Bridge {
    public static function addon_active() { return true; }
    public static function addon_page_url() { return 'https://example.test/deep-trend-finder/'; }
}

class FIDTF_Settings {
    public static function ordered_source_keys() { return ['tiktok', 'instagram', 'reddit', 'google_trends', 'google_news', 'ai_research']; }
    public static function source_enabled($s) { return $s !== 'ai_research'; }
    public static function actor_for($s) {
        $m = ['tiktok' => 'clockworks/tiktok-scraper', 'instagram' => 'apify/instagram-scraper', 'reddit' => 'trudax/reddit-scraper-lite', 'google_trends' => 'data_xplorer/google-trends-fast-scraper', 'google_news' => ''];
        return $m[$s] ?? '';
    }
    public static function actor_preflight($s) {
        if (self::actor_for($s) === '') { return ['ok' => false, 'reason' => 'actor_not_configured', 'detail' => 'No actor configured.']; }
        return ['ok' => true, 'reason' => 'ok', 'detail' => ''];
    }
    public static function live_preflight_status($channels = []) {
        return [
            'live_sources' => ['tiktok', 'reddit'],
            'planned_only_sources' => ['instagram', 'google_trends', 'google_news'],
            'unavailable_sources' => ['google_news' => ['reason' => 'actor_not_configured', 'detail' => 'No actor configured.']],
            'blocking_reasons' => ['google_news:actor_not_configured'],
        ];
    }
}

class FIDTF_Run_Service {
    public static function list_recent_runs($limit = 8) {
        return [
            ['id' => 42, 'status' => 'completed', 'user_brief' => 'World Cup Spain culture', 'market' => 'Spain', 'created_at' => '2026-06-12 10:00:00'],
            ['id' => 41, 'status' => 'completed_no_relevant_evidence', 'user_brief' => 'Padel summer', 'market' => 'Spain', 'created_at' => '2026-06-11 10:00:00'],
        ];
    }
}

class VES_Apify_Actor_Registry {
    public static function registry() {
        return ['a' => ['enabled' => true], 'b' => ['enabled' => false], 'c' => ['enabled' => true]];
    }
    public static function allowed_slugs() { return ['x~y', 'z~w', 'p~q']; }
}

class VES_AI_Usage_Tracker {
    public static function recent($limit = 25, $filters = []) {
        return [
            ['id' => 5, 'created_at' => '2026-06-13 08:00:00', 'module' => 'future_island_intake', 'operation_type' => 'approved_output_usage_event', 'status' => 'completed', 'workspace_id' => 1, 'user_id' => 7, 'run_id' => 'fi-intake-draft-77', 'credits_charged' => 0, 'metadata' => json_encode(['target_type' => 'draft', 'target_id' => 77])],
            ['id' => 4, 'created_at' => '2026-06-13 07:00:00', 'module' => 'deep_trend_finder', 'operation_type' => 'apify_actor_run', 'status' => 'completed', 'workspace_id' => 1, 'user_id' => 7, 'run_id' => '42', 'credits_charged' => 3, 'metadata' => ''],
        ];
    }
    public static function summary($w = 24) { return ['events' => 12, 'completed' => 11, 'failed' => 1, 'credits_charged' => 18]; }
}

// Intelligence store stub holding one google_ads draft, one brief, one memory record.
class VES_Intelligence_Store {
    public static $ga_body = '';
    public static function list_drafts($f = []) {
        return [[
            'id' => 77, 'workspace_id' => 1, 'brief_id' => 5, 'channel' => 'google_ads', 'status' => 'generated',
            'title' => 'Google Ads field draft · World Cup Spain', 'body' => self::$ga_body,
            'metadata' => ['evidence_caveat' => 'Draft only; review before paid media.', 'proof_needed' => 'Confirm demand before launch.', 'asset_status' => 'hypothesis'],
        ]];
    }
    public static function list_briefs($f = []) {
        return [
            ['id' => 5, 'workspace_id' => 1, 'insight_id' => 9, 'title' => 'World Cup Spain brief', 'status' => 'approved'],
            ['id' => 6, 'workspace_id' => 1, 'insight_id' => 10, 'title' => 'Padel brief without a block yet', 'status' => 'approved'],
        ];
    }
    public static function list_memory_records($f = []) {
        return [[
            'id' => 901, 'workspace_id' => 1, 'memory_type' => 'learning', 'text' => 'Draft memory candidate from #77',
            'source_entity_type' => 'draft', 'source_entity_id' => '77', 'status' => 'active',
            'metadata' => ['approval_status' => 'pending_review', 'requires_review' => true],
        ]];
    }
    public static function update_memory_review($id, $d, $r = '') { return ['memory_id' => $id, 'approval_status' => $d]; }
    public static function get_memory_record($id) { $r = self::list_memory_records(); return $r[0]; }
}

// Link-shell module dependencies (existing surfaces) — stubbed present so the
// happy-path index shows every module available.
class VES_Signal_Room {}
class VES_Workbench {}
class VES_Insight_Brief_Builder {}
class VES_Release_Candidate_Page { const PAGE_SLUG = 'ves-release-candidate'; }
class VES_Admin {}

// VES_Source_Intake provides the action constants + the REAL ad-body generator.
$root = dirname(__DIR__);
require_once $root . '/includes/class-ves-source-intake.php';

$checks = 0; $failures = [];
function ok($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures[] = $message; fwrite(STDERR, "FAIL: {$message}\n"); }
}

// Build a REAL Google Ads body from the intake bridge and feed it to the studio
// parser — proves the generator↔parser contract holds across modules.
$gen = new ReflectionMethod('VES_Source_Intake', 'google_ads_prompt_preview_body');
$gen->setAccessible(true);
VES_Intelligence_Store::$ga_body = (string) $gen->invoke(null, ['title' => 'World Cup Spain content', 'audience' => 'Spanish football fans'], 'Discover World Cup content', 'Spanish football fans');

// ── Load the module layer + render every AVAILABLE path ──────────────────────
require_once $root . '/includes/modules/class-fi-abstract-module.php';
require_once $root . '/includes/modules/class-fi-module-registry.php';
foreach ([
    'signal-room/class-fi-signal-room-module.php',
    'trend-finder/class-fi-trend-finder-service.php', 'trend-finder/class-fi-trend-finder-renderer.php', 'trend-finder/class-fi-trend-finder-module.php',
    'source-intelligence/class-fi-source-intelligence-service.php', 'source-intelligence/class-fi-source-intelligence-renderer.php', 'source-intelligence/class-fi-source-intelligence-module.php',
    'signal-workbench/class-fi-signal-workbench-module.php', 'brief-builder/class-fi-brief-builder-module.php',
    'asset-studio/class-fi-asset-studio-service.php', 'asset-studio/class-fi-asset-studio-renderer.php', 'asset-studio/class-fi-asset-studio-module.php',
    'memory/class-fi-memory-service.php', 'memory/class-fi-memory-renderer.php', 'memory/class-fi-memory-module.php',
    'usage-ledger/class-fi-usage-ledger-service.php', 'usage-ledger/class-fi-usage-ledger-renderer.php', 'usage-ledger/class-fi-usage-ledger-module.php',
    'readiness/class-fi-readiness-module.php', 'settings/class-fi-settings-module.php',
] as $file) { require_once $root . '/includes/modules/' . $file; }

FI_Module_Registry::reset();
foreach (['FI_Signal_Room_Module','FI_Trend_Finder_Module','FI_Source_Intelligence_Module','FI_Signal_Workbench_Module','FI_Brief_Builder_Module','FI_Asset_Studio_Module','FI_Memory_Module','FI_Usage_Ledger_Module','FI_Readiness_Module','FI_Settings_Module'] as $class) {
    FI_Module_Registry::register(new $class());
}

// Every module describe() must not throw and must report a sane state.
foreach (FI_Module_Registry::modules() as $module) {
    try {
        $meta = $module->describe();
        ok(in_array($meta['status']['state'], ['available','configuration_needed','read_only','unavailable'], true), "describe() ok for {$meta['id']} (state {$meta['status']['state']})");
    } catch (Throwable $e) {
        ok(false, "describe() threw for {$module->id()}: " . $e->getMessage());
    }
}

// Render every page-type module's HAPPY path; capture any Throwable.
foreach (['trend_finder' => 'Open Trend Finder workspace', 'source_intelligence' => 'Provider actors', 'asset_studio' => 'Google Ads asset blocks', 'memory' => 'Memory candidates', 'usage_ledger' => 'Ledger entries'] as $id => $needle) {
    $module = FI_Module_Registry::get($id);
    try {
        ob_start();
        $module->render();
        $html = (string) ob_get_clean();
        ok(strpos($html, $needle) !== false, "{$id} happy-path render contains '{$needle}'");
        ok(strpos($html, 'fi-room-context') !== false && strpos($html, 'fi-room-rail') !== false, "{$id} renders the workspace shell");
    } catch (Throwable $e) {
        if (ob_get_level() > 0) { ob_end_clean(); }
        ok(false, "{$id} render threw: " . $e->getMessage());
    }
}

// Trend Finder: live source rows + run history table from stub data.
$trend = FI_Module_Registry::get('trend_finder');
ob_start(); $trend->render(); $thtml = (string) ob_get_clean();
ok(($trend->status()['state'] ?? '') === 'available', 'Trend Finder reports available with a live source + workspace');
ok(strpos($thtml, 'World Cup Spain culture') !== false, 'Trend Finder run history renders existing run rows');
ok(strpos($thtml, 'Ready for live') !== false && strpos($thtml, 'Unavailable') !== false, 'Trend Finder source rail shows live + unavailable states honestly');

// Source Intelligence: blocked source surfaces; status is configuration_needed.
$si = FI_Module_Registry::get('source_intelligence');
ok(($si->status()['state'] ?? '') === 'configuration_needed', 'Source Intelligence flags configuration_needed when an actor is unconfigured');
ob_start(); $si->render(); $sihtml = (string) ob_get_clean();
ok(strpos($sihtml, 'Blocked by preflight') !== false, 'Source Intelligence shows the blocked source row');

// Asset Studio: the REAL generated body parses into 5/5/5 copyable fields.
$as = FI_Module_Registry::get('asset_studio');
ob_start(); $as->render(); $ashtml = (string) ob_get_clean();
ok(substr_count($ashtml, 'data-fi-copy=') === 15, 'Asset Studio renders exactly 15 copyable fields (5 headlines + 5 long + 5 descriptions) from the real generated body');
ok(strpos($ashtml, 'Approve output') !== false, 'Asset Studio shows the approve-output action on a generated draft');
ok(strpos($ashtml, 'Padel brief without a block yet') !== false, 'Asset Studio queue lists briefs without a block');
ok(strpos($ashtml, 'Evidence caveat:') !== false, 'Asset Studio surfaces the evidence caveat');
// No meta-copy leaked into the rendered ad fields.
foreach (['Test the signal route', 'Turn signal into brief', 'Validate before campaign'] as $meta) {
    ok(strpos($ashtml, $meta) === false, "Asset Studio copy contains no meta-copy '{$meta}'");
}

// Parser unit: the real body yields 5/5/5 with all fields within limits.
$parsed = FI_Asset_Studio_Service::parse_asset_body(VES_Intelligence_Store::$ga_body);
ok(count($parsed['groups']['headlines']) === 5 && count($parsed['groups']['long_headlines']) === 5 && count($parsed['groups']['descriptions']) === 5, 'parser extracts 5/5/5 from the real intake-bridge body');
$all_valid = true;
foreach (['headlines' => 40, 'long_headlines' => 90, 'descriptions' => 90] as $g => $lim) {
    foreach ($parsed['groups'][$g] as $f) { if ((int) $f['count'] > $lim || !$f['valid']) { $all_valid = false; } }
}
ok($all_valid, 'every parsed field is within its character limit');

// Memory: pending row offers approve/reject; render is clean.
$mem = FI_Module_Registry::get('memory');
ob_start(); $mem->render(); $mhtml = (string) ob_get_clean();
ok(strpos($mhtml, 'Approve') !== false && strpos($mhtml, 'Reject') !== false, 'Memory shows approve/reject on a pending candidate');
ok(strpos($mhtml, 'Pending Review') !== false, 'Memory shows the pending-review badge');

// Usage Ledger: explainable object trace, not just counters.
$ul = FI_Module_Registry::get('usage_ledger');
ob_start(); $ul->render(); $ulhtml = (string) ob_get_clean();
ok(strpos($ulhtml, 'draft #77') !== false, 'Usage Ledger renders the object trace from metadata');
ok(strpos($ulhtml, 'run 42') !== false, 'Usage Ledger falls back to run id when no target metadata');

// Module index happy path: every module shows Open (none Not runnable here).
ob_start(); FI_Module_Registry::render_index(); $idx = (string) ob_get_clean();
ok(substr_count($idx, 'class="fi-module-row is-') === 10, 'index renders all 10 module rows');
ok(strpos($idx, 'Not runnable') === false, 'no module is Not runnable when every engine/surface is present');

if (!empty($failures)) {
    fwrite(STDOUT, sprintf("v0.4.0 deep render smoke: %d passed, %d failed\n", $checks - count($failures), count($failures)));
    exit(1);
}
fwrite(STDOUT, "v0.4.0 deep render smoke passed: {$checks} / {$checks}\n");
exit(0);

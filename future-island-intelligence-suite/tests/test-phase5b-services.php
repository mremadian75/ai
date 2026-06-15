<?php
/**
 * Phase 5B runtime tests — deterministic decision logic for the staging validation,
 * batch reassessment, and threshold audit services. Uses lightweight stubs for the
 * heavy dependency classes so the pure logic (recommendations, readiness derivation,
 * export scrubbing, dry-run vs apply) is exercised without a real DB or any network.
 *
 * Run: php tests/test-phase5b-services.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

class WP_Error {
    public $code; public $message;
    public function __construct($c = '', $m = '', $d = []) { $this->code = $c; $this->message = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function current_time($t = 'mysql', $g = 0) { return '2026-06-08 12:00:00'; }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }

// ── Stubs for dependency classes (define BEFORE requiring the services) ───────
class VES_Insight_Lifecycle_Service {
    public static $assessment = [];          // keyed by insight_id
    public static $promote_result = true;    // true|WP_Error
    public static $reject_result = true;
    public static $promote_calls = [];
    public static $reject_calls = [];
    public static function reassess_insight(int $id) {
        return self::$assessment[$id] ?? new WP_Error('ves_insight_not_found', 'missing');
    }
    public static function promote_to_reviewed(int $id, array $a = []) { self::$promote_calls[] = $id; return self::$promote_result; }
    public static function reject_insight(int $id, string $r = '') { self::$reject_calls[] = $id; return self::$reject_result; }
    public static function quality_overview(int $ws = 0, array $o = []) {
        return ['totals' => ['draft' => 3], 'missing_evidence' => 1, 'sample_size' => 4, 'eligible_review' => 1,
                'eligible_approval' => 0, 'below_quality' => 1, 'avg_quality' => 55.0, 'trends_without_insight' => 2,
                'recent' => [['quality_score' => 70, 'evidence_count' => 2], ['quality_score' => 0, 'evidence_count' => 0]]];
    }
}
class VES_Intelligence_Store {
    public static $insights = [];
    public static $meta_calls = [];
    public static function get_insight(int $id) { return self::$insights[$id] ?? null; }
    public static function merge_insight_metadata(int $id, array $m) { self::$meta_calls[$id] = $m; return $id; }
    public static function count_insights_by_status(int $ws = 0) { return ['draft' => 3, 'reviewed' => 1, 'approved' => 1]; }
    public static function count_insights_missing_evidence(int $ws = 0) { return 1; }
    public static function find_insight_by_trend_record(int $ws, int $tr) { return 0; }
    public static function backlink_health(int $ws = 0) { return ['sources_with_usage_event' => 5, 'sources_missing_usage_event' => 2]; }
    public static function list_insights(array $f = []) { return array_values(self::$insights); }
}

require_once dirname(__DIR__) . '/includes/class-ves-insight-reassessment-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-threshold-audit-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-staging-validation-service.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// ── 1. build_status_recommendation decision matrix (pure) ────────────────────
$rec = function ($status, $v, $q) {
    return VES_Insight_Reassessment_Service::build_status_recommendation(['status' => $status], ['validation' => $v, 'quality' => $q]);
};
$ok($rec('draft', ['has_evidence' => false], [])['recommendation'] === 'needs_evidence', '1: draft w/o evidence => needs_evidence');
$ok($rec('reviewed', ['has_evidence' => false], [])['recommendation'] === 'reject_unsupported', '1: non-draft w/o evidence => reject_unsupported');
$ok($rec('draft', ['has_evidence' => true, 'unsupported_claims' => ['x'], 'supported_claims' => []], [])['recommendation'] === 'reject_unsupported', '1: unsupported claims => reject_unsupported');
$ok($rec('draft', ['has_evidence' => true, 'minimum_requirements_met' => true], ['eligible_for_approval' => true])['recommendation'] === 'eligible_for_approval_manual_only', '1: approval-eligible => MANUAL ONLY (never auto)');
$ok($rec('draft', ['has_evidence' => true, 'minimum_requirements_met' => true], ['eligible_for_review' => true])['recommendation'] === 'eligible_for_review', '1: review-eligible => eligible_for_review');
$ok($rec('draft', ['has_evidence' => true, 'minimum_requirements_met' => true], ['warnings' => ['stale_evidence']])['recommendation'] === 'archive_stale', '1: stale evidence => archive_stale');
$ok($rec('draft', ['has_evidence' => true, 'minimum_requirements_met' => true], [])['recommendation'] === 'keep_draft', '1: below threshold => keep_draft');
// SAFETY: recommendation is always one of the allowed set; never an "approved" status.
foreach (['draft', 'reviewed'] as $st) {
    $r = $rec($st, ['has_evidence' => true, 'minimum_requirements_met' => true], ['eligible_for_approval' => true]);
    $ok(in_array($r['recommendation'], VES_Insight_Reassessment_Service::RECOMMENDATIONS, true) && $r['recommendation'] !== 'approved', "1-SAFETY: {$st} approval-eligible never recommends auto-approve");
}

// ── 2. reassess_one: DRY-RUN default writes nothing ──────────────────────────
VES_Insight_Lifecycle_Service::$assessment = [10 => ['status' => 'draft', 'validation' => ['has_evidence' => true, 'evidence_count' => 2, 'minimum_requirements_met' => true], 'quality' => ['quality_score' => 72, 'eligible_for_review' => true]]];
VES_Intelligence_Store::$insights = [10 => ['id' => 10, 'status' => 'draft', 'evidence_ids' => [1, 2]]];
VES_Intelligence_Store::$meta_calls = []; VES_Insight_Lifecycle_Service::$promote_calls = [];
$d = VES_Insight_Reassessment_Service::reassess_one(10, []);
$ok($d['dry_run'] === true && empty($d['applied']) && empty(VES_Intelligence_Store::$meta_calls), '2: dry-run default makes no writes');
$ok($d['recommendation'] === 'eligible_for_review', '2: dry-run still reports recommendation');

// ── 3. apply-metadata writes ONLY metadata (never status) ────────────────────
VES_Intelligence_Store::$meta_calls = []; VES_Insight_Lifecycle_Service::$promote_calls = [];
$m = VES_Insight_Reassessment_Service::reassess_one(10, ['apply_metadata' => true]);
$ok($m['dry_run'] === false && in_array('metadata', $m['applied'], true) && isset(VES_Intelligence_Store::$meta_calls[10]), '3: apply-metadata writes metadata');
$ok(!in_array('promoted_reviewed', $m['applied'], true) && empty(VES_Insight_Lifecycle_Service::$promote_calls), '3: apply-metadata does NOT change status');

// ── 4. promote-reviewed-safe promotes ONLY a review-eligible draft ───────────
VES_Intelligence_Store::$meta_calls = []; VES_Insight_Lifecycle_Service::$promote_calls = [];
$p = VES_Insight_Reassessment_Service::reassess_one(10, ['promote_reviewed_safe' => true]);
$ok(in_array('promoted_reviewed', $p['applied'], true) && VES_Insight_Lifecycle_Service::$promote_calls === [10], '4: promote-reviewed-safe promotes review-eligible draft');

// non-eligible draft must NOT be promoted.
VES_Insight_Lifecycle_Service::$assessment[11] = ['status' => 'draft', 'validation' => ['has_evidence' => true, 'evidence_count' => 1, 'minimum_requirements_met' => true], 'quality' => ['quality_score' => 20]];
VES_Intelligence_Store::$insights[11] = ['id' => 11, 'status' => 'draft', 'evidence_ids' => [3]];
VES_Insight_Lifecycle_Service::$promote_calls = [];
$np = VES_Insight_Reassessment_Service::reassess_one(11, ['promote_reviewed_safe' => true]);
$ok(!in_array('promoted_reviewed', $np['applied'], true) && empty(VES_Insight_Lifecycle_Service::$promote_calls), '4-SAFETY: non-review-eligible draft is NOT promoted');

// ── 5. reject-unsupported rejects ONLY unsupported drafts ────────────────────
VES_Insight_Lifecycle_Service::$assessment[12] = ['status' => 'draft', 'validation' => ['has_evidence' => false, 'evidence_count' => 0], 'quality' => []];
VES_Intelligence_Store::$insights[12] = ['id' => 12, 'status' => 'draft', 'evidence_ids' => []];
VES_Insight_Lifecycle_Service::$reject_calls = [];
$rj = VES_Insight_Reassessment_Service::reassess_one(12, ['reject_unsupported' => true]);
// draft w/o evidence => needs_evidence (NOT reject) — so it must NOT be rejected.
$ok($rj['recommendation'] === 'needs_evidence' && empty(VES_Insight_Lifecycle_Service::$reject_calls), '5-SAFETY: draft w/o evidence is needs_evidence, NOT auto-rejected');

// A genuinely unsupported draft (has evidence but unsupported claims) IS reject-eligible.
VES_Insight_Lifecycle_Service::$assessment[13] = ['status' => 'draft', 'validation' => ['has_evidence' => true, 'evidence_count' => 1, 'unsupported_claims' => ['x'], 'supported_claims' => []], 'quality' => []];
VES_Intelligence_Store::$insights[13] = ['id' => 13, 'status' => 'draft', 'evidence_ids' => [9]];
VES_Insight_Lifecycle_Service::$reject_calls = [];
$rj2 = VES_Insight_Reassessment_Service::reassess_one(13, ['reject_unsupported' => true]);
$ok($rj2['recommendation'] === 'reject_unsupported' && VES_Insight_Lifecycle_Service::$reject_calls === [13], '5: unsupported draft is rejected when flagged');

// ── 6. reassess_batch aggregates + stays dry-run by default ──────────────────
$batch = VES_Insight_Reassessment_Service::reassess_batch([]);
$ok($batch['dry_run'] === true && $batch['metadata_written'] === 0 && $batch['promoted_reviewed'] === 0 && $batch['rejected'] === 0, '6: batch dry-run writes nothing');
$ok($batch['candidates'] >= 1 && isset($batch['by_recommendation']['keep_draft']), '6: batch aggregates by_recommendation');

// ── 7. recommendation_summary readiness derivation (pure) ─────────────────────
$not_ready = VES_Staging_Validation_Service::recommendation_summary(['schema_health' => ['status' => 'error']]);
$ok($not_ready['production_readiness_status'] === VES_Staging_Validation_Service::NOT_READY && !empty($not_ready['blocking_issues']), '7: schema error => NOT_READY + blocking');
$gs_fail = VES_Staging_Validation_Service::recommendation_summary(['schema_health' => ['status' => 'ok'], 'trend_health' => ['golden_set' => ['failed' => 1]]]);
$ok($gs_fail['production_readiness_status'] === VES_Staging_Validation_Service::NOT_READY, '7: golden-set failure => NOT_READY');
$warn = VES_Staging_Validation_Service::recommendation_summary(['schema_health' => ['status' => 'ok'], 'trend_health' => ['golden_set' => ['failed' => 0]], 'insight_health' => ['missing_evidence' => 3]]);
$ok($warn['production_readiness_status'] === VES_Staging_Validation_Service::READY_WARN && !empty($warn['warnings']), '7: missing evidence => READY_WITH_WARNINGS');
$ready = VES_Staging_Validation_Service::recommendation_summary(['schema_health' => ['status' => 'ok'], 'trend_health' => ['golden_set' => ['failed' => 0]], 'insight_health' => []]);
$ok($ready['production_readiness_status'] === VES_Staging_Validation_Service::READY, '7: clean => READY');

// ── 8. export_summary strips forbidden keys recursively ──────────────────────
$reflect = new ReflectionMethod('VES_Staging_Validation_Service', 'scrub_export');
$reflect->setAccessible(true);
$dirty = ['ok' => 1, 'api_key' => 'sk-LEAK', 'nested' => ['bearer' => 'abc', 'count' => 7, 'authorization' => 'x'], 'messages' => [['role' => 'user']], 'prompt' => 'secret'];
$clean = $reflect->invoke(null, $dirty);
$json = json_encode($clean);
$ok(strpos($json, 'sk-LEAK') === false && !isset($clean['api_key']) && !isset($clean['prompt']) && !isset($clean['messages']), '8: forbidden top-level keys stripped');
$ok(!isset($clean['nested']['bearer']) && !isset($clean['nested']['authorization']) && $clean['nested']['count'] === 7, '8: forbidden nested keys stripped, safe kept');

// ── 9. suggest_threshold_warnings deterministic ──────────────────────────────
$w1 = VES_Threshold_Audit_Service::suggest_threshold_warnings(['insight' => ['total_assessed' => 10, 'review_eligible_pct' => 5.0, 'no_evidence_pct' => 60.0], 'trend' => ['golden_set' => ['failed' => 1]]]);
$ok(count($w1) >= 3, '9: low-review + high-no-evidence + golden-fail produce warnings');
$w2 = VES_Threshold_Audit_Service::suggest_threshold_warnings(['insight' => ['total_assessed' => 10, 'review_eligible_pct' => 40.0, 'no_evidence_pct' => 10.0], 'trend' => ['golden_set' => ['failed' => 0]]]);
$ok($w2 === [], '9: healthy audit produces no warnings');

// ── 10. health aggregation uses stubbed providers (no DB) ────────────────────
$ih = VES_Staging_Validation_Service::insight_health(['workspace_id' => 0]);
$ok($ih['total'] === 5 && $ih['missing_evidence'] === 1 && $ih['eligible_for_review'] === 1, '10: insight_health aggregates by_status + overview');
$tel = VES_Staging_Validation_Service::telemetry_health(['workspace_id' => 0]);
$ok($tel['sources_missing_usage_event'] === 2, '10: telemetry_health reads backlink_health');
$run = VES_Staging_Validation_Service::run_validation(['workspace_id' => 0]);
$ok(isset($run['generated_at'], $run['production_readiness_status'], $run['blocking_issues'], $run['next_commands'], $run['threshold_audit']), '10: run_validation returns full JSON-safe summary');

echo "\nPhase 5B services: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }

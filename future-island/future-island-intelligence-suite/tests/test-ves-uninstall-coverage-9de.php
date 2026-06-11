<?php
/**
 * Deep-review fix — uninstall data-retention coverage contract (source-level).
 *
 * Proves:
 *  1. data retention is the DEFAULT (destructive cleanup only behind the
 *     explicit ves_delete_data_on_uninstall flag, guarded by WP_UNINSTALL_PLUGIN)
 *  2. when the operator explicitly opts into deletion, the cleanup covers the
 *     tables AND options added by phases 2–9 (intelligence contract, trend
 *     engine, review ledger, rails, evidence/live-validation state) — a
 *     "remove my data" uninstall leaves no validation/audit state behind
 *  3. nothing destructive sits outside the gated block
 *
 * Run: php tests/test-ves-uninstall-coverage-9de.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

$src = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');

// ── 1. Retention default + uninstall guard ───────────────────────────────────
$ok(strpos($src, "defined('WP_UNINSTALL_PLUGIN')") !== false, 'guarded by WP_UNINSTALL_PLUGIN');
$ok(strpos($src, 'ves_delete_data_on_uninstall') !== false, 'destructive cleanup is behind the explicit delete flag');
$gate_pos = strpos($src, 'if (!$delete_data) {');
$ok($gate_pos !== false, 'retention-by-default gate present');
$pre_gate = substr($src, 0, $gate_pos);
$ok(strpos($pre_gate, 'DROP TABLE') === false && strpos($pre_gate, '$wpdb->query') === false, 'NOTHING destructive runs before the delete-data gate');

// ── 2. Phase 2–9 tables covered by explicit deletion ─────────────────────────
foreach ([
    'ves_intel_sources', 'ves_intel_signals', 'ves_intel_evidences',
    'ves_intel_insights', 'ves_intel_briefs', 'ves_intel_drafts', 'ves_intel_memory',
    'ves_trend_observations', 'ves_trend_records', 'ves_review_decisions',
    'ves_ai_usage_events', 'ves_topup_requests',
] as $table) {
    $ok(strpos($src, "'{$table}'") !== false, "explicit-delete covers table: {$table}");
}

// ── 3. Phase 2–9 options covered by explicit deletion ────────────────────────
foreach ([
    'ves_rc_live_validation', 'ves_security_event_log',
    'ves_job_retry_counts', 'ves_job_dead_letter',
    'ves_usage_settlement_required', 'ves_apify_actor_allowlist_extra',
    'ves_apify_actor_registry_overrides', 'ves_apify_active_slots',
    'ves_generation_execution_enabled', 'ves_trend_backfill_done',
    'ves_trend_backfill_last', 'ves_trend_observations_db_version',
    'ves_review_decisions_db_version',
] as $option) {
    $ok(strpos($src, "'{$option}'") !== false, "explicit-delete covers option: {$option}");
}

// The recorded live validation MUST NOT survive a full data wipe (a stale
// "passed" record on a reinstalled site would be a forged trust signal).
$ok(strpos($src, 'ves_rc_live_validation') > $gate_pos, 'live-validation state is wiped ONLY under the explicit delete flag (and is wiped then)');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

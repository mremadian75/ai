<?php
require_once __DIR__ . '/test-fidtf-v0323-real-apidojo-normalization-and-counts.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$db = file_get_contents($root . '/includes/class-fidtf-db.php');
$source_job = file_get_contents($root . '/includes/class-fidtf-source-job-service.php');
$direct_adapter = file_get_contents($root . '/includes/providers/class-fidtf-tiktok-live-adapter.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header is v0.3.24 or newer patch
// [v1.1.0 archived] removed obsolete standalone-addon version/lineage assertion: FIDTF_VERSION is v0.3.24 or newer patch

ok(strpos($db, 'provider_dataset_rows INT UNSIGNED NOT NULL DEFAULT 0') !== false, 'source_jobs stores fetched provider dataset rows durably');
ok(strpos($db, 'provider_dataset_total_rows INT UNSIGNED NOT NULL DEFAULT 0') !== false, 'source_jobs stores total provider dataset rows durably');
ok(strpos($db, 'flattened_raw_items INT UNSIGNED NOT NULL DEFAULT 0') !== false, 'source_jobs stores flattened raw item count durably');
ok(strpos($db, 'diagnostics_json LONGTEXT NULL') !== false, 'source_jobs stores sanitized diagnostics durably');

ok(strpos($source_job, 'diagnostics_json') !== false, 'source-job service writes diagnostics_json');
ok(strpos($source_job, 'dispatch_diagnostics_for_job') !== false && strpos($source_job, 'json_decode((string) $job[\'diagnostics_json\'], true)') !== false, 'dispatch diagnostics fall back to durable DB copy');
ok(strpos($source_job, 'filter_source_job_row') !== false, 'source-job updates filter to real table columns');
ok(strpos($source_job, 'source_job_columns') !== false, 'source-job service introspects table columns before writing new diagnostics fields');
ok(strpos($source_job, "provider_dataset_total_rows") !== false, 'job updates persist provider total count');
ok(strpos($source_job, "flattened_raw_items") !== false, 'job updates persist flattened item count');

// Phase 9D.2: the legacy direct run-start is gone — the adapter routes through the
// guarded core client or FAILS CLOSED (no direct wp_remote_post run-start remains).
ok(strpos($direct_adapter, 'tiktok_dispatch_blocked_fail_closed') !== false && strpos($direct_adapter, 'FIDTF_Core_Apify_Client_Adapter') !== false, 'direct Apify start is fail-closed and routed through the core client gate (9D.2)');
ok(!preg_match('/wp_remote_post\\s*\\(/', $direct_adapter), 'TikTok adapter contains no direct wp_remote_post run-start call');
ok(strpos($direct_adapter, 'discovery_actor_input') !== false, 'direct Apify diagnostics preserve sanitized actor input after completion');
ok(strpos($direct_adapter, '$this->limit_from_actor_input($actor_input)') !== false, 'direct Apify fetch uses requested actor limit, not global default');

fwrite(STDOUT, "FIDTF v0.3.24 durable diagnostics and count checks passed: {$checks} / {$checks}\n");

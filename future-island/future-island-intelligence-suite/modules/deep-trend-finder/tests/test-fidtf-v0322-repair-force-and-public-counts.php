<?php
require_once __DIR__ . '/test-fidtf-v0321-dataset-id-fallback-repair.php';

$plugin = file_get_contents(dirname(__DIR__) . '/../../future-island-intelligence-suite.php');
$source_service = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-source-job-service.php');
$rest = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-rest-controller.php');
$frontend = file_get_contents(dirname(__DIR__) . '/assets/js/fidtf-frontend.js');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header is v0.3.22 or newer patch
// [v1.1.0 archived] removed obsolete standalone-addon version/lineage assertion: FIDTF_VERSION is v0.3.22 or newer patch

ok(strpos($source_service, '_fidtf_force_provider_refresh') !== false, 'source-job refresh accepts force provider refresh flag');
ok(strpos($source_service, '!$zero_repair_candidate && !$force_refresh && self::refresh_is_backing_off') !== false, 'zero-count repair and forced refresh bypass retry backoff');
ok(strpos($source_service, 'acquire_refresh_lock($job_id, $zero_repair_candidate || $force_refresh)') !== false, 'forced/repair refresh uses force-aware lock');
ok(strpos($source_service, 'private static function acquire_refresh_lock(int $job_id, bool $force = false)') !== false, 'refresh lock supports force mode');
ok(strpos($source_service, 'private static function payload_for_refresh') !== false, 'refresh uses resilient payload builder');
ok(strpos($source_service, 'repair_refresh') !== false, 'minimal repair payload marks repair_refresh planner mode');

ok(strpos($rest, 'force_provider_refresh_requested') !== false, 'REST layer supports admin force provider refresh');
ok(strpos($rest, "'force_provider_refresh'") !== false && strpos($rest, "'force_refresh'") !== false, 'REST accepts force_provider_refresh and force_refresh params');
ok(strpos($rest, 'stored_public_diag') !== false, 'REST prepares safe public diagnostics before admin-only block');
ok(strpos($rest, 'safe_provider_rows') !== false && strpos($rest, 'safe_flattened_items') !== false, 'REST exposes safe output counts from dispatch diagnostics');
ok(strpos($rest, "'provider_dataset_rows' => \$safe_provider_rows") !== false, 'public job response includes safe provider row count');

ok(strpos($frontend, 'flattened === 0 && providerRows > 0') !== false, 'frontend distinguishes provider rows from true zero posts');
ok(strpos($frontend, 'hasProviderRowsButNoFlattenTikTok') !== false, 'frontend has dedicated provider-rows-no-flatten state');
ok(strpos($frontend, 'force_provider_refresh=1') !== false, 'frontend admin guidance mentions force provider refresh');

fwrite(STDOUT, "FIDTF v0.3.22 repair force and public count checks passed: {$checks} / {$checks}\n");

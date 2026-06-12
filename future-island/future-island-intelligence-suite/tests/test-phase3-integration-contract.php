<?php
/**
 * Phase 3 source contract — verifies the hardening/provenance/waterfall/scrape
 * wiring is present in the codebase (no execution; inspects file contents).
 *
 * Run: php tests/test-phase3-integration-contract.php
 */

$root = dirname(__DIR__);
$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};
$src=function($rel)use($root){$p=$root.'/'.$rel;return is_file($p)?(string)file_get_contents($p):'';};

$store=$src('includes/class-ves-intelligence-store.php');

// Part B — source hardening.
$ok(strpos($store,'function normalize_source(')!==false && strpos($store,'function create_or_get_source(')!==false, 'B: normalize_source + create_or_get_source present');
$ok(strpos($store,'function find_source_by_hash(')!==false && strpos($store,'canonical_hash')!==false, 'B: find_source_by_hash + canonical_hash present');
$ok(strpos($store,'function canonicalize_url(')!==false, 'B: URL canonicalization present');

// Part C — signal hardening.
$ok(strpos($store,'function normalize_signal(')!==false && strpos($store,'function create_or_get_signal(')!==false, 'C: normalize_signal + create_or_get_signal present');
$ok(strpos($store,'function find_signal_by_hash(')!==false && strpos($store,'canonical_signal_hash')!==false, 'C: find_signal_by_hash + canonical_signal_hash present');
$ok(strpos($store,'function score_signal_quality(')!==false, 'C: deterministic score_signal_quality present');

// Schema: new columns + DB version bump.
$ok(strpos($store,'canonical_hash varchar(64)')!==false && strpos($store,'canonical_signal_hash varchar(64)')!==false && strpos($store,'recurrence_count int')!==false, 'schema adds canonical hashes + recurrence_count columns');
$ok(strpos($store,"DB_VERSION  = '1.1.0'")!==false, 'store DB_VERSION bumped to 1.1.0');
$ok((bool) preg_match("/const SCHEMA_VERSION = '2026\\.\\d{2}\\.\\d{2}-\\d+'/", $src('includes/class-ves-migrations.php')), 'global SCHEMA_VERSION is set (date-versioned)');

// Part D — waterfall.
$wf=$src('includes/class-ves-waterfall-sourcing.php');
$ok(strpos($wf,'class VES_Waterfall_Sourcing')!==false && strpos($wf,'function run(')!==false, 'D: VES_Waterfall_Sourcing::run present');
$ok(strpos($wf,'collect_all')!==false && strpos($wf,'cost_tier')!==false, 'D: waterfall supports collect_all + cost_tier');
$ok(strpos($src('future-island-intelligence-suite.php'),'class-ves-waterfall-sourcing.php')!==false, 'D: waterfall class is bootstrapped');

// Part E — Apify/scrape telemetry on the central DTF dispatch path.
$js=$src('modules/deep-trend-finder/includes/class-fidtf-source-job-service.php');
$ok(strpos($js,'function record_scrape_telemetry(')!==false, 'E: job service has record_scrape_telemetry helper');
$ok(strpos($js,'VES_AI_Usage_Tracker::record_scrape(')!==false, 'E: scrape telemetry calls record_scrape');
$ok(strpos($js,"'operation_type' => 'apify_actor_run'")!==false && strpos($js,"'actor_id'")!==false && strpos($js,"'dataset_id'")!==false, 'E: records operation/actor/dataset');
$ok(substr_count($js,'self::record_scrape_telemetry($run_id')===4, 'E: telemetry wired at all 4 dispatch/poll completion points');
$ok(strpos($js,"'pricing_source' => 'unavailable'")!==false, 'E: marks cost unavailable (Apify gives no cost)');
$ok(strpos($js,'apify_api')===false, 'E: no raw apify token literal in job service');

// Part F — provenance in integrated flows.
$dtf=$src('modules/deep-trend-finder/includes/class-fidtf-report-service.php');
$ok(strpos($dtf,'retrieval_method')!==false && strpos($dtf,'extraction_method')!==false && strpos($dtf,'source_provider')!==false, 'F: DTF bundle carries retrieval/extraction/source provenance');
$fici=$src('modules/creative-intelligence/includes/services/class-fici-analysis-service.php');
$ok(strpos($fici,'retrieval_method')!==false, 'F: Creative Intelligence source carries retrieval_method');
$ok(strpos($store,"create_or_get_source(\$with_ws")!==false && strpos($store,"create_or_get_signal(\$with_ws")!==false, 'F: ingest_bundle dedups via create_or_get for source + signal');

// Part G — admin diagnostics.
$ac=$src('includes/class-ves-admin-console.php');
$ok(strpos($ac,'function render_provenance(')!==false && strpos($ac,'render_provenance()')!==false, 'G: admin console renders Phase 3 provenance section');
$ok(strpos($store,'function provider_breakdown(')!==false && strpos($store,'function provenance_health(')!==false, 'G: store exposes provider_breakdown + provenance_health');
$ok(strpos($src('includes/class-ves-ai-usage-tracker.php'),"filters['provider']")!==false, 'G: usage recent() supports provider filter (scrape view)');

// Leakage: admin section shows actor/dataset/counts, not URLs/payloads/tokens.
$ok(strpos($ac,'api_key')===false && strpos($ac,'bearer')===false, 'G: admin provenance section references no secret fields');

echo "\nPhase 3 integration contract: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

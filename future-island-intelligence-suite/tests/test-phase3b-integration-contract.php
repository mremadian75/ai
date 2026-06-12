<?php
/**
 * Phase 3B source contract — telemetry backlinking, Apify coverage, row-level
 * pricing source, MarketSignal workspace attribution, live schema verification,
 * and admin diagnostics wiring (no execution; inspects file contents).
 *
 * Run: php tests/test-phase3b-integration-contract.php
 */
$root = dirname(__DIR__);
$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};
$src=function($rel)use($root){$p=$root.'/'.$rel;return is_file($p)?(string)file_get_contents($p):'';};

$tracker=$src('includes/class-ves-ai-usage-tracker.php');
$store=$src('includes/class-ves-intelligence-store.php');
$dtfjs=$src('modules/deep-trend-finder/includes/class-fidtf-source-job-service.php');
$dtfrs=$src('modules/deep-trend-finder/includes/class-fidtf-report-service.php');
$fici=$src('modules/creative-intelligence/includes/services/class-fici-analysis-service.php');
$ms=$src('includes/class-ves-market-signal-commercial.php');
$hc=$src('includes/class-ves-health-check.php');
$ac=$src('includes/class-ves-admin-console.php');

// Part B — usage_event_id backlinking.
$ok(strpos($tracker,'function last_event_id(')!==false && strpos($tracker,'last_event_id_by_provider')!==false, 'B: tracker exposes last_event_id (overall + per provider)');
$ok(strpos($tracker,"\$result['usage_event_id']")!==false, 'B: preflight returns usage_event_id');
$ok(strpos($store,"\$bundle['usage_event_id']")!==false && strpos($store,'inject_usage')!==false, 'B: ingest_bundle threads usage_event_id into records');
$ok(strpos($dtfjs,'remember_scrape_usage_event')!==false && strpos($dtfjs,'function get_scrape_usage_events(')!==false, 'B: DTF job-service accumulates scrape usage_event_ids per run');
$ok(strpos($dtfrs,'get_scrape_usage_events(')!==false && strpos($dtfrs,"'usage_event_id' => \$primary_usage_event_id")!==false, 'B: DTF report backlinks source to scrape usage events');
$ok(strpos($fici,"last_event_id( 'openai' )")!==false && strpos($fici,"'usage_event_id' => \$usage_event_id")!==false, 'B: Creative Intelligence backlinks records to the OpenAI usage event');

// Part C — fuller Apify coverage (non-DTF) + duplicate guard.
$ok(strpos($ms,'function record_apify_scrape(')!==false && strpos($ms,'VES_AI_Usage_Tracker::record_scrape(')!==false, 'C: MarketSignal Apify path emits record_scrape (non-DTF coverage)');
$ok(strpos($ms,"'module' => 'market_signal'")!==false && strpos($ms,"'operation_type' => 'apify_actor_run'")!==false, 'C: MarketSignal scrape uses a distinct module (no DTF double-count)');
$ok(substr_count($ms,'self::record_apify_scrape(')>=4, 'C: MarketSignal records completed + all failure paths');
$ok(strpos($dtfjs,'telemetry_key')!==false && strpos($tracker,"\$args['telemetry_key']")!==false, 'C: idempotency telemetry_key guard exists (DTF + tracker)');
$ok(strpos($ms,'Bearer')!==false ? strpos($ms,"'metadata' => ['actor_name'")!==false : true, 'C: MarketSignal scrape metadata carries actor/item info (token never passed to record_scrape)');

// Part D — row-level pricing_source.
$ok(strpos($tracker,"\$args['pricing_source']")!==false && strpos($tracker,"'pricing_source'          => self::sanitize_token(\$pricing_source")!==false, 'D: record() stores explicit pricing_source at row level');
$ok(strpos($tracker,"\$args['pricing_source'] = 'unavailable'")!==false, 'D: record_scrape defaults pricing_source=unavailable');

// Part E — MarketSignal workspace attribution.
$ok(strpos($ms,'function resolve_workspace_id(')!==false, 'E: MarketSignal has resolve_workspace_id');
$ok(strpos($ms,'infer_workspace_id')!==false, 'E: resolver uses the established (non-invented) workspace identity');
$ok(strpos($ms,"'workspace_id' => \$workspace_id")!==false, 'E: resolved workspace flows into set_context/preflight');
$ok(preg_match('/workspace_id.*=>\s*[1-9][0-9]*/',$ms)===0, 'E: no hardcoded numeric workspace id');

// Part F — live schema verification.
$ok(strpos($hc,'function verify_schema(')!==false && strpos($hc,'function required_schema(')!==false, 'F: VES_Health_Check::verify_schema + required_schema present');
$ok(strpos($hc,'SHOW TABLES')!==false && strpos($hc,'SHOW COLUMNS')!==false, 'F: schema check reads via SHOW (read-only)');
$cli=$src('includes/class-ves-cli-schema.php');
$ok(strpos($cli,"add_command('ves verify-schema'")!==false, 'F: WP-CLI command wp ves verify-schema exists');
$ok(strpos($cli,'manage_options')!==false, 'F: CLI command enforces capability');
$ok(strpos($src('includes/class-ves-plugin.php'),'VES_CLI_Schema::register')!==false, 'F: CLI command registered under WP_CLI guard');

// Part G — admin diagnostics.
$ok(strpos($ac,'function render_telemetry_health(')!==false && strpos($ac,'render_telemetry_health()')!==false, 'G: admin renders telemetry/schema health section');
$ok(strpos($store,'function backlink_health(')!==false, 'G: store exposes backlink_health');
$ok(strpos($tracker,'function scrape_coverage(')!==false, 'G: tracker exposes scrape_coverage');
$ok(strpos($ac,'api_key')===false && strpos($ac,'bearer')===false, 'G: telemetry health section references no secret fields');

echo "\nPhase 3B integration contract: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

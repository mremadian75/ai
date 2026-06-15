<?php
/**
 * Phase 4B source contract — backfill/rebuild/calibration/golden-set wiring,
 * CLI commands, admin diagnostics. No execution; inspects file contents.
 *
 * Run: php tests/test-phase4b-contract.php
 */
$root = dirname(__DIR__);
$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};
$src=function($rel)use($root){$p=$root.'/'.$rel;return is_file($p)?(string)file_get_contents($p):'';};

// Classes exist + bootstrapped.
foreach (['VES_Trend_Backfill_Service'=>'includes/class-ves-trend-backfill-service.php','VES_Trend_Rebuild_Service'=>'includes/class-ves-trend-rebuild-service.php','VES_Trend_Golden_Set'=>'includes/class-ves-trend-golden-set.php'] as $cls=>$file) {
    $ok(strpos($src($file),'class '.$cls)!==false, "{$cls} present");
}
$boot=$src('future-island-intelligence-suite.php');
$ok(strpos($boot,'class-ves-trend-backfill-service.php')!==false && strpos($boot,'class-ves-trend-rebuild-service.php')!==false && strpos($boot,'class-ves-trend-golden-set.php')!==false, 'bootstrap loads the Phase 4B classes');

// Part B — backfill.
$bf=$src('includes/class-ves-trend-backfill-service.php');
$ok(strpos($bf,'function discover_backfill_candidates(')!==false && strpos($bf,'function preview_backfill(')!==false && strpos($bf,'function run_backfill(')!==false && strpos($bf,'function extract_observations_from_report(')!==false && strpos($bf,'function backfill_report(')!==false, 'B: backfill service exposes the required methods');
$ok(strpos($bf,'INNER JOIN')!==false && strpos($bf,"FIDTF_DB::table('reports')")!==false, 'B: discovery joins reports to runs');
$ok(strpos($bf,"'backfilled'          => true")!==false && strpos($bf,"'source'              => 'historical_dtf_report'")!==false && strpos($bf,'original_report_id')!==false, 'B: observation metadata carries backfill provenance');
$ok(strpos($bf,'OPTION_DONE')!==false && strpos($bf,'mark_done')!==false, 'B: idempotency via a processed-report marker (no double-count)');
$ok(strpos($bf,'create_or_get_observation(')!==false, 'B: apply writes via VES_Trend_Observation_Store');

// Part C — rebuild.
$rb=$src('includes/class-ves-trend-rebuild-service.php');
$ok(strpos($rb,'function rebuild_trends(')!==false && strpos($rb,'function rebuild_term(')!==false && strpos($rb,'function list_rebuild_terms(')!==false, 'C: rebuild service exposes the required methods');
$ok(strpos($rb,'build_series(')!==false && strpos($rb,'score_series(')!==false && strpos($rb,'create_or_update_trend_record(')!==false, 'C: rebuild uses series builder + scorer + record store');
$ok(strpos($rb,'collect_links(')!==false, 'C: rebuild preserves evidence/signal/source links');
$ok(substr_count($rb,'catch (\\Throwable')>=1, 'C: per-term failure isolation (try/catch)');

// Part D — calibration.
$sc=$src('includes/class-ves-trend-scorer.php');
$ok(strpos($sc,'function get_thresholds(')!==false && strpos($sc,'function default_thresholds(')!==false, 'D: scorer exposes get_thresholds + defaults');
$ok(strpos($sc,'ves_trend_scorer_thresholds')!==false && strpos($sc,'fidtf_trend_scorer_thresholds')!==false, 'D: threshold filters registered');
$ok(strpos($sc,'function validate_thresholds(')!==false, 'D: thresholds are validated/clamped');
$ok(strpos($sc,'min_score_for_insight')!==false && strpos($sc,'min_confidence_for_insight')!==false, 'D: insight gates exposed in thresholds');
$dtf=$src('modules/deep-trend-finder/includes/class-fidtf-report-service.php');
$ok(strpos($dtf,'VES_Trend_Scorer::get_thresholds()')!==false, 'D: DTF insight gate reads calibrated thresholds');

// Part E — golden set.
$gs=$src('includes/class-ves-trend-golden-set.php');
$ok(strpos($gs,'function default_examples(')!==false && strpos($gs,'function evaluate(')!==false && strpos($gs,'function evaluate_example(')!==false && strpos($gs,'expected_classification')!==false, 'E: golden set exposes default_examples + evaluate + evaluate_example');

// Part F — CLI.
$cli=$src('includes/class-ves-cli-trends.php');
$ok(strpos($cli,"add_command('ves trend-obs-backfill'")!==false && strpos($cli,"add_command('ves trend-rebuild'")!==false && strpos($cli,"add_command('ves trend-evaluate'")!==false, 'F: obs-backfill/rebuild/evaluate CLI commands registered (distinct from legacy trend-backfill)');
$ok(strpos($src('includes/class-ves-cli-trend-backfill.php'),"add_command('ves trend-backfill'")!==false, 'F: legacy ves trend-backfill is preserved (no collision with the new command)');
$ok(strpos($cli,"add_command('ves trend-verify-staging'")!==false, 'H: trend-verify-staging checklist command registered');
$ok(strpos($cli,'$apply = !empty($assoc[\'apply\'])')!==false && strpos($cli,'DRY-RUN')!==false, 'F: backfill is dry-run by default, --apply required to write');
$ok(strpos($cli,'require_cap')!==false, 'F: CLI commands are capability-gated');

// Part G — admin diagnostics.
$ac=$src('includes/class-ves-admin-console.php');
$ok(strpos($ac,'Backfill readiness')!==false && strpos($ac,'preview_backfill(')!==false, 'G: admin shows backfill readiness');
$ok(strpos($ac,'Calibration thresholds')!==false && strpos($ac,'get_thresholds()')!==false, 'G: admin shows calibration thresholds');
$ok(strpos($ac,'Golden-set validation')!==false && strpos($ac,'VES_Trend_Golden_Set::evaluate()')!==false, 'G: admin shows golden-set validation');

// No LLM / no network / no secrets in the new classes.
foreach (['includes/class-ves-trend-backfill-service.php','includes/class-ves-trend-rebuild-service.php','includes/class-ves-trend-golden-set.php'] as $f) {
    $c=$src($f);
    $ok(stripos($c,'openai')===false && stripos($c,'wp_remote')===false && stripos($c,'api.apify')===false, "no LLM/network in {$f}");
    $ok(stripos($c,"'api_key'")===false || stripos($c,'FORBIDDEN')!==false, "no secret literals in {$f}");
}

echo "\nPhase 4B contract: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

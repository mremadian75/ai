<?php
/**
 * Phase 4B Parts D+E — VES_Trend_Scorer calibration thresholds + VES_Trend_Golden_Set.
 * Pure (no DB, no API, no LLM).
 *
 * Run: php tests/test-ves-trend-calibration.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
// Configurable filter shim: $GLOBALS['__f'][$tag] = callable.
function apply_filters($tag, $value) { if (isset($GLOBALS['__f'][$tag]) && is_callable($GLOBALS['__f'][$tag])) { return call_user_func($GLOBALS['__f'][$tag], $value); } return $value; }
$GLOBALS['__f'] = [];

require_once dirname(__DIR__).'/includes/class-ves-trend-series-builder.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-scorer.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-golden-set.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};
function series($vals){ $start=gmdate('Y-m-d', time()-(count($vals)-1)*86400); $pts=[]; $t=strtotime($start); foreach($vals as $i=>$v){ $pts[]=['date'=>gmdate('Y-m-d',$t+$i*86400),'value'=>(float)$v]; } return ['points'=>$pts,'summary'=>VES_Trend_Series_Builder::summarize_series($pts)]; }

// ── Default thresholds ───────────────────────────────────────────────────────
$th = VES_Trend_Scorer::get_thresholds();
$ok(($th['min_points']??0)===3 && ($th['spike_z']??0)==2.0 && isset($th['min_score_for_insight'],$th['min_confidence_for_insight']), 'D: get_thresholds returns defaults incl. insight gates');

// ── Filter override changes classification ──────────────────────────────────
$emerging = series([2,3,4,6,9,13]);
$ok(VES_Trend_Scorer::classify_series($emerging)==='emerging', 'baseline: 6-point rise is emerging');
$GLOBALS['__f']['ves_trend_scorer_thresholds'] = function($t){ $t['min_points']=10; return $t; };
$ok(VES_Trend_Scorer::classify_series($emerging)==='insufficient_data', 'D: filter raising min_points=10 makes it insufficient_data');
$ok((VES_Trend_Scorer::get_thresholds()['min_points']??0)===10, 'D: filter override reflected in get_thresholds');
$GLOBALS['__f'] = []; // clear

// ── Invalid override clamped / ignored ──────────────────────────────────────
$GLOBALS['__f']['ves_trend_scorer_thresholds'] = function($t){ $t['min_points']=1; $t['spike_z']='abc'; $t['peak_drop']=99; return $t; };
$thBad = VES_Trend_Scorer::get_thresholds();
$ok(($thBad['min_points']??0)===2, 'D: min_points below 2 is clamped to 2');
$ok(($thBad['spike_z']??0)==2.0, 'D: non-numeric override is ignored (spike_z keeps default)');
$ok(($thBad['peak_drop']??0)==1.0, 'D: out-of-range override is clamped (peak_drop max 1.0)');
$GLOBALS['__f'] = [];

// ── Per-call overrides do not mutate global defaults ────────────────────────
$r = VES_Trend_Scorer::score_series($emerging, ['emerge_growth' => 10.0]); // require +1000% growth → not emerging (series growth ≈5.5)
$ok($r['classification']!=='emerging', 'D: per-call override (emerge_growth=10.0) changes classification');
$ok((VES_Trend_Scorer::get_thresholds()['emerge_growth']??0)==0.10, 'D: per-call override does NOT mutate global defaults');

// ── Golden set ───────────────────────────────────────────────────────────────
$g = VES_Trend_Golden_Set::evaluate();
$ok($g['total']===7 && $g['passed']===7 && $g['failed']===0, 'E: all 7 default golden examples pass');
$ok(isset($g['pass_rate'],$g['failures']) && is_array($g['failures']), 'E: evaluate output structure correct');
$wrong = VES_Trend_Golden_Set::evaluate([['name'=>'mislabel','expected_classification'=>'fading','values'=>[2,3,4,6,9,13]]]);
$ok($wrong['failed']===1 && ($wrong['failures'][0]['actual']??'')==='emerging', 'E: a deliberately wrong expected class fails (actual=emerging)');
$ex = VES_Trend_Golden_Set::evaluate_example(['name'=>'x','expected_classification'=>'spike','values'=>[1,1,1,12,1,1]]);
$ok($ex['passed']===true && isset($ex['scores']['trend_score']), 'E: evaluate_example returns pass + scores');
$ok(count(VES_Trend_Golden_Set::default_examples())===7, 'E: 7 default examples (all classes)');

echo "\nVES trend calibration+golden tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

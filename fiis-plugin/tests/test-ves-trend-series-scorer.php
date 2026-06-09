<?php
/**
 * Phase 4 Parts C+D — VES_Trend_Series_Builder + VES_Trend_Scorer.
 * Pure deterministic math; no DB, no API calls.
 *
 * Run: php tests/test-ves-trend-series-scorer.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__).'/includes/class-ves-trend-series-builder.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-scorer.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};
$approx=function($a,$b,$e=0.01){return abs((float)$a-(float)$b)<$e;};

// Helper: observations with daily values starting at a base date.
function obs_days(array $vals, $start='2026-05-01'){ $o=[]; $t=strtotime($start); foreach($vals as $i=>$v){ $o[]=['observed_at'=>gmdate('Y-m-d H:i:s',$t+$i*86400),'value_number'=>(float)$v]; } return $o; }
// Series from raw daily values (recent dates so freshness is high).
function series($vals){ $start=gmdate('Y-m-d', time() - (count($vals)-1)*86400); $pts=VES_Trend_Series_Builder::bucket_observations(obs_days($vals,$start),'day'); return ['points'=>$pts,'summary'=>VES_Trend_Series_Builder::summarize_series($pts)]; }

// ── Series builder ───────────────────────────────────────────────────────────
$pts = VES_Trend_Series_Builder::bucket_observations(obs_days([5,10,15]), 'day');
$ok(count($pts)===3 && $pts[0]['value']==5 && $pts[2]['value']==15, 'day bucketing produces one point per day');

// Same-day observations sum into one bucket.
$same = [['observed_at'=>'2026-05-01 09:00:00','value_number'=>3],['observed_at'=>'2026-05-01 18:00:00','value_number'=>4]];
$ptsSame = VES_Trend_Series_Builder::bucket_observations($same,'day');
$ok(count($ptsSame)===1 && $ptsSame[0]['value']==7, 'same-day observations sum into one bucket');

// Week bucketing collapses 7 days into 1.
$ptsWeek = VES_Trend_Series_Builder::bucket_observations(obs_days([1,1,1,1,1,1,1],'2026-05-04'),'week'); // Mon..Sun
$ok(count($ptsWeek)===1 && $ptsWeek[0]['value']==7, 'week bucketing collapses a 7-day span');

// Month bucketing.
$ptsMonth = VES_Trend_Series_Builder::bucket_observations(obs_days([2,2],'2026-05-01'),'month');
$ok(count($ptsMonth)===1 && $ptsMonth[0]['value']==4 && $ptsMonth[0]['date']==='2026-05-01', 'month bucketing keys to first of month');

// Summary stats.
$sum = VES_Trend_Series_Builder::summarize_series([['date'=>'a','value'=>2],['date'=>'b','value'=>4],['date'=>'c','value'=>9]]);
$ok($sum['point_count']===3 && $approx($sum['total'],15) && $approx($sum['mean'],5) && $approx($sum['median'],4) && $approx($sum['min'],2) && $approx($sum['max'],9), 'summary: count/total/mean/median/min/max');
$ok($approx($sum['first'],2) && $approx($sum['last'],9) && $sum['slope']>0 && $sum['growth_rate']>0, 'summary: first/last/slope/growth');
$ok($sum['volatility']>=0 && $sum['stddev']>=0, 'summary: volatility/stddev non-negative');

// Empty + zero series.
$empty = VES_Trend_Series_Builder::summarize_series([]);
$ok($empty['point_count']===0 && $empty['mean']===0.0 && $empty['slope']===0.0, 'empty series summarized safely (no div-by-zero)');
$zero = VES_Trend_Series_Builder::summarize_series([['date'=>'a','value'=>0],['date'=>'b','value'=>0]]);
$ok($zero['total']===0.0 && $zero['volatility']===0.0, 'all-zero series: zero total + zero volatility (no div-by-zero)');

// ── Scorer: classifications ──────────────────────────────────────────────────
$ok(VES_Trend_Scorer::classify_series(series([2,3,4,6,9,13]))==='emerging', 'classify: emerging (clean rise)');
$ok(VES_Trend_Scorer::classify_series(series([20,21,20,21,20,21]))==='sustained', 'classify: sustained (steady)');
$ok(VES_Trend_Scorer::classify_series(series([2,5,9,12,11,8]))==='peaking', 'classify: peaking (rise then decline)');
$ok(VES_Trend_Scorer::classify_series(series([12,10,8,5,3,1]))==='fading', 'classify: fading (decline)');
$ok(VES_Trend_Scorer::classify_series(series([1,1,1,12,1,1]))==='spike', 'classify: spike (one bucket far above)');
$ok(VES_Trend_Scorer::classify_series(series([3,9,2,11,1,8]))==='noise', 'classify: noise (high volatility, no direction)');
$ok(VES_Trend_Scorer::classify_series(series([5]))==='insufficient_data', 'classify: insufficient_data (too few points)');
$ok(VES_Trend_Scorer::classify_series(series([0,0,0,0]))==='insufficient_data', 'classify: insufficient_data (all zero)');

// ── Scorer: bounds + structure + edge cases ─────────────────────────────────
$r = VES_Trend_Scorer::score_series(series([2,3,4,6,9,13]));
foreach (['trend_score','momentum_score','consistency_score','freshness_score','volatility_score','spike_score','confidence_score'] as $k) {
    $ok(isset($r[$k]) && $r[$k]>=0 && $r[$k]<=100, "score bound 0-100: {$k}");
}
$ok(is_array($r['reasons']) && !empty($r['reasons']) && isset($r['metadata']['point_count']), 'score_series returns reasons + metadata');

// detect_spike on the spike series.
$sp = VES_Trend_Scorer::detect_spike(series([1,1,1,12,1,1]));
$ok($sp['is_spike']===true && $sp['z_score']>=2.0 && $sp['spike_score']>0, 'detect_spike flags the outlier with z-score');
$noSp = VES_Trend_Scorer::detect_spike(series([5,5,5,5]));
$ok($noSp['is_spike']===false, 'detect_spike: flat series has no spike (no div-by-zero)');

// Momentum: rising > flat > falling.
$ok(VES_Trend_Scorer::calculate_momentum(series([1,2,4,8]))>60 && VES_Trend_Scorer::calculate_momentum(series([8,4,2,1]))<40, 'momentum: rising high, falling low');

// Empty series scores without error.
$re = VES_Trend_Scorer::score_series(['points'=>[],'summary'=>VES_Trend_Series_Builder::summarize_series([])]);
$ok($re['classification']==='insufficient_data' && $re['trend_score']>=0, 'empty series scores safely as insufficient_data');

echo "\nVES trend series+scorer tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

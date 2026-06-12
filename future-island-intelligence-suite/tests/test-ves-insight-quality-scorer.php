<?php
/**
 * Phase 5 Part B — VES_Insight_Quality_Scorer (deterministic, no DB/LLM).
 * Run: php tests/test-ves-insight-quality-scorer.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
function apply_filters($tag, $value) { if (isset($GLOBALS['__f'][$tag]) && is_callable($GLOBALS['__f'][$tag])) { return call_user_func($GLOBALS['__f'][$tag], $value); } return $value; }
$GLOBALS['__f'] = [];
require_once dirname(__DIR__).'/includes/class-ves-insight-quality-scorer.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

$now = strtotime('2026-06-07 00:00:00');
$recent = gmdate('Y-m-d H:i:s', $now - 3*86400);
$old    = gmdate('Y-m-d H:i:s', $now - 200*86400);

$strong_ctx = [
    'now' => $now,
    'evidence' => [
        ['id'=>1,'confidence_score'=>72,'text'=>'Customers repeatedly mention ai agents and automation in reviews and forums.','source_id'=>10,'observed_at'=>$recent],
        ['id'=>2,'confidence_score'=>68,'text'=>'Engagement on ai agents content rose across two platforms this month.','source_id'=>11,'observed_at'=>$recent],
    ],
    'sources' => [['provider'=>'google','observed_at'=>$recent],['provider'=>'reddit','observed_at'=>$recent]],
    'trend_record' => ['classification'=>'emerging','trend_score'=>75,'confidence_score'=>70],
];
$strong_insight = ['title'=>'Trend: ai agents (emerging)','recommendation'=>'Explore and test content around ai agents now with two low-cost experiments.','evidence_ids'=>[1,2]];

// 1) No evidence → low + not eligible.
$r0 = VES_Insight_Quality_Scorer::score_insight(['title'=>'Unsupported claim'], ['now'=>$now]);
$ok($r0['evidence_score']==0.0 && $r0['eligible_for_review']===false && $r0['eligible_for_approval']===false && in_array('no_evidence',$r0['reasons'],true), 'no-evidence insight scores 0 evidence + not review/approval eligible');

// 2) Evidence-backed → higher + review eligible.
$r1 = VES_Insight_Quality_Scorer::score_insight($strong_insight, $strong_ctx);
$ok($r1['evidence_score']>50 && $r1['quality_score']>$r0['quality_score'], 'evidence-backed insight scores higher than unsupported');
$ok($r1['eligible_for_review']===true, 'evidence-backed quality insight is review-eligible');

// 3) Stale evidence reduces freshness + warns.
$stale_ctx = $strong_ctx;
$stale_ctx['evidence'][0]['observed_at'] = $old; $stale_ctx['evidence'][1]['observed_at'] = $old; $stale_ctx['sources'][0]['observed_at']=$old; $stale_ctx['sources'][1]['observed_at']=$old;
$r2 = VES_Insight_Quality_Scorer::score_insight($strong_insight, $stale_ctx);
$ok($r2['freshness_score'] < $r1['freshness_score'] && in_array('stale_evidence',$r2['warnings'],true), 'stale evidence reduces freshness + warns');

// 4) No recommendation reduces actionability.
$no_rec = $strong_insight; unset($no_rec['recommendation']);
$r3 = VES_Insight_Quality_Scorer::score_insight($no_rec, $strong_ctx);
$ok($r3['actionability_score'] < $r1['actionability_score'] && in_array('no_recommendation',$r3['warnings'],true), 'missing recommendation reduces actionability + warns');

// 5) Threshold override (filter) flips review eligibility.
$GLOBALS['__f']['ves_insight_quality_thresholds'] = function($t){ $t['review_quality_min']=99; return $t; };
$r4 = VES_Insight_Quality_Scorer::score_insight($strong_insight, $strong_ctx);
$ok($r4['eligible_for_review']===false, 'raising review_quality_min via filter removes review eligibility');
$GLOBALS['__f']=[];
// invalid override ignored.
$GLOBALS['__f']['ves_insight_quality_thresholds'] = function($t){ $t['review_quality_min']='abc'; $t['min_evidence_for_approval']=99; return $t; };
$th = VES_Insight_Quality_Scorer::get_thresholds();
$ok($th['review_quality_min']==50.0, 'non-numeric threshold override ignored');
$GLOBALS['__f']=[];

// 6) Approval requires 2+ evidence: a single-evidence insight is not approval-eligible.
$one_ev = ['title'=>'Trend: x (emerging)','recommendation'=>'do something specific and concrete now','evidence_ids'=>[1]];
$r5 = VES_Insight_Quality_Scorer::score_insight($one_ev, ['now'=>$now,'evidence'=>[$strong_ctx['evidence'][0]],'sources'=>$strong_ctx['sources'],'trend_record'=>$strong_ctx['trend_record']]);
$ok($r5['eligible_for_approval']===false, 'single-evidence insight is not approval-eligible (needs 2+)');

// 7) Bounds.
$allbound=true;
foreach (['quality_score','evidence_score','source_score','freshness_score','specificity_score','actionability_score','confidence_score'] as $k) { if ($r1[$k]<0||$r1[$k]>100){$allbound=false;} }
$ok($allbound, 'all scores clamped 0-100');

echo "\nVES insight quality scorer tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

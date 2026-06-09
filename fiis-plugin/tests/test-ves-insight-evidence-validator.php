<?php
/**
 * Phase 5 Part C — VES_Insight_Evidence_Validator (deterministic, no DB/LLM).
 * Run: php tests/test-ves-insight-evidence-validator.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__).'/includes/class-ves-insight-evidence-validator.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

$now = strtotime('2026-06-07 00:00:00');
$recent = gmdate('Y-m-d H:i:s', $now - 2*86400);
$old    = gmdate('Y-m-d H:i:s', $now - 120*86400);

// ── validate_claim_support ──
$evid_match = [['id'=>1,'confidence_score'=>70,'text'=>'Buyers keep asking about ai agents in support tickets.','source_id'=>10,'observed_at'=>$recent]];
$r = VES_Insight_Evidence_Validator::validate_claim_support('ai agents', $evid_match, ['key_term'=>'ai agents','now'=>$now]);
$ok($r['supported']===true && in_array(1,$r['matched_evidence_ids'],true), 'term-matching, fresh, confident evidence supports the claim');

$evid_unrelated = [['id'=>2,'confidence_score'=>80,'text'=>'Tomorrow will be sunny with light wind.','source_id'=>99,'observed_at'=>$recent]];
$r2 = VES_Insight_Evidence_Validator::validate_claim_support('ai agents', $evid_unrelated, ['key_term'=>'ai agents','now'=>$now]);
$ok($r2['supported']===false && $r2['reason']==='no_matching_evidence', 'unrelated evidence does NOT support the claim');

// Link-based support (no term match, but linked to the same source).
$r3 = VES_Insight_Evidence_Validator::validate_claim_support('ai agents', $evid_unrelated, ['key_term'=>'ai agents','now'=>$now,'source_ids'=>[99]]);
$ok($r3['supported']===true, 'evidence linked to the same source supports the claim (linkage)');

// Weak confidence + stale are not usable.
$weak = [['id'=>3,'confidence_score'=>10,'text'=>'ai agents mentioned once.','observed_at'=>$recent]];
$ok(VES_Insight_Evidence_Validator::validate_claim_support('ai agents',$weak,['key_term'=>'ai agents','now'=>$now])['supported']===false, 'weak-confidence evidence does not establish support');
$stale = [['id'=>4,'confidence_score'=>80,'text'=>'ai agents are trending.','observed_at'=>$old]];
$ok(VES_Insight_Evidence_Validator::validate_claim_support('ai agents',$stale,['key_term'=>'ai agents','now'=>$now,'freshness_days'=>60])['supported']===false, 'stale evidence does not establish support');

// No evidence at all.
$ok(VES_Insight_Evidence_Validator::validate_claim_support('x', [], [])['reason']==='no_evidence', 'no evidence → reason no_evidence');

// ── Deep-debug GATE regressions (evidence-first loophole closes) ──
// G1: explicit zero-confidence with a bare term match must NOT support (was a loophole).
$zeroconf = [['id'=>5,'confidence_score'=>0,'text'=>'ai agents are everywhere.','observed_at'=>$recent]];
$ok(VES_Insight_Evidence_Validator::validate_claim_support('ai agents',$zeroconf,['key_term'=>'ai agents','now'=>$now])['supported']===false, 'GATE: zero-confidence term match does not establish support');
// G2: missing confidence_score with a bare term match must NOT support.
$noconf = [['id'=>6,'text'=>'ai agents are everywhere.','observed_at'=>$recent]];
$ok(VES_Insight_Evidence_Validator::validate_claim_support('ai agents',$noconf,['key_term'=>'ai agents','now'=>$now])['supported']===false, 'GATE: missing-confidence term match does not establish support');
// G3: an evidence row without a concrete id cannot back a supported claim, even if credible+term-matched.
$noid = [['confidence_score'=>90,'text'=>'ai agents are everywhere.','observed_at'=>$recent]];
$ok(VES_Insight_Evidence_Validator::validate_claim_support('ai agents',$noid,['key_term'=>'ai agents','now'=>$now])['supported']===false, 'GATE: evidence without an id cannot support a claim');
// G4: zero/missing-confidence rows are NOT "strong" → has_strong stays false.
$sumZero = VES_Insight_Evidence_Validator::summarize_evidence($zeroconf);
$ok($sumZero['has_strong']===false, 'GATE: zero-confidence evidence is not counted as strong');
$sumNo = VES_Insight_Evidence_Validator::summarize_evidence($noconf);
$ok($sumNo['has_strong']===false, 'GATE: missing-confidence evidence is not counted as strong');

// ── summarize_evidence (safe) ──
$sum = VES_Insight_Evidence_Validator::summarize_evidence($evid_match);
$ok($sum['count']===1 && $sum['avg_confidence']==70.0 && $sum['has_strong']===true, 'summarize_evidence returns counts/avg/strong');
$encoded = json_encode($sum);
$ok(strpos($encoded,'Buyers keep asking')===false, 'evidence summary contains NO raw evidence text');

// ── validate_insight ──
$insight = ['title'=>'Trend: ai agents (emerging)','status'=>'draft','evidence_ids'=>[1],'metadata'=>['normalized_term'=>'ai agents']];
$vi = VES_Insight_Evidence_Validator::validate_insight($insight, ['evidence'=>$evid_match,'now'=>$now]);
$ok($vi['has_evidence']===true && !empty($vi['supported_claims']) && $vi['minimum_requirements_met']===true, 'validate_insight: evidence-backed claim is supported + minimum met');

$insight0 = ['title'=>'Trend: ai agents (emerging)','status'=>'draft','evidence_ids'=>[]];
$vi0 = VES_Insight_Evidence_Validator::validate_insight($insight0, ['evidence'=>[],'now'=>$now]);
$ok($vi0['has_evidence']===false && in_array('no_evidence',$vi0['gaps'],true) && $vi0['minimum_requirements_met']===false, 'validate_insight: no evidence → gaps + minimum not met');

// get_evidence_gaps.
$gaps = VES_Insight_Evidence_Validator::get_evidence_gaps($insight, ['evidence'=>$stale,'now'=>$now]);
$ok(in_array('stale_evidence',$gaps,true) || in_array('evidence_missing_source_link',$gaps,true), 'get_evidence_gaps detects stale/missing-source gaps');

echo "\nVES insight evidence validator tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

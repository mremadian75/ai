<?php
/**
 * Phase 5 source contract — evidence-first insight engine wiring, DTF integration,
 * admin + CLI. No execution; inspects file contents.
 *
 * Run: php tests/test-phase5-contract.php
 */
$root = dirname(__DIR__);
$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};
$src=function($rel)use($root){$p=$root.'/'.$rel;return is_file($p)?(string)file_get_contents($p):'';};

// Classes exist + bootstrapped.
$map = [
    'VES_Insight_Quality_Scorer'      => 'includes/class-ves-insight-quality-scorer.php',
    'VES_Insight_Evidence_Validator'  => 'includes/class-ves-insight-evidence-validator.php',
    'VES_Insight_Lifecycle_Service'   => 'includes/class-ves-insight-lifecycle-service.php',
    'VES_Trend_Insight_Builder'       => 'includes/class-ves-trend-insight-builder.php',
    'VES_Insight_Brief_Builder'       => 'includes/class-ves-insight-brief-builder.php',
];
$boot=$src('future-island-intelligence-suite.php');
foreach ($map as $cls=>$file) {
    $ok(strpos($src($file),'class '.$cls)!==false, "{$cls} present");
    $ok(strpos($boot,basename($file))!==false, "{$cls} bootstrapped");
}

// Part B — quality scorer.
$qs=$src('includes/class-ves-insight-quality-scorer.php');
$ok(strpos($qs,'function score_insight(')!==false && strpos($qs,'eligible_for_review')!==false && strpos($qs,'eligible_for_approval')!==false, 'B: score_insight returns eligibility');
$ok(strpos($qs,'ves_insight_quality_thresholds')!==false && strpos($qs,'fidtf_insight_quality_thresholds')!==false, 'B: quality thresholds are filterable');

// Part C — evidence validator (no LLM/semantic).
$ev=$src('includes/class-ves-insight-evidence-validator.php');
$ok(strpos($ev,'function validate_insight(')!==false && strpos($ev,'function validate_claim_support(')!==false && strpos($ev,'function get_evidence_gaps(')!==false && strpos($ev,'function summarize_evidence(')!==false, 'C: evidence validator exposes required methods');

// Part D — lifecycle + hard store gate.
$lf=$src('includes/class-ves-insight-lifecycle-service.php');
$ok(strpos($lf,'function promote_to_reviewed(')!==false && strpos($lf,'function approve_insight(')!==false && strpos($lf,'function reject_insight(')!==false && strpos($lf,'function archive_insight(')!==false && strpos($lf,'function reassess_insight(')!==false, 'D: lifecycle exposes promote/approve/reject/archive/reassess');
$store=$src('includes/class-ves-intelligence-store.php');
$ok(strpos($store,'function update_insight_status(')!==false && strpos($store,"return new WP_Error('ves_intel_evidence_required', 'Cannot promote an insight without evidence beyond draft.')")!==false, 'D: store HARD GATE blocks reviewed/approved without evidence');

// Part E — trend insight builder.
$tib=$src('includes/class-ves-trend-insight-builder.php');
$ok(strpos($tib,'function create_draft_insight_from_trend(')!==false && strpos($tib,'function build_recommendation(')!==false && strpos($tib,'function build_from_trend_record(')!==false, 'E: trend insight builder exposes required methods');
$ok(strpos($tib,"WEAK_CLASSES = ['noise', 'insufficient_data']")!==false && strpos($tib,'find_insight_by_trend_record')!==false, 'E: weak classes excluded + dedup by trend_record_id');
$ok(strpos($tib,"'status'           => 'draft'")!==false, 'E: builder output is always draft');

// Part F — DTF integration.
$dtf=$src('modules/deep-trend-finder/includes/class-fidtf-report-service.php');
$ok(strpos($dtf,'VES_Trend_Insight_Builder::create_draft_insight_from_trend(')!==false, 'F: DTF pipeline uses the evidence-first builder');
$ok(preg_match('/function write_trend_intelligence\([^)]*\): void/',$dtf)===1, 'F: trend pipeline still returns void (report shape unchanged)');
$ok(substr_count($dtf,'catch (\\Throwable')>=2, 'F: trend pipeline remains isolated (try/catch)');

// Part G — brief builder.
$bb=$src('includes/class-ves-insight-brief-builder.php');
$ok(strpos($bb,'function create_brief_from_insight(')!==false && strpos($bb,'source_insight_id')!==false && strpos($bb,'find_brief_by_insight')!==false, 'G: brief builder requires insight + dedups by source_insight_id');

// Part H — admin diagnostics.
$ac=$src('includes/class-ves-admin-console.php');
$ok(strpos($ac,'function render_insight_quality(')!==false && strpos($ac,'render_insight_quality()')!==false, 'H: admin renders insight-quality panel');
$ok(strpos($ac,'quality_overview(')!==false, 'H: admin uses the quality_overview diagnostics provider');

// Part I — CLI.
$cli=$src('includes/class-ves-cli-trends.php');
$ok(strpos($cli,"add_command('ves insight-assess'")!==false && strpos($cli,"add_command('ves trend-insights'")!==false && strpos($cli,"add_command('ves insight-briefs'")!==false, 'I: insight-assess/trend-insights/insight-briefs CLI registered');
$ok(strpos($cli,'require_cap')!==false, 'I: insight CLI commands are capability-gated');

// No LLM / no network / no secrets in the new classes.
foreach (array_values($map) as $f) {
    $c=$src($f);
    // No LLM/network CALLS (the literal 'openai' may appear as a provider-credibility label).
    $ok(stripos($c,'api.openai.com')===false && stripos($c,'wp_remote')===false && stripos($c,'chat/completions')===false && stripos($c,'/v1/responses')===false, "no LLM/network calls in {$f}");
}
$ok(strpos($ac,'api_key')===false && strpos($ac,'bearer')===false, 'no secret fields in admin insight panel');

echo "\nPhase 5 contract: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

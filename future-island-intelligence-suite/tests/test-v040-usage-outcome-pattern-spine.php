<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
require_once __DIR__ . '/../includes/class-ves-decision-edge-store.php';
require_once __DIR__ . '/../includes/class-ves-decision-edge-service.php';
require_once __DIR__ . '/../includes/class-ves-pattern-observation-store.php';
require_once __DIR__ . '/../includes/class-ves-pattern-observation-service.php';
require_once __DIR__ . '/../includes/class-ves-outcome-store.php';
require_once __DIR__ . '/../includes/class-ves-outcome-service.php';

VES_Decision_Edge_Store::create_table(true);
VES_Pattern_Observation_Store::create_table(true);
VES_Outcome_Store::create_table(true);
fi_spine_ok(count($GLOBALS['fi_dbdelta_sql']) >= 3, 'outcome/pattern/edge migrations run');

$outcome_id = VES_Outcome_Service::record_outcome([
    'workspace_id' => 12,
    'run_id' => 99,
    'target_type' => 'draft',
    'target_id' => 77,
    'channel' => 'linkedin',
    'outcome_type' => 'client_feedback',
    'qualitative_note' => 'Client approved the proof-led direction.',
    'result_label' => 'approved',
    'confidence_score' => 0.7,
    'source_ref' => 'manual-note-1',
]);
fi_spine_ok(is_int($outcome_id) && $outcome_id > 0, 'manual outcome receipt records');
$target = VES_Outcome_Service::list_for_target(12, 'draft', 77);
fi_spine_ok(count($target) === 1 && $target[0]['result_label'] === 'approved', 'list_for_target returns outcome');
$run = VES_Outcome_Service::list_for_run(12, 99);
fi_spine_ok(count($run) === 1 && $run[0]['metrics'] === [], 'list_for_run returns decoded outcome');
$edges = VES_Decision_Edge_Service::list_edges_for_run(12, 99);
fi_spine_ok((bool) array_filter($edges, static fn($e) => $e['relation_type'] === 'produced_outcome'), 'outcome writes produced_outcome edge');
$patterns = VES_Pattern_Observation_Service::list_for_workspace(12);
fi_spine_ok(count($patterns) === 1 && $patterns[0]['source_refs'][0]['type'] === 'outcome', 'approved outcome creates low-confidence pattern observation');

$bad = VES_Outcome_Service::record_outcome(['workspace_id' => 12, 'target_type' => 'draft', 'target_id' => 77, 'outcome_type' => 'bad_type', 'qualitative_note' => 'x']);
fi_spine_ok(is_wp_error($bad), 'invalid outcome type rejected');
$bad_pattern = VES_Pattern_Observation_Service::record_pattern(['workspace_id' => 12, 'pattern_key' => 'empty_refs', 'trial_count' => 1]);
fi_spine_ok(is_wp_error($bad_pattern), 'pattern without source refs rejected');
$manual_pattern = VES_Pattern_Observation_Service::record_pattern([
    'workspace_id' => 12,
    'run_id' => 99,
    'pattern_key' => 'linkedin_risk_hook',
    'platform' => 'linkedin',
    'content_type' => 'post',
    'hook_type' => 'risk_framed',
    'message_angle' => 'decision_clarity',
    'success_count' => 3,
    'trial_count' => 5,
    'source_refs' => [['type' => 'review', 'id' => 4]],
]);
fi_spine_ok(is_int($manual_pattern) && $manual_pattern > 0, 'manual pattern observation records');

fi_spine_finish('v0.4.0 outcome and pattern spine checks');

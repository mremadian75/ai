<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
require_once __DIR__ . '/../includes/class-ves-review-decision-ledger.php';
require_once __DIR__ . '/../includes/class-ves-intelligence-store.php';
require_once __DIR__ . '/../includes/class-ves-decision-edge-store.php';
require_once __DIR__ . '/../includes/class-ves-decision-edge-service.php';
require_once __DIR__ . '/../includes/class-ves-brief-os-service.php';

VES_Review_Decision_Ledger::create_table(true);
$decision_id = VES_Review_Decision_Ledger::record([
    'workspace_id' => 5,
    'object_type' => 'draft',
    'object_id' => 44,
    'from_status' => 'generated',
    'to_status' => 'approved',
    'decision' => 'approve',
    'reason' => 'Strong hook and useful specificity.',
    'reason_code' => 'strong_hook',
]);
fi_spine_ok(is_int($decision_id) && $decision_id > 0, 'review decision with reason code records');
$recent = VES_Review_Decision_Ledger::recent(['workspace_id' => 5, 'reason_code' => 'strong_hook']);
fi_spine_ok(count($recent) === 1 && $recent[0]['reason_code'] === 'strong_hook', 'reason code appears in recent list');
$without_reason = VES_Review_Decision_Ledger::record([
    'workspace_id' => 5,
    'object_type' => 'draft',
    'object_id' => 45,
    'from_status' => 'generated',
    'to_status' => 'rejected',
    'decision' => 'reject',
]);
fi_spine_ok(is_int($without_reason) && $without_reason > 0, 'review decision without reason code still works');
$bad = VES_Review_Decision_Ledger::record([
    'workspace_id' => 5,
    'object_type' => 'draft',
    'object_id' => 46,
    'decision' => 'reject',
    'reason_code' => 'not_a_real_reason',
]);
fi_spine_ok(is_wp_error($bad), 'invalid reason code is rejected');
fi_spine_ok(VES_Review_Decision_Ledger::transition_allowed('memory_record', 'rejected', 'active') === false, 'blocked memory trust transition remains blocked');

fi_spine_reset_db();
VES_Intelligence_Store::create_tables(true);
VES_Decision_Edge_Store::create_table(true);
$brief_id = VES_Brief_OS_Service::create_manual_brief([
    'workspace_id' => 8,
    'run_id' => 22,
    'title' => 'Manual campaign brief',
    'objective' => 'Create a proof-led campaign direction from operator context.',
    'channel' => 'linkedin',
    'format' => 'campaign_angle',
    'target_audience' => 'Agency operators',
    'key_points' => ['Lead with evidence', 'Avoid generic AI claims'],
    'constraints' => ['Label assumptions'],
]);
fi_spine_ok(is_int($brief_id) && $brief_id > 0, 'creation-led manual brief records');
$brief = VES_Intelligence_Store::get_brief($brief_id);
fi_spine_ok(is_array($brief) && $brief['run_id'] === 22 && $brief['origin_type'] === 'manual', 'brief carries run and origin metadata');
fi_spine_ok($brief['decision_context']['manual_assumption_based'] === true, 'manual brief without evidence is assumption-labeled');
fi_spine_ok($brief['key_points'][0] === 'Lead with evidence' && $brief['constraints'][0] === 'Label assumptions', 'brief OS JSON metadata round trips');
$edges = VES_Decision_Edge_Service::list_edges_for_run(8, 22);
fi_spine_ok(count($edges) === 1 && $edges[0]['to_type'] === 'brief', 'manual brief writes run generated brief edge');
$missing = VES_Brief_OS_Service::create_manual_brief(['workspace_id' => 8, 'title' => 'No objective']);
fi_spine_ok(is_wp_error($missing), 'manual brief still validates required objective');

fi_spine_finish('v0.4.0 review reason and brief OS checks');

<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return !empty($GLOBALS['logged']); } }
if (!function_exists('current_user_can')) { function current_user_can($cap){ return !empty($GLOBALS['admin']) && $cap === 'manage_options'; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value){ return $value; } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 2000; public static function record($args){ return ++self::$n; } } }

require_once dirname(__DIR__) . '/includes/class-ves-workspace-membership-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-workspace-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-permission-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-run-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-run-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-edge-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-edge-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-review-decision-ledger.php';
require_once dirname(__DIR__) . '/includes/class-ves-intelligence-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-usage-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-brief-os-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-draft-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-review-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-outcome-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-outcome-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-pattern-observation-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-pattern-observation-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-command-room-action-service.php';

$GLOBALS['logged'] = true; $GLOBALS['admin'] = false;
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true); VES_Outcome_Store::create_table(true); VES_Pattern_Observation_Store::create_table(true);
$ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'Action Workspace', 'created_by_user_id' => 42]);
VES_Workspace_Membership_Store::add_member($ws, 42, 'owner');
$run = VES_Run_Service::create_run(['workspace_id' => $ws, 'run_type' => 'research', 'trigger_type' => 'manual', 'idempotency_key' => 'actions-run']);
VES_Run_Service::mark_running($run);
$src = VES_Intelligence_Store::create_source(['workspace_id' => $ws, 'run_id' => $run, 'source_type' => 'manual', 'provider' => 'manual', 'title' => 'Manual source']);
$sig = VES_Intelligence_Store::create_signal(['workspace_id' => $ws, 'run_id' => $run, 'source_id' => $src, 'signal_type' => 'market_signal', 'title' => 'Proof beats generic claims', 'summary' => 'Audience asks for evidence.', 'confidence_score' => 80]);
$ev = VES_Intelligence_Store::create_evidence(['workspace_id' => $ws, 'run_id' => $run, 'source_id' => $src, 'signal_id' => $sig, 'evidence_type' => 'observation', 'text' => 'Manual observation with source ref.', 'confidence_score' => 70]);
$ins = VES_Intelligence_Store::create_insight(['workspace_id' => $ws, 'run_id' => $run, 'insight_type' => 'opportunity', 'title' => 'Lead with evidence', 'summary' => 'Proof-first framing is more useful.', 'evidence_ids' => [$ev], 'signal_ids' => [$sig], 'status' => 'draft']);

fi_spine_ok(VES_Command_Room_Action_Service::ACTION === 'fi_command_room_action', 'admin-post action handler constant exists');
$GLOBALS['logged'] = false;
$blocked = VES_Command_Room_Action_Service::perform(['command_action' => 'approve_insight', 'target_id' => $ins, 'reason_code' => 'good_angle']);
fi_spine_ok(is_wp_error($blocked), 'permission failure blocked');
$GLOBALS['logged'] = true;
$approve = VES_Command_Room_Action_Service::perform(['command_action' => 'approve_insight', 'target_id' => $ins, 'reason_code' => 'good_angle', 'notes' => '<b>Specific</b> enough']);
fi_spine_ok(is_array($approve) && ($approve['result']['status'] ?? '') === 'approved', 'valid approve insight works');
$briefBlocked = VES_Command_Room_Action_Service::perform(['command_action' => 'create_brief_from_insight', 'target_id' => $ins + 9999]);
fi_spine_ok(is_wp_error($briefBlocked), 'missing/wrong target blocked');
$brief = VES_Command_Room_Action_Service::perform(['command_action' => 'create_brief_from_insight', 'target_id' => $ins, 'channel' => 'linkedin', 'format' => 'social_post']);
$brief_id = is_array($brief) ? (int) ($brief['result'] ?? 0) : 0;
fi_spine_ok($brief_id > 0, 'create brief from approved insight works');
$ready = VES_Command_Room_Action_Service::perform(['command_action' => 'mark_brief_ready', 'target_id' => $brief_id, 'reason_code' => 'good_angle']);
fi_spine_ok(is_array($ready) && (($ready['result']['status'] ?? '') === 'ready'), 'mark brief ready works');
$draft = VES_Command_Room_Action_Service::perform(['command_action' => 'generate_draft_from_brief', 'target_id' => $brief_id, 'draft_mode' => 'deterministic_preview']);
$draft_id = is_array($draft) ? (int) ($draft['result'] ?? 0) : 0;
fi_spine_ok($draft_id > 0, 'generate draft from brief works');
$primary = VES_Command_Room_Action_Service::perform(['command_action' => 'mark_draft_primary', 'target_id' => $draft_id]);
fi_spine_ok(is_array($primary) && !empty($primary['result']['metadata']['is_primary']), 'mark draft primary works');
$reviseDraft = VES_Command_Room_Action_Service::perform(['command_action' => 'request_draft_revision', 'target_id' => $draft_id, 'reason_code' => 'needs_specificity']);
fi_spine_ok(is_array($reviseDraft) && (($reviseDraft['result']['status'] ?? '') === 'edited'), 'request draft revision works');
$memory = VES_Command_Room_Action_Service::perform(['command_action' => 'save_memory_candidate', 'target_id' => $draft_id, 'memory_text' => 'Use proof-first framing for agency operators.']);
$memory_id = is_array($memory) ? (int) ($memory['result'] ?? 0) : 0;
fi_spine_ok($memory_id > 0, 'save memory candidate works');
$activated = VES_Command_Room_Action_Service::perform(['command_action' => 'activate_memory', 'target_id' => $memory_id, 'reason_code' => 'useful_pattern']);
fi_spine_ok(is_array($activated) && (($activated['result']['status'] ?? '') === 'active'), 'activate memory candidate works');
$pinned = VES_Command_Room_Action_Service::perform(['command_action' => 'pin_memory', 'target_id' => $memory_id]);
fi_spine_ok(is_array($pinned) && (($pinned['result']['status'] ?? '') === 'pinned'), 'pin memory works');
$expired = VES_Command_Room_Action_Service::perform(['command_action' => 'mark_memory_stale', 'target_id' => $memory_id, 'reason_code' => 'stale_memory']);
fi_spine_ok(is_array($expired) && (($expired['result']['status'] ?? '') === 'expired'), 'mark memory stale/expired works');
$outcome = VES_Command_Room_Action_Service::perform(['command_action' => 'record_outcome', 'target_type' => 'draft', 'target_id' => $draft_id, 'channel' => 'linkedin', 'outcome_type' => 'client_feedback', 'result_label' => 'won', 'qualitative_note' => 'Client approved the proof-first direction.', 'metric_name' => 'approval_score', 'metric_value' => '1']);
$outcome_id = is_array($outcome) ? (int) ($outcome['result'] ?? 0) : 0;
fi_spine_ok($outcome_id > 0, 'outcome creation works');
$edges = VES_Decision_Edge_Service::list_edges_for_target($ws, 'draft', $draft_id);
fi_spine_ok(count($edges) > 0, 'decision edge created for successful actions');
$patterns = VES_Pattern_Observation_Service::list_for_workspace($ws, ['limit' => 20]);
fi_spine_ok(count($patterns) > 0 && (float) ($patterns[0]['confidence_score'] ?? 1) <= 0.2, 'review/outcome pattern low confidence label created');

$other = VES_Intelligence_Store::create_insight(['workspace_id' => $ws + 999, 'run_id' => $run, 'insight_type' => 'opportunity', 'title' => 'Other workspace', 'summary' => 'Wrong workspace', 'status' => 'draft']);
$wrong = VES_Command_Room_Action_Service::perform(['command_action' => 'approve_insight', 'target_id' => $other]);
fi_spine_ok(is_wp_error($wrong), 'wrong workspace blocked');
$invalid = VES_Command_Room_Action_Service::perform(['command_action' => 'approve_insight', 'target_id' => $ins, 'reason_code' => 'not_a_code']);
fi_spine_ok(is_wp_error($invalid), 'invalid transition/reason blocked');
$recent = VES_Review_Decision_Ledger::recent(['workspace_id' => $ws, 'object_type' => 'insight', 'limit' => 20]);
$bad = false; foreach ($recent as $r) { if (($r['reason_code'] ?? '') === 'not_a_code') { $bad = true; } }
fi_spine_ok(!$bad, 'no successful review decision created for blocked reason');

fi_spine_finish('v0.6.0 Command Room action checks');

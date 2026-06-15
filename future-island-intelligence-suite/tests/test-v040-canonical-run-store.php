<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
require_once __DIR__ . '/../includes/class-ves-run-store.php';
require_once __DIR__ . '/../includes/class-ves-run-service.php';

VES_Run_Store::create_table(true);
fi_spine_ok(!empty($GLOBALS['fi_dbdelta_sql'][0]) && strpos($GLOBALS['fi_dbdelta_sql'][0], 'ves_intel_runs') !== false, 'migration creates ves_intel_runs table');

$run_id = VES_Run_Service::create_run([
    'workspace_id' => 4,
    'source_id' => 9,
    'run_type' => 'research',
    'trigger_type' => 'manual',
    'input_context' => ['query' => 'signal'],
    'idempotency_key' => 'idem-1',
    'metadata' => ['note' => 'ok', 'api_key' => 'secret'],
]);
fi_spine_ok(is_int($run_id) && $run_id > 0, 'create_run returns id');
$run = VES_Run_Service::get_run($run_id);
fi_spine_ok(is_array($run) && $run['workspace_id'] === 4 && $run['source_id'] === 9, 'get_run returns workspace/source');
fi_spine_ok($run['input_context']['query'] === 'signal', 'input_context JSON round trip');
fi_spine_ok(!isset($run['metadata']['api_key']), 'forbidden metadata is scrubbed');
fi_spine_ok(VES_Run_Service::find_by_idempotency_key(4, 'idem-1')['id'] === $run_id, 'idempotency lookup works');
fi_spine_ok(VES_Run_Service::create_run(['workspace_id' => 4, 'idempotency_key' => 'idem-1']) === $run_id, 'duplicate idempotency key reuses run');
fi_spine_ok(VES_Run_Service::mark_running($run_id) === $run_id, 'queued -> running transition works');
fi_spine_ok(VES_Run_Service::mark_completed($run_id, ['signals' => 3]) === $run_id, 'running -> completed transition works');
$run = VES_Run_Service::get_run($run_id);
fi_spine_ok($run['status'] === 'completed' && $run['result_summary']['signals'] === 3, 'completed summary persisted');
fi_spine_ok(is_wp_error(VES_Run_Service::mark_running($run_id)), 'terminal run cannot reopen');
fi_spine_ok(VES_Run_Service::assert_workspace($run_id, 4) === true, 'workspace assertion succeeds');
fi_spine_ok(is_wp_error(VES_Run_Service::assert_workspace($run_id, 5)), 'workspace assertion blocks mismatch');
$list = VES_Run_Service::list_runs_for_workspace(4, ['limit' => 5]);
fi_spine_ok(count($list) === 1 && $list[0]['id'] === $run_id, 'list_runs_for_workspace scopes by workspace');

$retry_id = VES_Run_Service::create_run(['workspace_id' => 4, 'parent_run_id' => $run_id, 'trigger_type' => 'retry', 'idempotency_key' => 'retry-1']);
fi_spine_ok($retry_id !== $run_id && VES_Run_Service::get_run($retry_id)['parent_run_id'] === $run_id, 'retry creates new child run');

fi_spine_finish('v0.4.0 canonical run store checks');

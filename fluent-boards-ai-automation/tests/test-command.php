<?php
/**
 * AI Command Console: intent validation/sanitization and the read-now / mutate-confirm
 * execution gate. The AI interpret() step needs HTTP and is covered by integration, not here.
 * Run via tests/run.php.
 */

require_once dirname(__DIR__) . '/includes/class-fbaia-command.php';
require_once dirname(__DIR__) . '/includes/class-fbaia-fluentboards-adapter.php';
require_once dirname(__DIR__) . '/includes/class-fbaia-runner.php';

echo "AI command console\n";

// --- sanitize_intent(): whitelist + per-intent param sanitization ---
$i = FBAIA_Command::sanitize_intent(['intent' => 'set_priority', 'params' => ['task_id' => '42', 'priority' => 'HIGH']]);
fbaia_assert('set_priority sanitized', $i['intent'] === 'set_priority' && $i['params']['task_id'] === 42 && $i['params']['priority'] === 'high');
fbaia_assert('set_priority is mutate', $i['type'] === 'mutate');

$bad = FBAIA_Command::sanitize_intent(['intent' => 'rm_rf', 'params' => []]);
fbaia_assert('unknown intent falls back to help', $bad['intent'] === 'help' && $bad['type'] === 'read');

$noid = FBAIA_Command::sanitize_intent(['intent' => 'analyze_task', 'params' => []]);
fbaia_assert('analyze_task without id -> help', $noid['intent'] === 'help');

$badprio = FBAIA_Command::sanitize_intent(['intent' => 'set_priority', 'params' => ['task_id' => 5, 'priority' => 'banana']]);
fbaia_assert('invalid priority -> help', $badprio['intent'] === 'help');

$find = FBAIA_Command::sanitize_intent(['intent' => 'find_tasks', 'params' => ['filter' => 'overdue', 'days' => '900', 'board_id' => '12']]);
fbaia_assert('find_tasks filter kept', $find['params']['filter'] === 'overdue');
fbaia_assert('find_tasks days clamped', $find['params']['days'] === 365);
fbaia_assert('find_tasks board_id int', $find['params']['board_id'] === 12);

$subs = FBAIA_Command::sanitize_intent(['intent' => 'create_subtasks', 'params' => ['task_id' => 8, 'titles' => ['Design', '', 'Build', 123]]]);
fbaia_assert('create_subtasks keeps non-empty titles', $subs['params']['titles'] === ['Design', 'Build', '123']);

// --- Fake adapter for execution ---
class FBAIA_Fake_Adapter_Cmd
{
    public $priorityCalls = 0;
    public $task = ['id' => 42, 'board_id' => 12, 'title' => 'T', 'priority' => 'low'];
    public function get_task($id) { return ($id === 42) ? (object) $this->task : null; }
    public function task_to_array($t) { return is_object($t) ? (array) $t : (array) $t; }
    public function update_task_priority($id, $p) { $this->priorityCalls++; return ['updated' => true]; }
    public function create_subtask($id, $t) { return (object) ['id' => 1]; }
    public function get_due_tasks($b, $l = 25, $a = '') { return [['id' => 1, 'title' => 'Overdue A', 'priority' => 'high', 'due_at' => '2020-01-01']]; }
    public function get_stale_tasks($b, $l = 50, $a = '') { return []; }
    public function get_tasks_due_within($f, $t, $l = 50, $a = '') { return []; }
    public function get_recent_tasks($s, $l = 25, $a = '') { return []; }
    public function is_available() { return true; }
}

// --- Mutate: preview first, no side effects ---
$fa = new FBAIA_Fake_Adapter_Cmd();
$intent = FBAIA_Command::sanitize_intent(['intent' => 'set_priority', 'params' => ['task_id' => 42, 'priority' => 'high']]);
$preview = FBAIA_Command::execute($intent, false, $fa);
fbaia_assert('mutate without confirm -> needs_confirm', !empty($preview['needs_confirm']));
fbaia_assert('preview does not mutate', $fa->priorityCalls === 0);
fbaia_assert('preview carries intent + params', $preview['intent'] === 'set_priority' && $preview['params']['priority'] === 'high');

// --- Mutate: confirm -> executes ---
$done = FBAIA_Command::execute($intent, true, $fa);
fbaia_assert('confirm executes mutation', $fa->priorityCalls === 1 && !empty($done['ok']) && empty($done['needs_confirm']));

// --- Read: find_tasks runs immediately ---
$fr = FBAIA_Command::execute(FBAIA_Command::sanitize_intent(['intent' => 'find_tasks', 'params' => ['filter' => 'overdue']]), false, $fa);
fbaia_assert('find_tasks runs without confirm', empty($fr['needs_confirm']) && !empty($fr['ok']));
fbaia_assert('find_tasks returns rows', isset($fr['data']['tasks']) && count($fr['data']['tasks']) === 1);

// --- help ---
$h = FBAIA_Command::execute(FBAIA_Command::sanitize_intent(['intent' => 'help']), false, $fa);
fbaia_assert('help is ok + no confirm', !empty($h['ok']) && empty($h['needs_confirm']));

// --- mutate on a missing task fails cleanly on confirm ---
$missing = FBAIA_Command::sanitize_intent(['intent' => 'set_priority', 'params' => ['task_id' => 999, 'priority' => 'high']]);
$mres = FBAIA_Command::execute($missing, true, $fa);
fbaia_assert('confirm on missing task -> not ok', empty($mres['ok']));
fbaia_assert('missing task did not mutate', $fa->priorityCalls === 1);

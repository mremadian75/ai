<?php
/**
 * Held recipe-action review queue: record, approve (applies the mutation), reject.
 * Run via tests/run.php.
 */

require_once dirname(__DIR__) . '/includes/class-fbaia-recipes.php';
require_once dirname(__DIR__) . '/includes/class-fbaia-recipe-queue.php';
require_once dirname(__DIR__) . '/includes/class-fbaia-fluentboards-adapter.php';

echo "Held recipe-action queue\n";

fbaia_reset_options();

// Recording a held action.
$id = FBAIA_Recipe_Queue::record('Bump urgent', ['type' => 'set_priority', 'value' => 'high'], 7, 12);
fbaia_assert('record returns an id', is_string($id) && $id !== '');
$pending = FBAIA_Recipe_Queue::all('pending');
fbaia_assert('one pending action', count($pending) === 1);
fbaia_assert('pending action shape', $pending[0]['action_type'] === 'set_priority' && $pending[0]['task_id'] === 7);

$stats = FBAIA_Recipe_Queue::stats();
fbaia_assert('stats pending = 1', $stats['pending'] === 1);

// Approve -> applies the mutation via a fake adapter and marks applied.
class FBAIA_Fake_Adapter_Queue
{
    public $priorityCalls = 0;
    public function update_task_priority($id, $p) { $this->priorityCalls++; return ['updated' => true]; }
    public function create_subtask($id, $t) { return (object) ['id' => 1]; }
    public function create_ai_comment($t, $b, $c, $p = 'private') { return (object) ['id' => 1]; }
    public function is_available() { return true; }
}
$fa = new FBAIA_Fake_Adapter_Queue();
$result = FBAIA_Recipe_Queue::approve($id, $fa);
fbaia_assert('approve returns applied', is_array($result) && !empty($result['applied']));
fbaia_assert('approve actually applied the mutation', $fa->priorityCalls === 1);
fbaia_assert('approved item no longer pending', count(FBAIA_Recipe_Queue::all('pending')) === 0);
fbaia_assert('approved item is in applied', count(FBAIA_Recipe_Queue::all('applied')) === 1);

// Approving an already-resolved action is a no-op (not re-applied).
$again = FBAIA_Recipe_Queue::approve($id, $fa);
fbaia_assert('re-approve does not re-apply', $fa->priorityCalls === 1);

// Reject flow.
$id2 = FBAIA_Recipe_Queue::record('Add note', ['type' => 'add_comment', 'message' => 'hi'], 9, 12);
$rej = FBAIA_Recipe_Queue::reject($id2);
fbaia_assert('reject returns rejected', is_array($rej) && !empty($rej['rejected']));
fbaia_assert('rejected item recorded', count(FBAIA_Recipe_Queue::all('rejected')) === 1);

// Unknown id -> WP_Error.
fbaia_assert('approve unknown id -> WP_Error', is_wp_error(FBAIA_Recipe_Queue::approve('nope')));
fbaia_assert('reject unknown id -> WP_Error', is_wp_error(FBAIA_Recipe_Queue::reject('nope')));

// End-to-end: a held run() action lands in the queue, then approves and applies.
fbaia_reset_options();
update_option(FBAIA_Recipes::OPTION, FBAIA_Recipes::parse(json_encode([[
    'name' => 'Hold then approve', 'enabled' => true, 'trigger' => 'task_priority_changed',
    'conditions' => [['field' => 'priority', 'op' => 'equals', 'value' => 'urgent']],
    'actions' => [['type' => 'set_priority', 'value' => 'high']],
    'run_mode' => 'review',
]])), false);
$s = FBAIA_Helpers::defaults(); $s['recipes_enabled'] = 'yes'; $s['recipe_allow_mutations'] = 'no';
update_option(FBAIA_OPTION, $s, false);
$fa2 = new FBAIA_Fake_Adapter_Queue();
FBAIA_Recipes::run('task_priority_changed', ['task' => ['priority' => 'urgent', 'id' => 21, 'board_id' => 5]], $fa2);
$q = FBAIA_Recipe_Queue::all('pending');
fbaia_assert('held run() action recorded to queue', count($q) === 1 && $q[0]['task_id'] === 21);
fbaia_assert('held action not applied yet', $fa2->priorityCalls === 0);
FBAIA_Recipe_Queue::approve($q[0]['id'], $fa2);
fbaia_assert('approving the held action applies it', $fa2->priorityCalls === 1);

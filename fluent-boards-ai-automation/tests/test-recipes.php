<?php
/**
 * Automation Recipes engine: parsing, condition evaluation, field resolution, and the
 * safe-by-default execution tiering. Run via tests/run.php.
 */

require_once dirname(__DIR__) . '/includes/class-fbaia-recipes.php';
require_once dirname(__DIR__) . '/includes/class-fbaia-fluentboards-adapter.php';

echo "Automation recipes\n";

// --- compare() operators ---
fbaia_assert('equals (case-insensitive)', FBAIA_Recipes::compare('Urgent', 'equals', 'urgent') === true);
fbaia_assert('not_equals', FBAIA_Recipes::compare('high', 'not_equals', 'urgent') === true);
fbaia_assert('contains', FBAIA_Recipes::compare('please review ASAP', 'contains', 'review') === true);
fbaia_assert('not_contains', FBAIA_Recipes::compare('all good', 'not_contains', 'bug') === true);
fbaia_assert('is_empty', FBAIA_Recipes::compare('', 'is_empty', '') === true);
fbaia_assert('is_not_empty', FBAIA_Recipes::compare('x', 'is_not_empty', '') === true);
fbaia_assert('gt numeric', FBAIA_Recipes::compare('5', 'gt', '3') === true);
fbaia_assert('lt numeric', FBAIA_Recipes::compare('2', 'lt', '3') === true);
fbaia_assert('gt non-numeric is false', FBAIA_Recipes::compare('abc', 'gt', '3') === false);
fbaia_assert('in list', FBAIA_Recipes::compare('high', 'in', 'urgent, high, medium') === true);
fbaia_assert('unknown op is false', FBAIA_Recipes::compare('x', 'bogus', 'x') === false);

// --- resolve_field() ---
$payload = ['task' => ['priority' => 'urgent', 'status' => 'open', 'due_at' => '', 'board_id' => 12, 'id' => 7, 'title' => 'Fix checkout']];
fbaia_assert('resolve priority', FBAIA_Recipes::resolve_field($payload, 'priority') === 'urgent');
fbaia_assert('resolve due_at empty', FBAIA_Recipes::resolve_field($payload, 'due_at') === '');
fbaia_assert('resolve board_id', (int) FBAIA_Recipes::resolve_field($payload, 'board_id') === 12);
fbaia_assert('resolve title', FBAIA_Recipes::resolve_field($payload, 'title') === 'Fix checkout');
fbaia_assert('resolve event', FBAIA_Recipes::resolve_field($payload, 'event', 'task_created') === 'task_created');

// --- parse() JSON ---
$json = json_encode([[
    'name' => 'R1', 'enabled' => true, 'trigger' => 'task_priority_changed',
    'conditions' => [['field' => 'priority', 'op' => 'equals', 'value' => 'urgent']],
    'actions' => [['type' => 'notify', 'message' => 'hi']],
    'run_mode' => 'review',
]]);
$recipes = FBAIA_Recipes::parse($json);
fbaia_assert('parse JSON returns one recipe', count($recipes) === 1);
fbaia_assert('parsed recipe trigger', $recipes[0]['trigger'] === 'task_priority_changed');
fbaia_assert('parsed recipe enabled bool', $recipes[0]['enabled'] === true);

// Recipe with an invalid trigger is dropped.
fbaia_assert('invalid trigger dropped', count(FBAIA_Recipes::parse(json_encode([['trigger' => 'nope', 'actions' => [['type' => 'notify']]]]))) === 0);
// Recipe with no valid actions is dropped.
fbaia_assert('no actions dropped', count(FBAIA_Recipes::parse(json_encode([['trigger' => 'task_created', 'actions' => []]]))) === 0);

// --- parse() block format ---
$block = "name: Block recipe\nenabled: yes\ntrigger: task_created\nwhen: due_at is_empty\nthen: notify admin@example.com\nrun_mode: review";
$bp = FBAIA_Recipes::parse($block);
fbaia_assert('parse block returns recipe', count($bp) === 1);
fbaia_assert('block condition parsed', $bp[0]['conditions'][0]['field'] === 'due_at' && $bp[0]['conditions'][0]['op'] === 'is_empty');
fbaia_assert('block action parsed', $bp[0]['actions'][0]['type'] === 'notify');

// --- conditions_pass match all / any ---
$recipeAll = ['match' => 'all', 'conditions' => [
    ['field' => 'priority', 'op' => 'equals', 'value' => 'urgent'],
    ['field' => 'status', 'op' => 'equals', 'value' => 'open'],
]];
fbaia_assert('match all true when both pass', FBAIA_Recipes::conditions_pass($recipeAll, $payload, 'x') === true);
$recipeAll2 = ['match' => 'all', 'conditions' => [
    ['field' => 'priority', 'op' => 'equals', 'value' => 'low'],
]];
fbaia_assert('match all false when one fails', FBAIA_Recipes::conditions_pass($recipeAll2, $payload, 'x') === false);
$recipeAny = ['match' => 'any', 'conditions' => [
    ['field' => 'priority', 'op' => 'equals', 'value' => 'low'],
    ['field' => 'status', 'op' => 'equals', 'value' => 'open'],
]];
fbaia_assert('match any true when one passes', FBAIA_Recipes::conditions_pass($recipeAny, $payload, 'x') === true);
fbaia_assert('no conditions always passes', FBAIA_Recipes::conditions_pass(['conditions' => []], $payload, 'x') === true);

// --- has_enabled_for_event ---
fbaia_reset_options();
update_option(FBAIA_Recipes::OPTION, FBAIA_Recipes::parse($json), false);
fbaia_assert('has_enabled_for_event matches', FBAIA_Recipes::has_enabled_for_event('task_priority_changed') === true);
fbaia_assert('has_enabled_for_event non-match', FBAIA_Recipes::has_enabled_for_event('task_created') === false);

// --- run(): safe-by-default execution tiering ---
// Fake adapter that records mutation calls.
class FBAIA_Fake_Adapter_Recipes
{
    public $priorityCalls = 0;
    public function update_task_priority($id, $p) { $this->priorityCalls++; return ['updated' => true]; }
    public function create_subtask($id, $t) { return (object) ['id' => 1]; }
    public function create_ai_comment($t, $b, $c, $p = 'private') { return (object) ['id' => 1]; }
    public function is_available() { return true; }
}

function fbaia_seed_recipe($run_mode)
{
    fbaia_reset_options();
    $r = [[
        'name' => 'Bump urgent', 'enabled' => true, 'trigger' => 'task_priority_changed',
        'conditions' => [['field' => 'priority', 'op' => 'equals', 'value' => 'urgent']],
        'actions' => [['type' => 'notify', 'to' => 'a@b.com', 'message' => 'm'], ['type' => 'set_priority', 'value' => 'high']],
        'run_mode' => $run_mode,
    ]];
    update_option(FBAIA_Recipes::OPTION, FBAIA_Recipes::parse(json_encode($r)), false);
}

$pl = ['task' => ['priority' => 'urgent', 'id' => 7, 'board_id' => 12]];

// recipes disabled globally -> nothing runs.
fbaia_seed_recipe('auto');
$s = FBAIA_Helpers::defaults(); $s['recipes_enabled'] = 'no'; $s['recipe_allow_mutations'] = 'yes';
update_option(FBAIA_OPTION, $s, false);
$fa = new FBAIA_Fake_Adapter_Recipes();
$res = FBAIA_Recipes::run('task_priority_changed', $pl, $fa);
fbaia_assert('recipes disabled -> no execution', $res === []);

// enabled, but mutations not allowed -> notify runs, set_priority HELD.
fbaia_seed_recipe('auto');
$s = FBAIA_Helpers::defaults(); $s['recipes_enabled'] = 'yes'; $s['recipe_allow_mutations'] = 'no';
update_option(FBAIA_OPTION, $s, false);
$fa = new FBAIA_Fake_Adapter_Recipes();
$res = FBAIA_Recipes::run('task_priority_changed', $pl, $fa);
$statuses = array_map(function ($r) { return $r['action'] . ':' . $r['status']; }, $res);
fbaia_assert('notify runs when mutations off', in_array('notify:sent', $statuses, true));
fbaia_assert('set_priority HELD when mutations off', in_array('set_priority:held', $statuses, true));
fbaia_assert('no priority mutation occurred', $fa->priorityCalls === 0);

// enabled + mutations allowed + run_mode auto -> set_priority APPLIED.
fbaia_seed_recipe('auto');
$s = FBAIA_Helpers::defaults(); $s['recipes_enabled'] = 'yes'; $s['recipe_allow_mutations'] = 'yes';
update_option(FBAIA_OPTION, $s, false);
$fa = new FBAIA_Fake_Adapter_Recipes();
$res = FBAIA_Recipes::run('task_priority_changed', $pl, $fa);
$statuses = array_map(function ($r) { return $r['action'] . ':' . $r['status']; }, $res);
fbaia_assert('set_priority APPLIED when auto + allowed', in_array('set_priority:applied', $statuses, true));
fbaia_assert('priority mutation occurred once', $fa->priorityCalls === 1);

// run_mode review + mutations allowed -> still HELD (recipe must be auto).
fbaia_seed_recipe('review');
$s = FBAIA_Helpers::defaults(); $s['recipes_enabled'] = 'yes'; $s['recipe_allow_mutations'] = 'yes';
update_option(FBAIA_OPTION, $s, false);
$fa = new FBAIA_Fake_Adapter_Recipes();
$res = FBAIA_Recipes::run('task_priority_changed', $pl, $fa);
$statuses = array_map(function ($r) { return $r['action'] . ':' . $r['status']; }, $res);
fbaia_assert('review-mode recipe holds mutation even if globally allowed', in_array('set_priority:held', $statuses, true));
fbaia_assert('review-mode recipe does not mutate', $fa->priorityCalls === 0);

// condition mismatch -> recipe does not run.
fbaia_seed_recipe('auto');
$s = FBAIA_Helpers::defaults(); $s['recipes_enabled'] = 'yes'; $s['recipe_allow_mutations'] = 'yes';
update_option(FBAIA_OPTION, $s, false);
$fa = new FBAIA_Fake_Adapter_Recipes();
$res = FBAIA_Recipes::run('task_priority_changed', ['task' => ['priority' => 'low', 'id' => 7, 'board_id' => 12]], $fa);
fbaia_assert('condition mismatch -> no actions', $res === []);

// --- from_structured() (visual builder POST shape) ---
$structured = [
    '0' => [
        'name' => 'Builder recipe', 'enabled' => '1', 'trigger' => 'task_created',
        'match' => 'all', 'run_mode' => 'review', 'days' => '5', 'board_allowlist' => '12',
        'conditions' => ['0' => ['field' => 'due_at', 'op' => 'is_empty', 'value' => '']],
        'actions' => ['0' => ['type' => 'notify', 'to' => 'a@b.com', 'message' => 'no due date']],
    ],
    '1' => ['name' => 'No actions -> dropped', 'trigger' => 'task_created', 'actions' => []],
];
$built = FBAIA_Recipes::from_structured($structured);
fbaia_assert('from_structured builds valid recipe', count($built) === 1);
fbaia_assert('from_structured parsed trigger', $built[0]['trigger'] === 'task_created');
fbaia_assert('from_structured parsed condition', $built[0]['conditions'][0]['op'] === 'is_empty');
fbaia_assert('from_structured enabled "1" -> true', $built[0]['enabled'] === true);
fbaia_assert('from_structured days clamped int', $built[0]['days'] === 5);

// --- time triggers accepted by parse ---
$t = FBAIA_Recipes::parse(json_encode([['trigger' => 'task_stale', 'days' => 7, 'enabled' => true, 'actions' => [['type' => 'notify']]]]));
fbaia_assert('time trigger task_stale accepted', count($t) === 1 && $t[0]['trigger'] === 'task_stale');
fbaia_assert('is_time_trigger true for task_stale', FBAIA_Recipes::is_time_trigger('task_stale') === true);
fbaia_assert('is_time_trigger false for task_created', FBAIA_Recipes::is_time_trigger('task_created') === false);

// --- time_scan() drives the adapter query + executes actions ---
class FBAIA_Fake_Adapter_Time
{
    public $staleCalled = 0;
    public function get_stale_tasks($before, $limit = 50, $allow = '') { $this->staleCalled++; return [['id' => 5, 'board_id' => 12, 'priority' => 'low']]; }
    public function get_due_tasks($before, $limit = 25, $allow = '') { return []; }
    public function get_tasks_due_within($from, $to, $limit = 50, $allow = '') { return []; }
    public function task_to_array($t) { return is_array($t) ? $t : []; }
    public function is_available() { return true; }
}

fbaia_reset_options();
update_option(FBAIA_Recipes::OPTION, FBAIA_Recipes::parse(json_encode([[
    'name' => 'Nudge stale', 'enabled' => true, 'trigger' => 'task_stale', 'days' => 3,
    'actions' => [['type' => 'notify', 'to' => 'a@b.com', 'message' => 'stale']],
]])), false);
$s = FBAIA_Helpers::defaults(); $s['recipes_enabled'] = 'yes';
update_option(FBAIA_OPTION, $s, false);
$ft = new FBAIA_Fake_Adapter_Time();
$res = FBAIA_Recipes::time_scan($ft);
fbaia_assert('time_scan queried stale tasks', $ft->staleCalled === 1);
fbaia_assert('time_scan executed notify for stale task', in_array('notify:sent', array_map(function ($r) { return $r['action'] . ':' . $r['status']; }, $res), true));

// time_scan respects global recipes_enabled = no.
$s['recipes_enabled'] = 'no'; update_option(FBAIA_OPTION, $s, false);
$ft2 = new FBAIA_Fake_Adapter_Time();
fbaia_assert('time_scan no-op when recipes disabled', FBAIA_Recipes::time_scan($ft2) === [] && $ft2->staleCalled === 0);

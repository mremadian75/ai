<?php
/**
 * Safe-mode contract: when the master engine is disabled, no task is mutated — not by the
 * overdue-scan cron and not by the manual "Run overdue scan" button. Run via tests/run.php.
 */

require_once dirname(__DIR__) . '/includes/class-fbaia-internal-actions.php';

// Fake adapter that records whether the overdue-task query was reached.
class FBAIA_Fake_Adapter_Probe
{
    public $queried = false;
    public function get_due_tasks($before, $limit = 25, $allowlist = '')
    {
        $this->queried = true;
        return [];
    }
    public function task_to_array($task)
    {
        return is_array($task) ? $task : [];
    }
    public function is_available()
    {
        return true;
    }
}

echo "Safe-mode mutation guard\n";

// Engine OFF but overdue scan ON: the scan must not even reach the adapter.
$fake = new FBAIA_Fake_Adapter_Probe();
$off = FBAIA_Helpers::defaults();
$off['enabled'] = 'no';
$off['overdue_scan_enabled'] = 'yes';
$off['overdue_email_enabled'] = 'no';
(new FBAIA_Internal_Actions($off, $fake))->process_overdue_scan();
fbaia_assert('engine OFF -> overdue scan never touches tasks', $fake->queried === false);

// Engine ON and overdue scan ON: the scan proceeds and queries the adapter.
$fake2 = new FBAIA_Fake_Adapter_Probe();
$on = FBAIA_Helpers::defaults();
$on['enabled'] = 'yes';
$on['overdue_scan_enabled'] = 'yes';
$on['overdue_email_enabled'] = 'no';
(new FBAIA_Internal_Actions($on, $fake2))->process_overdue_scan();
fbaia_assert('engine ON -> overdue scan queries tasks', $fake2->queried === true);

// Engine ON but overdue scan OFF: still no work.
$fake3 = new FBAIA_Fake_Adapter_Probe();
$on2 = FBAIA_Helpers::defaults();
$on2['enabled'] = 'yes';
$on2['overdue_scan_enabled'] = 'no';
(new FBAIA_Internal_Actions($on2, $fake3))->process_overdue_scan();
fbaia_assert('overdue scan OFF -> no work even when engine on', $fake3->queried === false);

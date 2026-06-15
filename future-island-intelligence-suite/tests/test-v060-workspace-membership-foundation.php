<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return !empty($GLOBALS['logged']); } }
if (!function_exists('current_user_can')) { function current_user_can($cap){ return !empty($GLOBALS['admin']) && $cap === 'manage_options'; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id(){ return 42; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value){ return $value; } }

require_once dirname(__DIR__) . '/includes/class-ves-workspace-membership-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-workspace-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-permission-service.php';

$GLOBALS['logged'] = true; $GLOBALS['admin'] = false;
VES_Workspace_Membership_Store::create_tables(true);
$sql = implode("\n", $GLOBALS['fi_dbdelta_sql']);
fi_spine_ok(strpos($sql, 'ves_workspaces') !== false, 'workspace table migration registered');
fi_spine_ok(strpos($sql, 'ves_workspace_members') !== false, 'workspace members table migration registered');

$ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'Future Island Lab', 'slug' => 'future-island-lab', 'market' => 'Spain', 'default_language' => 'en', 'created_by_user_id' => 42]);
fi_spine_ok(is_int($ws) && $ws > 0, 'create workspace');
$mem = VES_Workspace_Membership_Store::add_member($ws, 42, 'owner', ['invited_by_user_id' => 42]);
fi_spine_ok(is_int($mem) && $mem > 0, 'add owner member');
$resolved = VES_Workspace_Service::resolve_for_request(['workspace_id' => 999999]);
fi_spine_ok((int) ($resolved['workspace_id'] ?? 0) === $ws && !empty($resolved['client_workspace_ignored']), 'client supplied workspace ignored');
$map = VES_Permission_Service::map_for_workspace($ws);
fi_spine_ok(!empty($map['can_manage_members']) && !empty($map['can_export_reports']), 'owner/admin capability map includes member and export controls');

$roles = [
    'admin' => ['can_manage_members' => true, 'can_run_playbook' => true],
    'operator' => ['can_manage_members' => false, 'can_run_playbook' => true],
    'reviewer' => ['can_run_playbook' => false, 'can_review_outputs' => true],
    'viewer' => ['can_review_outputs' => false, 'can_view_usage' => true],
];
foreach ($roles as $role => $expected) {
    fi_spine_reset_db(); $GLOBALS['logged'] = true; $GLOBALS['admin'] = false;
    VES_Workspace_Membership_Store::create_tables(true);
    $w = VES_Workspace_Membership_Store::create_workspace(['name' => 'Role ' . $role, 'created_by_user_id' => 42]);
    VES_Workspace_Membership_Store::add_member($w, 42, $role);
    $cap = VES_Permission_Service::map_for_workspace($w);
    foreach ($expected as $k => $v) { fi_spine_ok((bool) ($cap[$k] ?? false) === $v, $role . ' capability ' . $k); }
}

fi_spine_reset_db(); $GLOBALS['logged'] = true; $GLOBALS['admin'] = false;
VES_Workspace_Membership_Store::create_tables(true);
$inactive_ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'Inactive', 'created_by_user_id' => 42]);
VES_Workspace_Membership_Store::add_member($inactive_ws, 42, 'operator', ['status' => 'inactive']);
fi_spine_ok(VES_Permission_Service::can_run_playbook($inactive_ws) === false, 'inactive member blocked');

fi_spine_reset_db(); $GLOBALS['logged'] = true; $GLOBALS['admin'] = false;
VES_Workspace_Membership_Store::create_tables(true);
$own = VES_Workspace_Membership_Store::create_workspace(['name' => 'Own', 'created_by_user_id' => 42]);
$other = VES_Workspace_Membership_Store::create_workspace(['name' => 'Other', 'created_by_user_id' => 7]);
VES_Workspace_Membership_Store::add_member($own, 42, 'operator');
fi_spine_ok(VES_Permission_Service::can_view_workspace($other) === false, 'wrong workspace blocked');

fi_spine_reset_db(); $GLOBALS['logged'] = true; $GLOBALS['admin'] = true;
fi_spine_ok(VES_Workspace_Service::current_workspace_id() === 42, 'compatibility fallback still works without membership tables');
fi_spine_ok(VES_Permission_Service::can_manage_members(999) === true, 'admin compatibility preserved');

fi_spine_finish('v0.6.0 workspace membership foundation checks');

<?php
if (!defined('ABSPATH')) { exit; }

final class FI_Command_Room_Service {
    const ACTION_BRAND_XRAY = 'fi_command_room_brand_xray';

    public static function workspace_id(): int {
        return class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id() : (function_exists('get_current_user_id') ? max(1, (int) get_current_user_id()) : 1);
    }

    public static function snapshot(array $args = []): array {
        $ws = self::workspace_id();
        $selected_run_id = max(0, (int) ($args['run_id'] ?? ($_GET['run_id'] ?? 0)));
        $runs = class_exists('VES_Run_Service') ? VES_Run_Service::list_runs_for_workspace($ws, ['limit' => 8]) : [];
        $selected = null;
        if ($selected_run_id > 0 && class_exists('VES_Run_Service')) {
            $selected = VES_Run_Service::get_run($selected_run_id);
            if (!is_array($selected) || (int) ($selected['workspace_id'] ?? 0) !== $ws) { $selected = null; }
        }
        if (!$selected && !empty($runs[0])) { $selected = $runs[0]; $selected_run_id = (int) ($selected['id'] ?? 0); }
        return [
            'workspace' => class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace() : ['workspace_id' => $ws, 'resolved_server_side' => true],
            'permissions' => class_exists('VES_Permission_Service') ? VES_Permission_Service::map_for_workspace($ws) : [],
            'playbooks' => self::playbooks(),
            'recent_runs' => $runs,
            'selected_run' => $selected,
            'selected_run_id' => $selected_run_id,
            'selected_objects' => $selected_run_id > 0 && class_exists('VES_Brand_Market_Xray_Playbook') ? VES_Brand_Market_Xray_Playbook::result_for_run($ws, $selected_run_id) : [],
            'decision_report' => $selected_run_id > 0 && class_exists('VES_Decision_Report_Service') ? VES_Decision_Report_Service::build_for_run($ws, $selected_run_id) : [],
            'run_logs' => $selected_run_id > 0 && class_exists('VES_Run_Log_Service') ? VES_Run_Log_Service::list_for_run($ws, $selected_run_id) : [],
            'run_timeline' => $selected_run_id > 0 && class_exists('VES_Run_Log_Service') ? VES_Run_Log_Service::timeline_for_run($ws, $selected_run_id) : [],
            'review_queue' => class_exists('VES_Review_Service') ? VES_Review_Service::queue($ws, 6) : [],
            'memories' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_memory_records(['workspace_id' => $ws, 'limit' => 6]) : [],
            'onboarding' => class_exists('VES_Workspace_Onboarding_Service') ? VES_Workspace_Onboarding_Service::state($ws) : [],
            'provider_readiness' => class_exists('VES_Private_Beta_Readiness_Service') ? VES_Private_Beta_Readiness_Service::snapshot() : [],
        ];
    }

    public static function playbooks(): array {
        return [
            ['id' => 'brand_market_xray', 'label' => 'Brand & Market X-Ray', 'status' => 'available', 'description' => 'Turn brand context and market references into signals, draft insights, a brief candidate and a decision report.'],
            ['id' => 'social_signal_scan', 'label' => 'Social Signal Scan', 'status' => 'coming_soon_ui', 'description' => 'Normalize public social references or existing provider rows into signals and evidence. REST/service skeleton is available.'],
            ['id' => 'post_profile_analysis', 'label' => 'Post/Profile Analysis', 'status' => 'coming_soon_ui', 'description' => 'Inspect a public post or handle; no private/logged-in content.'],
            ['id' => 'brief_builder', 'label' => 'Brief Builder', 'status' => 'available_link', 'description' => 'Build structured Brief OS objects from approved insights or manual intent.'],
            ['id' => 'draft_generator', 'label' => 'Draft Generator', 'status' => 'available_link', 'description' => 'Create reviewable deterministic or provider-backed draft candidates from briefs.'],
            ['id' => 'aeo_action_loop', 'label' => 'AEO/GEO Action Loop', 'status' => 'provider_ready', 'description' => 'Capture manual/provider answer rows into visibility signals, corrective insight, brief and decision report without tracker-only positioning.'],
            ['id' => 'decision_report', 'label' => 'Decision Report', 'status' => 'available_when_run_selected', 'description' => 'Show lineage, evidence, output and usage summary for the selected run.'],
        ];
    }

    public static function handle_brand_xray(): void {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { self::die_or_redirect('forbidden'); }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ACTION_BRAND_XRAY); }
        $ws = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_run_playbook($ws)) { self::die_or_redirect('forbidden'); }
        $input = [
            'brand_url' => $_POST['brand_url'] ?? '',
            'brand_name' => $_POST['brand_name'] ?? '',
            'brand_description' => $_POST['brand_description'] ?? '',
            'market' => $_POST['market'] ?? '',
            'language' => $_POST['language'] ?? '',
            'audience' => $_POST['audience'] ?? '',
            'competitors' => $_POST['competitors'] ?? '',
            'goal' => $_POST['goal'] ?? '',
            'manual_notes' => $_POST['manual_notes'] ?? '',
            'keywords' => $_POST['keywords'] ?? '',
            'social_handles' => $_POST['social_handles'] ?? '',
        ];
        $res = class_exists('VES_Brand_Market_Xray_Playbook') ? VES_Brand_Market_Xray_Playbook::run($input, ['workspace_id' => $ws, 'idempotency_key' => (string) ($_POST['idempotency_key'] ?? '')]) : null;
        $run_id = is_array($res) ? (int) ($res['run_id'] ?? 0) : 0;
        $notice = $run_id > 0 ? 'brand_xray_completed' : 'brand_xray_failed';
        $err = (function_exists('is_wp_error') && is_wp_error($res)) ? $res->get_error_code() : '';
        $url = function_exists('admin_url') ? admin_url('admin.php?page=fi-command-room') : 'admin.php?page=fi-command-room';
        if (function_exists('add_query_arg')) { $url = add_query_arg(array_filter(['fi_notice' => $notice, 'run_id' => $run_id, 'fi_err' => $err]), $url); }
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); exit; }
    }

    private static function die_or_redirect(string $code): void {
        if (function_exists('wp_die')) { wp_die($code); }
        exit;
    }
}

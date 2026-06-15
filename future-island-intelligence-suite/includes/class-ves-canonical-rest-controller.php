<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Tenant-scoped REST surface for the canonical Future Island spine.
 * Admin-gated in this sprint; workspace is resolved server-side and client
 * workspace IDs are ignored.
 */
final class VES_Canonical_REST_Controller {
    const NS = 'fi/v1';

    public static function register(): void {
        if (!function_exists('add_action')) { return; }
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        if (!function_exists('register_rest_route')) { return; }
        $routes = [
            ['/workspaces/current', 'GET', 'current_workspace'],
            ['/runs/(?P<id>\d+)', 'GET', 'get_run'],
            ['/runs/(?P<id>\d+)/timeline', 'GET', 'run_timeline'],
            ['/runs/(?P<id>\d+)/diagnostics', 'GET', 'run_diagnostics'],
            ['/runs/(?P<id>\d+)/decision-report', 'GET', 'decision_report'],
            ['/runs/(?P<id>\d+)/report', 'GET', 'get_run_report'],
            ['/runs/(?P<id>\d+)/report.md', 'GET', 'get_run_report_markdown'],
            ['/provider/contracts', 'GET', 'provider_contracts'],
            ['/provider/settings', 'GET', 'provider_settings'],
            ['/provider/settings', 'POST', 'update_provider_settings'],
            ['/provider/ingestions', 'GET', 'provider_ingestions'],
            ['/provider/ingestions/(?P<id>\d+)', 'GET', 'provider_ingestion'],
            ['/provider/ingestions/(?P<id>\d+)/review', 'POST', 'provider_ingestion_review'],
            ['/provider/ingestions/(?P<id>\d+)/void', 'POST', 'provider_ingestion_void'],
            ['/provider/ingestions/(?P<id>\d+)/archive', 'POST', 'provider_ingestion_archive'],
            ['/provider/social/ingest', 'POST', 'provider_social_ingest'],
            ['/provider/aeo/ingest', 'POST', 'provider_aeo_ingest'],
            ['/provider/creative/ingest', 'POST', 'provider_creative_ingest'],
            ['/creative/taxonomy', 'GET', 'creative_taxonomy'],
            ['/outcomes', 'POST', 'record_outcome'],
            ['/decision-map/(?P<target_type>[a-zA-Z0-9_\-]+)/(?P<target_id>\d+)', 'GET', 'decision_map'],
            ['/playbooks/brand-market-xray', 'POST', 'run_brand_xray'],
            ['/social/query', 'POST', 'run_social_query'],
            ['/social/post-analysis', 'POST', 'run_social_post_analysis'],
            ['/social/profile-analysis', 'POST', 'run_social_profile_analysis'],
            ['/insights/(?P<id>\d+)/review', 'POST', 'review_insight'],
            ['/insights/(?P<id>\d+)/brief', 'POST', 'create_brief_from_insight'],
            ['/briefs/(?P<id>\d+)/drafts', 'POST', 'create_draft_from_brief'],
            ['/briefs/(?P<id>\d+)/review', 'POST', 'review_brief'],
            ['/drafts/(?P<id>\d+)/review', 'POST', 'review_draft'],
            ['/drafts/(?P<id>\d+)/memory', 'POST', 'create_memory_from_draft'],
            ['/memory', 'GET', 'list_memory'],
            ['/memory/(?P<id>\d+)/review', 'POST', 'review_memory'],
            ['/patterns', 'GET', 'list_patterns'],
            ['/reports/export', 'POST', 'export_report'],
            ['/aeo/prompt-set', 'POST', 'run_aeo_prompt_set'],
            ['/review-queue', 'GET', 'review_queue'],
            ['/workspaces/onboarding', 'GET', 'workspace_onboarding'],
            ['/workspaces/onboarding', 'POST', 'save_workspace_onboarding'],
            ['/operator-qa', 'GET', 'operator_qa'],
        ];
        foreach ($routes as $r) {
            $permission = in_array($r[2], ['provider_social_ingest','provider_aeo_ingest','provider_creative_ingest'], true)
                ? [__CLASS__, 'can_access_provider_callback']
                : [__CLASS__, 'can_access'];
            register_rest_route(self::NS, $r[0], [[
                'methods' => $r[1],
                'callback' => [__CLASS__, $r[2]],
                'permission_callback' => $permission,
            ]]);
        }
    }

    public static function can_access($request = null): bool {
        if (function_exists('is_user_logged_in') && !is_user_logged_in()) { return false; }
        if (class_exists('VES_Permission_Service')) {
            $ws = self::workspace_id();
            return VES_Permission_Service::can_view_workspace($ws);
        }
        return function_exists('current_user_can') ? current_user_can('manage_options') : false;
    }


    public static function can_access_provider_callback($request = null): bool {
        if (!class_exists('VES_Provider_Callback_Auth_Service')) {
            return self::can_access($request);
        }
        $route = is_object($request) && method_exists($request, 'get_route') ? (string) $request->get_route() : '';
        $family = 'social_signal';
        if (strpos($route, '/provider/aeo/') !== false) { $family = 'aeo_answer'; }
        elseif (strpos($route, '/provider/creative/') !== false) { $family = 'creative_asset_analysis'; }
        $auth = VES_Provider_Callback_Auth_Service::authenticate($request, $family, ['consume' => false]);
        return !self::is_err($auth);
    }

    public static function current_workspace($request = null) {
        $workspace = class_exists('VES_Workspace_Service') ? VES_Workspace_Service::resolve_for_request($request) : ['workspace_id' => self::workspace_id(), 'resolved_server_side' => true, 'policy' => 'admin_gated_workspace_fallback'];
        if (class_exists('VES_Permission_Service')) { $workspace['permissions'] = VES_Permission_Service::map_for_workspace((int) $workspace['workspace_id']); }
        return self::response($workspace);
    }

    public static function get_run($request) {
        $check = self::run_from_request($request);
        if (self::is_err($check)) { return $check; }
        return self::response(['run' => $check]);
    }

    public static function run_timeline($request) {
        $run = self::run_from_request($request);
        if (self::is_err($run)) { return $run; }
        $workspace_id = (int) ($run['workspace_id'] ?? 0);
        $run_id = (int) ($run['id'] ?? 0);
        $edges = class_exists('VES_Decision_Edge_Service') ? VES_Decision_Edge_Service::list_edges_for_run($workspace_id, $run_id) : [];
        $report = class_exists('VES_Decision_Report_Service') ? VES_Decision_Report_Service::build_for_run($workspace_id, $run_id) : [];
        $logs = class_exists('VES_Run_Log_Service') ? VES_Run_Log_Service::list_for_run($workspace_id, $run_id) : [];
        $timeline = class_exists('VES_Run_Log_Service') ? VES_Run_Log_Service::timeline_for_run($workspace_id, $run_id) : [];
        $diagnostics = class_exists('VES_Run_Diagnostics_Service') ? VES_Run_Diagnostics_Service::diagnose($workspace_id, $run_id) : [];
        return self::response(['run' => $run, 'decision_edges' => $edges, 'decision_report' => $report, 'run_logs' => $logs, 'timeline' => $timeline, 'diagnostics' => $diagnostics, 'operator_links' => ['provider_ingestions' => '/wp-admin/admin.php?page=fi-provider-ingestions&run_id=' . $run_id, 'review_queue' => '/wp-json/fi/v1/review-queue', 'report' => '/wp-json/fi/v1/runs/' . $run_id . '/report'], 'raw_provider_payloads_exposed' => false]);
    }

    public static function run_diagnostics($request) {
        $run = self::run_from_request($request);
        if (self::is_err($run)) { return $run; }
        $workspace_id = (int) ($run['workspace_id'] ?? 0);
        $run_id = (int) ($run['id'] ?? 0);
        $diag = class_exists('VES_Run_Diagnostics_Service') ? VES_Run_Diagnostics_Service::diagnose($workspace_id, $run_id) : ['workspace_id' => $workspace_id, 'run_id' => $run_id, 'diagnosis' => 'unavailable'];
        return self::response(['diagnostics' => class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($diag) : $diag]);
    }

    public static function decision_report($request) {
        $run = self::run_from_request($request);
        if (self::is_err($run)) { return $run; }
        $report = class_exists('VES_Decision_Report_Service') ? VES_Decision_Report_Service::build_for_run((int) $run['workspace_id'], (int) $run['id']) : [];
        return self::response(['report' => $report]);
    }

    public static function decision_map($request) {
        $workspace_id = self::workspace_id();
        $target_type = self::clean_key((string) self::request_value($request, 'target_type'), 60);
        $target_id = self::request_int($request, 'target_id');
        if ($target_type === '' || $target_id <= 0) { return self::error('fi_bad_target', 'Target type and id are required.', 400); }
        $incoming = class_exists('VES_Decision_Edge_Service') ? VES_Decision_Edge_Service::list_edges_for_target($workspace_id, $target_type, $target_id) : [];
        $outgoing = class_exists('VES_Decision_Edge_Service') ? VES_Decision_Edge_Service::list_edges_from_source($workspace_id, $target_type, $target_id) : [];
        return self::response(['target_type' => $target_type, 'target_id' => $target_id, 'incoming' => $incoming, 'outgoing' => $outgoing]);
    }

    public static function run_brand_xray($request) {
        $workspace_id = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_run_playbook($workspace_id)) { return self::error('fi_forbidden', 'Not allowed to run playbooks.', 403); }
        if (!class_exists('VES_Brand_Market_Xray_Playbook')) { return self::error('fi_playbook_unavailable', 'Brand & Market X-Ray unavailable.', 503); }
        $params = self::params($request);
        $res = VES_Brand_Market_Xray_Playbook::run($params, ['workspace_id' => $workspace_id, 'idempotency_key' => (string) ($params['idempotency_key'] ?? '')]);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response($res, 201);
    }

    public static function run_social_query($request) { return self::run_social($request, 'topic'); }
    public static function run_social_post_analysis($request) { return self::run_social($request, 'post'); }
    public static function run_social_profile_analysis($request) { return self::run_social($request, 'profile'); }

    private static function run_social($request, string $mode) {
        $workspace_id = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_run_playbook($workspace_id)) { return self::error('fi_forbidden', 'Not allowed to run playbooks.', 403); }
        if (!class_exists('VES_Social_Signal_Intelligence_Service')) { return self::error('fi_social_unavailable', 'Social Signal Intelligence unavailable.', 503); }
        $params = self::params($request);
        $params['mode'] = $mode;
        $res = VES_Social_Signal_Intelligence_Service::run_scan($params, ['workspace_id' => $workspace_id, 'idempotency_key' => (string) ($params['idempotency_key'] ?? '')]);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response($res, 201);
    }

    public static function review_insight($request) {
        return self::review_target('insight', self::request_int($request, 'id'), $request);
    }

    private static function review_target(string $type, int $id, $request) {
        $workspace_id = self::workspace_id();
        if (!class_exists('VES_Review_Service')) { return self::error('fi_review_unavailable', 'Review service unavailable.', 503); }
        $params = self::params($request);
        $decision = self::clean_key((string) ($params['decision'] ?? 'revise'), 40);
        $res = VES_Review_Service::review($type, $id, $decision, [
            'workspace_id' => $workspace_id,
            'reason_code' => (string) ($params['reason_code'] ?? ''),
            'notes' => (string) ($params['notes'] ?? ($params['reason'] ?? '')),
        ]);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response($res);
    }

    public static function create_brief_from_insight($request) {
        $workspace_id = self::workspace_id();
        if (!class_exists('VES_Review_Service')) { return self::error('fi_brief_unavailable', 'Brief creation unavailable.', 503); }
        $params = self::params($request);
        $res = VES_Review_Service::create_brief_from_insight(self::request_int($request, 'id'), array_merge($params, ['workspace_id' => $workspace_id]));
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response(['brief_id' => (int) $res], 201);
    }

    public static function create_draft_from_brief($request) {
        $workspace_id = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_run_playbook($workspace_id)) { return self::error('fi_forbidden', 'Not allowed to create drafts.', 403); }
        if (!class_exists('VES_Draft_Service')) { return self::error('fi_draft_unavailable', 'Draft service unavailable.', 503); }
        $brief_id = self::request_int($request, 'id');
        $params = self::params($request);
        $res = VES_Draft_Service::create_draft_from_brief($brief_id, $params);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response(['draft_id' => (int) $res], 201);
    }


    public static function run_aeo_prompt_set($request) {
        $workspace_id = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_run_playbook($workspace_id)) { return self::error('fi_forbidden', 'Not allowed to run AEO prompt sets.', 403); }
        if (!class_exists('VES_AEO_Action_Service')) { return self::error('fi_aeo_unavailable', 'AEO/GEO action loop unavailable.', 503); }
        $params = self::params($request);
        $res = VES_AEO_Action_Service::run_prompt_set($params, ['workspace_id' => $workspace_id, 'idempotency_key' => (string) ($params['idempotency_key'] ?? '')]);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response($res, 201);
    }

    public static function review_queue($request) {
        $workspace_id = self::workspace_id();
        if (!class_exists('VES_Review_Service')) { return self::response(['queue' => []]); }
        return self::response(['workspace_id' => $workspace_id, 'queue' => VES_Review_Service::queue($workspace_id, 20)]);
    }

    public static function record_outcome($request) {
        $workspace_id = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_record_outcome($workspace_id)) { return self::error('fi_forbidden', 'Not allowed to record outcomes.', 403); }
        $params = self::params($request);
        $target_type = self::clean_key((string) ($params['target_type'] ?? ''), 60);
        $target_id = max(0, (int) ($params['target_id'] ?? 0));
        if ($target_type === '' || $target_id <= 0) { return self::error('fi_bad_target', 'Outcome target is required.', 400); }
        $run_id = max(0, (int) ($params['run_id'] ?? 0));
        if ($run_id > 0 && class_exists('VES_Run_Service')) {
            $check = VES_Run_Service::assert_workspace($run_id, $workspace_id);
            if (self::is_err($check)) { return self::error('fi_run_forbidden', 'Run belongs to a different workspace.', 403); }
        }
        if ($run_id <= 0) { $run_id = self::target_run_id($target_type, $target_id, $workspace_id); }
        if (!class_exists('VES_Outcome_Service')) { return self::error('fi_outcome_unavailable', 'Outcome store unavailable.', 503); }
        $outcome_id = VES_Outcome_Service::record_outcome([
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'target_type' => $target_type,
            'target_id' => $target_id,
            'channel' => self::clean_key((string) ($params['channel'] ?? ''), 80),
            'outcome_type' => self::clean_key((string) ($params['outcome_type'] ?? 'manual_note'), 60),
            'observed_at' => (string) ($params['observed_at'] ?? ''),
            'metrics' => is_array($params['metrics'] ?? null) ? $params['metrics'] : [],
            'qualitative_note' => self::clean_textarea((string) ($params['qualitative_note'] ?? ''), 5000),
            'result_label' => self::clean_key((string) ($params['result_label'] ?? 'inconclusive'), 40),
            'confidence_score' => isset($params['confidence_score']) ? (float) $params['confidence_score'] : null,
            'source_ref' => self::clean_text((string) ($params['source_ref'] ?? ''), 255),
            'metadata' => ['created_via' => 'canonical_rest'],
        ]);
        if (self::is_err($outcome_id)) { return self::error($outcome_id->get_error_code(), $outcome_id->get_error_message(), 400); }
        $outcome_id = (int) $outcome_id;
        if ($outcome_id > 0 && class_exists('VES_Decision_Edge_Service')) {
            VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, $target_type, $target_id, 'outcome', $outcome_id, 'produced_outcome', ['metadata' => ['created_via' => 'canonical_rest']]);
        }
        return self::response(['outcome_id' => $outcome_id, 'run_id' => $run_id], 201);
    }



    public static function review_brief($request) {
        $params = self::params($request);
        $decision = self::clean_key((string) ($params['decision'] ?? 'revise'), 40);
        $action = $decision === 'approve' ? 'approve_brief' : ($decision === 'reject' ? 'request_brief_revision' : ($decision === 'archive' ? 'request_brief_revision' : 'request_brief_revision'));
        if ($decision === 'ready' || $decision === 'mark_ready') { $action = 'mark_brief_ready'; }
        $res = class_exists('VES_Command_Room_Action_Service') ? VES_Command_Room_Action_Service::perform(array_merge($params, ['command_action' => $action, 'target_type' => 'brief', 'target_id' => self::request_int($request, 'id')])) : self::error('fi_action_unavailable', 'Action service unavailable.', 503);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response($res);
    }

    public static function review_draft($request) {
        $params = self::params($request);
        $decision = self::clean_key((string) ($params['decision'] ?? 'revise'), 40);
        $action = $decision === 'approve' ? 'approve_draft' : ($decision === 'reject' ? 'reject_draft' : ($decision === 'primary' ? 'mark_draft_primary' : 'request_draft_revision'));
        $res = class_exists('VES_Command_Room_Action_Service') ? VES_Command_Room_Action_Service::perform(array_merge($params, ['command_action' => $action, 'target_type' => 'draft', 'target_id' => self::request_int($request, 'id')])) : self::error('fi_action_unavailable', 'Action service unavailable.', 503);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response($res);
    }

    public static function create_memory_from_draft($request) {
        $params = self::params($request);
        $res = class_exists('VES_Command_Room_Action_Service') ? VES_Command_Room_Action_Service::perform(array_merge($params, ['command_action' => 'save_memory_candidate', 'target_type' => 'draft', 'target_id' => self::request_int($request, 'id')])) : self::error('fi_action_unavailable', 'Action service unavailable.', 503);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response($res, 201);
    }

    public static function list_memory($request) {
        $workspace_id = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_manage_memory($workspace_id)) { return self::error('fi_forbidden', 'Not allowed to view memory.', 403); }
        $status = self::clean_key((string) self::request_value($request, 'status'), 24);
        $filters = ['workspace_id' => $workspace_id, 'limit' => 50];
        if ($status !== '') { $filters['status'] = $status; }
        $rows = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_memory_records($filters) : [];
        return self::response(['workspace_id' => $workspace_id, 'memory' => $rows, 'memory_is_context_not_evidence' => true]);
    }

    public static function review_memory($request) {
        $params = self::params($request);
        $decision = self::clean_key((string) ($params['decision'] ?? 'activate'), 40);
        $action = ['activate' => 'activate_memory', 'approve' => 'activate_memory', 'reject' => 'reject_memory', 'pin' => 'pin_memory', 'unpin' => 'unpin_memory', 'archive' => 'archive_memory', 'stale' => 'mark_memory_stale', 'expire' => 'expire_memory'][$decision] ?? 'mark_memory_stale';
        $res = class_exists('VES_Command_Room_Action_Service') ? VES_Command_Room_Action_Service::perform(array_merge($params, ['command_action' => $action, 'target_type' => 'memory_record', 'target_id' => self::request_int($request, 'id')])) : self::error('fi_action_unavailable', 'Action service unavailable.', 503);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response($res);
    }

    public static function list_patterns($request) {
        $workspace_id = self::workspace_id();
        $rows = class_exists('VES_Pattern_Observation_Service') ? VES_Pattern_Observation_Service::list_for_workspace($workspace_id, ['limit' => 50]) : [];
        return self::response(['workspace_id' => $workspace_id, 'patterns' => $rows]);
    }

    public static function export_report($request) {
        $workspace_id = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_export_reports($workspace_id)) { return self::error('fi_forbidden', 'Not allowed to export reports.', 403); }
        $run_id = self::request_int($request, 'run_id');
        if ($run_id <= 0) { return self::error('fi_bad_run', 'run_id is required.', 400); }
        $run = class_exists('VES_Run_Service') ? VES_Run_Service::get_run($run_id) : null;
        if (!is_array($run) || (int) ($run['workspace_id'] ?? 0) !== $workspace_id) { return self::error('fi_run_forbidden', 'Run belongs to a different workspace.', 403); }
        $params = self::params($request);
        $report = class_exists('VES_Decision_Report_Service') ? VES_Decision_Report_Service::build_for_run($workspace_id, $run_id) : [];
        $format = self::clean_key((string) ($params['format'] ?? 'json'), 20);
        if (in_array($format, ['markdown','md'], true) && class_exists('VES_Decision_Report_Service')) {
            return self::response(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'format' => 'markdown', 'content' => VES_Decision_Report_Service::render_markdown($report), 'shareable' => false, 'note' => 'Private beta export payload. No public unauthenticated share link is created.']);
        }
        if ($format === 'html' && class_exists('VES_Decision_Report_Service')) {
            return self::response(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'format' => 'html', 'content' => VES_Decision_Report_Service::render_private_html($report), 'shareable' => false, 'note' => 'Private beta export payload. No public unauthenticated share link is created.']);
        }
        return self::response(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'format' => 'json', 'report' => $report, 'shareable' => false, 'note' => 'Private beta export payload. No public unauthenticated share link is created.']);
    }


    public static function provider_contracts($request) {
        $contracts = class_exists('VES_Provider_Contract_Service') ? VES_Provider_Contract_Service::public_contracts() : [];
        return self::response(['contracts' => $contracts, 'providers_write_directly_to_db' => false, 'wordpress_validates_and_maps_rows' => true]);
    }

    public static function creative_taxonomy($request) {
        return self::response(['dimensions' => class_exists('VES_Creative_Taxonomy_Service') ? VES_Creative_Taxonomy_Service::dimensions() : []]);
    }


    public static function provider_settings($request) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return self::error('fi_forbidden', 'Not allowed to view provider settings.', 403); }
        return self::response(['environment' => class_exists('VES_Provider_Settings_Service') ? VES_Provider_Settings_Service::environment() : 'unknown', 'providers' => class_exists('VES_Provider_Settings_Service') ? VES_Provider_Settings_Service::public_statuses() : []]);
    }

    public static function update_provider_settings($request) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return self::error('fi_forbidden', 'Not allowed to update provider settings.', 403); }
        if (!class_exists('VES_Provider_Settings_Service')) { return self::error('fi_provider_settings_unavailable', 'Provider settings unavailable.', 503); }
        $p = self::params($request);
        if (!empty($p['environment'])) { VES_Provider_Settings_Service::set_environment((string) $p['environment']); }
        $family = self::clean_key((string) ($p['provider_family'] ?? ''), 60);
        $key = self::clean_key((string) ($p['provider_key'] ?? ''), 80);
        if ($family !== '' && $key !== '') {
            $ok = VES_Provider_Settings_Service::set($family, $key, $p);
            if (self::is_err($ok)) { return self::error($ok->get_error_code(), $ok->get_error_message(), 400); }
        }
        return self::response(['updated' => true, 'providers' => VES_Provider_Settings_Service::public_statuses()]);
    }

    public static function provider_ingestions($request) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return self::error('fi_forbidden', 'Not allowed to view provider ingestions.', 403); }
        $p = self::params($request);
        $filters = [
            'provider_family' => self::clean_key((string) ($p['provider_family'] ?? ''), 60),
            'provider_key' => self::clean_key((string) ($p['provider_key'] ?? ''), 80),
            'status' => self::clean_key((string) ($p['status'] ?? ''), 40),
            'workspace_id' => max(0, (int) ($p['workspace_id'] ?? 0)),
            'run_id' => max(0, (int) ($p['run_id'] ?? 0)),
            'reviewed' => self::clean_key((string) ($p['reviewed'] ?? ''), 20),
            'has_rejected' => array_key_exists('has_rejected', $p) ? (int) $p['has_rejected'] : '',
            'date_from' => self::clean_text((string) ($p['date_from'] ?? ''), 30),
            'date_to' => self::clean_text((string) ($p['date_to'] ?? ''), 30),
            'limit' => max(1, min(200, (int) ($p['limit'] ?? 100))),
            'offset' => max(0, (int) ($p['offset'] ?? 0)),
        ];
        $rows = class_exists('VES_Provider_Ingestion_Store') ? VES_Provider_Ingestion_Store::list($filters) : [];
        $total = class_exists('VES_Provider_Ingestion_Store') && method_exists('VES_Provider_Ingestion_Store', 'count') ? VES_Provider_Ingestion_Store::count($filters) : count($rows);
        return self::response(['ingestions' => $rows, 'total' => $total, 'limit' => $filters['limit'], 'offset' => $filters['offset'], 'raw_payloads_exposed' => false]);
    }

    public static function provider_ingestion($request) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return self::error('fi_forbidden', 'Not allowed to view provider ingestion.', 403); }
        $id = self::request_int($request, 'id');
        $row = class_exists('VES_Provider_Ingestion_Store') ? VES_Provider_Ingestion_Store::get($id) : null;
        if (!is_array($row)) { return self::error('fi_ingestion_not_found', 'Provider ingestion not found.', 404); }
        return self::response(['ingestion' => class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($row) : $row, 'raw_payloads_exposed' => false]);
    }

    public static function provider_ingestion_review($request) { return self::provider_ingestion_action($request, 'reviewed'); }
    public static function provider_ingestion_void($request) { return self::provider_ingestion_action($request, 'voided'); }
    public static function provider_ingestion_archive($request) { return self::provider_ingestion_action($request, 'archived'); }

    private static function provider_ingestion_action($request, string $action) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return self::error('fi_forbidden', 'Not allowed to update provider ingestion.', 403); }
        $id = self::request_int($request, 'id');
        if (!class_exists('VES_Provider_Ingestion_Store')) { return self::error('fi_ingestion_unavailable', 'Provider ingestion store unavailable.', 503); }
        if ($action === 'reviewed') { $ok = VES_Provider_Ingestion_Store::mark_reviewed($id); }
        elseif ($action === 'voided') { $ok = method_exists('VES_Provider_Ingestion_Store', 'void_usage_if_no_value') ? VES_Provider_Ingestion_Store::void_usage_if_no_value($id) : VES_Provider_Ingestion_Store::void_usage($id); }
        else { $ok = VES_Provider_Ingestion_Store::archive($id); }
        if (self::is_err($ok)) { return self::error($ok->get_error_code(), $ok->get_error_message(), 400); }
        if (!$ok) { return self::error('fi_ingestion_update_failed', 'Provider ingestion update failed.', 400); }
        return self::response(['updated' => true, 'action' => $action, 'ingestion' => VES_Provider_Ingestion_Store::get($id)]);
    }

    public static function workspace_onboarding($request) {
        $workspace_id = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_view_workspace($workspace_id)) { return self::error('fi_forbidden', 'Not allowed to view onboarding.', 403); }
        return self::response(class_exists('VES_Workspace_Onboarding_Service') ? VES_Workspace_Onboarding_Service::state($workspace_id) : ['workspace_id' => $workspace_id]);
    }

    public static function save_workspace_onboarding($request) {
        $workspace_id = self::workspace_id();
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_manage_sources($workspace_id)) { return self::error('fi_forbidden', 'Not allowed to update onboarding.', 403); }
        if (!class_exists('VES_Workspace_Onboarding_Service')) { return self::error('fi_onboarding_unavailable', 'Workspace onboarding unavailable.', 503); }
        $res = VES_Workspace_Onboarding_Service::save_profile(self::params($request), $workspace_id);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response($res);
    }

    public static function operator_qa($request) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return self::error('fi_forbidden', 'Not allowed to view operator QA.', 403); }
        $workspace_id = self::workspace_id();
        $run_id = max(0, (int) (self::request_value($request, 'run_id') ?? 0));
        $qa = class_exists('VES_Operator_QA_Service') ? VES_Operator_QA_Service::checklist($workspace_id, $run_id) : ['workspace_id' => $workspace_id, 'items' => []];
        return self::response(class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($qa) : $qa);
    }

    public static function provider_social_ingest($request) { return self::provider_ingest($request, 'social_signal'); }
    public static function provider_aeo_ingest($request) { return self::provider_ingest($request, 'aeo_answer'); }
    public static function provider_creative_ingest($request) { return self::provider_ingest($request, 'creative_asset_analysis'); }

    private static function provider_ingest($request, string $family) {
        if (!class_exists('VES_Provider_Run_Service')) { return self::error('fi_provider_unavailable', 'Provider ingestion service unavailable.', 503); }
        $auth = class_exists('VES_Provider_Callback_Auth_Service') ? VES_Provider_Callback_Auth_Service::authenticate($request, $family, ['consume' => true]) : null;
        if (self::is_err($auth)) { return self::error($auth->get_error_code(), $auth->get_error_message(), 401); }
        $params_raw = self::params($request);
        $params = class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($params_raw) : $params_raw;
        $workspace_id = is_array($auth) ? (int) ($auth['workspace_id'] ?? 0) : self::workspace_id();
        $run_id = is_array($auth) ? (int) ($auth['run_id'] ?? 0) : max(0, (int) ($params['run_id'] ?? 0));
        if ($run_id <= 0 || $workspace_id <= 0) { return self::error('fi_bad_run', 'run_id is required.', 400); }
        $rows = is_array($params['rows'] ?? null) ? $params['rows'] : [];
        $res = VES_Provider_Run_Service::ingest($family, [
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'provider_key' => is_array($auth) ? (string) ($auth['provider_key'] ?? '') : (string) ($params['provider_key'] ?? ''),
            'provider_run_id' => (string) ($params['provider_run_id'] ?? ''),
            'mode' => (string) ($params['mode'] ?? ''),
            'rows' => $rows,
            'idempotency_key' => is_array($auth) ? (string) ($auth['idempotency_key'] ?? '') : (string) ($params['idempotency_key'] ?? ''),
            'usage_mode' => (string) ($params['usage_mode'] ?? 'zero_cost_beta'),
        ]);
        if (self::is_err($res)) { return self::error($res->get_error_code(), $res->get_error_message(), 400); }
        return self::response(class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($res) : $res, 201);
    }

    public static function get_run_report($request) {
        $run = self::run_from_request($request);
        if (self::is_err($run)) { return $run; }
        $report = class_exists('VES_Decision_Report_Service') ? VES_Decision_Report_Service::build_for_run((int) $run['workspace_id'], (int) $run['id']) : [];
        return self::response(['run_id' => (int) $run['id'], 'format' => 'json', 'report' => $report, 'shareable' => false]);
    }

    public static function get_run_report_markdown($request) {
        $run = self::run_from_request($request);
        if (self::is_err($run)) { return $run; }
        $report = class_exists('VES_Decision_Report_Service') ? VES_Decision_Report_Service::build_for_run((int) $run['workspace_id'], (int) $run['id']) : [];
        return self::response(['run_id' => (int) $run['id'], 'format' => 'markdown', 'content' => class_exists('VES_Decision_Report_Service') ? VES_Decision_Report_Service::render_markdown($report) : '', 'shareable' => false]);
    }

    private static function run_from_request($request) {
        $workspace_id = self::workspace_id();
        $run_id = self::request_int($request, 'id');
        $run = class_exists('VES_Run_Service') ? VES_Run_Service::get_run($run_id) : null;
        if (!is_array($run) || empty($run['id'])) { return self::error('fi_run_not_found', 'Run not found.', 404); }
        if ((int) ($run['workspace_id'] ?? 0) !== $workspace_id) { return self::error('fi_run_forbidden', 'Run belongs to a different workspace.', 403); }
        return $run;
    }

    private static function workspace_id(): int {
        return class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id() : (function_exists('get_current_user_id') ? max(1, (int) get_current_user_id()) : 1);
    }

    private static function target_run_id(string $target_type, int $target_id, int $workspace_id): int {
        if (!class_exists('VES_Intelligence_Store')) { return 0; }
        $method = 'get_' . $target_type;
        if (!in_array($target_type, ['source','signal','evidence','insight','brief','draft'], true) || !method_exists('VES_Intelligence_Store', $method)) { return 0; }
        $row = VES_Intelligence_Store::$method($target_id);
        if (!is_array($row) || (int) ($row['workspace_id'] ?? 0) !== $workspace_id) { return 0; }
        return max(0, (int) ($row['run_id'] ?? 0));
    }

    private static function params($request): array {
        if (is_object($request) && method_exists($request, 'get_json_params')) { $json = $request->get_json_params(); if (is_array($json) && !empty($json)) { return $json; } }
        if (is_object($request) && method_exists($request, 'get_params')) { $p = $request->get_params(); return is_array($p) ? $p : []; }
        return is_array($request) ? $request : [];
    }
    private static function request_value($request, string $key) { if (is_array($request)) { return $request[$key] ?? null; } if (is_object($request) && method_exists($request, 'get_param')) { return $request->get_param($key); } return null; }
    private static function request_int($request, string $key): int { return max(0, (int) self::request_value($request, $key)); }
    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return strlen($s) > $max ? substr($s, 0, $max) : $s; }
    private static function clean_textarea(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return strlen($s) > $max ? substr($s, 0, $max) : $s; }
    private static function is_err($t): bool { return function_exists('is_wp_error') ? is_wp_error($t) : ($t instanceof WP_Error); }
    private static function response(array $data, int $status = 200) { if (function_exists('rest_ensure_response')) { $r = rest_ensure_response($data); if (is_object($r) && method_exists($r, 'set_status')) { $r->set_status($status); } return $r; } return $data; }
    private static function error(string $code, string $message, int $status) { return class_exists('WP_Error') ? new WP_Error($code, $message, ['status' => $status]) : ['code' => $code, 'message' => $message, 'status' => $status]; }
}

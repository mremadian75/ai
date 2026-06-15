<?php
if (!defined('ABSPATH')) { exit; }

final class FI_Command_Room_Renderer {
    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function ea($s) { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function u($s) { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function cut($s, $max) { $s = (string) $s; return strlen($s) > $max ? substr($s, 0, max(0, $max - 1)) . '...' : $s; }

    public static function render_html(array $args = []): string {
        $snap = FI_Command_Room_Service::snapshot($args);
        $ws = (int) ($snap['workspace']['workspace_id'] ?? 0);
        $selected = is_array($snap['selected_run'] ?? null) ? $snap['selected_run'] : [];
        $objects = is_array($snap['selected_objects'] ?? null) ? $snap['selected_objects'] : [];
        $h = '<div class="wrap ves-wrap fi-command-room" id="fi-command-room">';
        $h .= self::notices();
        $h .= '<header class="fi-room-context fi-command-hero"><p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Command Room') . '</p><h1>' . self::e('Command Room') . '</h1><p class="fi-intake-sub">' . self::e('Command canvas + playbook launcher + evidence drawer + run timeline. Evidence first. Generation second. Memory after review.') . '</p></header>';
        $h .= '<div class="fi-command-layout">';
        $h .= self::left_sidebar($ws, $snap);
        $h .= '<main class="fi-command-canvas">' . self::intent_box() . self::onboarding_panel((array) ($snap['onboarding'] ?? [])) . self::provider_controls_panel((array) ($snap['provider_readiness'] ?? [])) . self::playbook_cards((array) $snap['playbooks']) . self::brand_xray_form($ws) . self::last_run_summary($selected, $objects) . self::review_queue((array) ($snap['review_queue'] ?? [])) . '</main>';
        $h .= self::right_drawer($selected, $objects, (array) ($snap['decision_report'] ?? []));
        $h .= '</div>';
        $h .= self::run_timeline($selected, (array) ($snap['decision_report']['counts'] ?? []), (array) ($snap['run_timeline'] ?? []));
        $h .= '</div>';
        return $h;
    }

    private static function notices(): string {
        $notice = isset($_GET['fi_notice']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $_GET['fi_notice'])) : '';
        $err = isset($_GET['fi_err']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $_GET['fi_err'])) : '';
        if ($notice === 'brand_xray_completed') { return '<div class="notice notice-success"><p>' . self::e('Brand & Market X-Ray completed. Review the run lineage before generating or approving output.') . '</p></div>'; }
        if ($notice === 'brand_xray_failed') { return '<div class="notice notice-error"><p>' . self::e('Brand & Market X-Ray could not complete' . ($err !== '' ? ' (' . $err . ')' : '') . '.') . '</p></div>'; }
        if ($notice === 'command_action_completed') { return '<div class="notice notice-success"><p>' . self::e('Command Room action recorded. Review the updated lineage and decision map.') . '</p></div>'; }
        if ($notice === 'command_action_failed') { return '<div class="notice notice-error"><p>' . self::e('Command Room action was blocked' . ($err !== '' ? ' (' . $err . ')' : '') . '. No successful review decision was written for blocked transitions.') . '</p></div>'; }
        if ($notice === 'onboarding_saved') { return '<div class="notice notice-success"><p>' . self::e('Workspace onboarding saved. Continue with Brand & Market X-Ray or the signed callback simulator route.') . '</p></div>'; }
        if ($notice === 'onboarding_failed') { return '<div class="notice notice-error"><p>' . self::e('Workspace onboarding could not be saved. Check required fields and permissions.') . '</p></div>'; }
        return '';
    }

    private static function left_sidebar(int $ws, array $snap): string {
        $h = '<aside class="fi-command-sidebar" aria-label="' . self::ea('Command Room navigation') . '"><section><h2>' . self::e('Workspace') . '</h2><p><strong>#' . self::e((string) $ws) . '</strong><br><span>' . self::e('Resolved server-side') . '</span></p></section>';
        $h .= '<section><h2>' . self::e('Recent runs') . '</h2><ul class="fi-command-list">';
        foreach ((array) ($snap['recent_runs'] ?? []) as $run) {
            $url = function_exists('admin_url') && function_exists('add_query_arg') ? add_query_arg(['page' => 'fi-command-room', 'run_id' => (int) ($run['id'] ?? 0)], admin_url('admin.php')) : '#';
            $h .= '<li><a href="' . self::u($url) . '">#' . self::e((string) (int) ($run['id'] ?? 0)) . ' · ' . self::e((string) ($run['run_type'] ?? 'run')) . '</a><span>' . self::e((string) ($run['status'] ?? '')) . '</span></li>';
        }
        if (empty($snap['recent_runs'])) { $h .= '<li class="fi-empty-state">' . self::e('No runs yet.') . '</li>'; }
        $h .= '</ul></section><section><h2>' . self::e('Memories') . '</h2><ul class="fi-command-list">';
        foreach (array_slice((array) ($snap['memories'] ?? []), 0, 4) as $mem) { $h .= '<li><span>' . self::e((string) ($mem['status'] ?? 'candidate')) . '</span>' . self::e(self::cut((string) ($mem['trust_label'] ?? 'context - not evidence'), 80)) . '</li>'; }
        if (empty($snap['memories'])) { $h .= '<li class="fi-empty-state">' . self::e('No memory records yet.') . '</li>'; }
        $h .= '</ul></section><section><h2>' . self::e('Reports') . '</h2><p>' . self::e('Private Decision Reports · Evidence packs · Operator QA') . '</p></section><section><h2>' . self::e('Objects') . '</h2><p>' . self::e('Sources · Memories · Usage · Decision Map') . '</p></section></aside>';
        return $h;
    }

    private static function intent_box(): string {
        return '<section class="fi-command-panel fi-command-intent"><h2>' . self::e('Command canvas') . '</h2><label for="fi-command-intent">' . self::e('What decision are we trying to support?') . '</label><textarea id="fi-command-intent" rows="3" placeholder="' . self::ea('Example: understand this brand position and turn it into an evidence-backed campaign brief') . '" disabled></textarea><p class="fi-empty-state">' . self::e('This is not a blank chat. Choose a playbook below so the system creates structured records.') . '</p></section>';
    }


    private static function onboarding_panel(array $state): string {
        if (class_exists('VES_Workspace_Onboarding_Service')) { return VES_Workspace_Onboarding_Service::render_panel($state); }
        return '<section class="fi-command-panel"><h2>' . self::e('Private beta onboarding') . '</h2><p>' . self::e('Workspace onboarding service unavailable.') . '</p></section>';
    }

    private static function provider_controls_panel(array $readiness): string {
        $contracts = function_exists('admin_url') && function_exists('add_query_arg') ? add_query_arg(['page' => 'fi-provider-contracts'], admin_url('admin.php')) : 'admin.php?page=fi-provider-contracts';
        $ingestions = function_exists('admin_url') && function_exists('add_query_arg') ? add_query_arg(['page' => 'fi-provider-ingestions'], admin_url('admin.php')) : 'admin.php?page=fi-provider-ingestions';
        $browser = function_exists('admin_url') && function_exists('add_query_arg') ? add_query_arg(['page' => 'fi-staging-browser-validation'], admin_url('admin.php')) : 'admin.php?page=fi-staging-browser-validation';
        $h = '<section class="fi-command-panel fi-provider-controls"><h2>' . self::e('Provider controls') . '</h2>';
        $h .= '<p>' . self::e('Private-beta provider results can come from the simulator, a custom worker, cron job, Apify wrapper, Make/Zapier-style workflow, optional n8n example, or future managed worker. Future Island does not require n8n. Every callback must authenticate, attach to a canonical Run, write redacted Run Logs, and enter the ingestion ledger before any product evidence is created.') . '</p>';
        $h .= '<p><a class="button button-secondary" href="' . self::u($contracts) . '">' . self::e('Provider / Orchestrator Contracts') . '</a> <a class="button button-secondary" href="' . self::u($ingestions) . '">' . self::e('Provider Ingestions') . '</a> <a class="button button-secondary" href="' . self::u($browser) . '">' . self::e('Browser Validation') . '</a></p>';
        if (!empty($readiness)) {
            $h .= '<ul class="fi-provider-readiness"><li>' . self::e('Callback auth: ' . (!empty($readiness['callback_auth']) ? 'ready' : 'missing')) . '</li><li>' . self::e('Replay guard: ' . (!empty($readiness['replay_guard']) ? 'ready' : 'missing')) . '</li><li>' . self::e('Secret redaction: ' . (!empty($readiness['secret_redaction']) ? 'ready' : 'missing')) . '</li></ul>';
        }
        return $h . '</section>';
    }

    private static function playbook_cards(array $playbooks): string {
        $h = '<section class="fi-command-panel"><h2>' . self::e('Playbook launcher') . '</h2><div class="fi-playbook-grid">';
        foreach ($playbooks as $p) {
            $status = (string) ($p['status'] ?? 'coming_soon');
            $h .= '<article class="fi-playbook-card is-' . self::ea($status) . '"><p class="fi-breadcrumb">' . self::e(str_replace('_', ' ', $status)) . '</p><h3>' . self::e((string) ($p['label'] ?? 'Playbook')) . '</h3><p>' . self::e((string) ($p['description'] ?? '')) . '</p></article>';
        }
        return $h . '</div></section>';
    }

    private static function brand_xray_form(int $ws): string {
        $action = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function_exists('wp_nonce_field') ? wp_nonce_field(FI_Command_Room_Service::ACTION_BRAND_XRAY, '_wpnonce', true, false) : '';
        $idem = 'fi-ui-' . $ws . '-' . md5((string) microtime(true));
        $h = '<section class="fi-command-panel fi-brand-xray-form"><h2>' . self::e('Brand & Market X-Ray') . '</h2><p>' . self::e('Stores URLs as reference sources only. No external crawling or provider call is required.') . '</p>';
        $h .= '<form method="post" action="' . self::u($action) . '">' . $nonce . '<input type="hidden" name="action" value="' . self::ea(FI_Command_Room_Service::ACTION_BRAND_XRAY) . '"><input type="hidden" name="idempotency_key" value="' . self::ea($idem) . '">';
        $fields = [
            ['brand_url','Brand URL','url','https://example.com'], ['brand_name','Brand name','text','Future Island'], ['market','Market','text','Spain / Europe'], ['language','Language','text','en'], ['audience','Audience','text','agency operators, brand teams'], ['goal','Goal','text','Find the clearest evidence-backed campaign angle'], ['competitors','Competitors','textarea','One per line or comma separated'], ['keywords','Keywords/topics','textarea','signal intelligence, cultural tech'], ['social_handles','Optional social handles','textarea','@brand, linkedin.com/company/brand'], ['brand_description','Brand description','textarea','What the brand does and what it wants to be known for'], ['manual_notes','Manual notes','textarea','Constraints, assumptions, open questions'],
        ];
        $h .= '<div class="fi-form-grid">';
        foreach ($fields as $f) {
            [$name,$label,$type,$placeholder] = $f;
            $h .= '<label><span>' . self::e($label) . '</span>';
            if ($type === 'textarea') { $h .= '<textarea name="' . self::ea($name) . '" rows="3" placeholder="' . self::ea($placeholder) . '"></textarea>'; }
            else { $h .= '<input type="' . self::ea($type) . '" name="' . self::ea($name) . '" placeholder="' . self::ea($placeholder) . '">'; }
            $h .= '</label>';
        }
        $h .= '</div><button type="submit" class="button button-primary">' . self::e('Run Brand & Market X-Ray') . '</button></form></section>';
        return $h;
    }

    private static function last_run_summary(array $run, array $objects): string {
        $h = '<section class="fi-command-panel fi-current-run-summary"><p class="fi-breadcrumb">' . self::e('Current run state') . '</p><h2>' . self::e('Last run summary') . '</h2>';
        if (empty($run)) { return $h . '<p class="fi-empty-state">' . self::e('Run a playbook to create the first canonical Run. The recommended first route is Brand & Market X-Ray.') . '</p><p class="fi-next-action"><strong>' . self::e('Next action:') . '</strong> ' . self::e('Add brand context, then use the signed callback simulator or approved external orchestrator if provider rows are needed.') . '</p></section>'; }
        $h .= '<p><strong>#' . self::e((string) (int) $run['id']) . '</strong> · ' . self::e((string) ($run['run_type'] ?? 'run')) . ' · <span class="fi-status-chip">' . self::e((string) ($run['status'] ?? '')) . '</span></p>';
        foreach (['sources','signals','evidence','insights','briefs','drafts'] as $k) { $h .= '<span class="fi-command-count"><strong>' . self::e((string) count((array) ($objects[$k] ?? []))) . '</strong> ' . self::e($k) . '</span>'; }
        $h .= '<div class="fi-usage-strip" aria-label="' . self::ea('Usage and review strip') . '"><strong>' . self::e('Usage:') . '</strong> ' . self::e('zero-cost beta / reserve-settle events stay idempotent') . ' · <strong>' . self::e('Review:') . '</strong> ' . self::e('AI/provider outputs are candidates until human-approved') . '</div>';
        return $h . '<p class="fi-memory-policy">' . self::e('Draft outputs are not final. Review decisions create learning; memory remains context, not evidence.') . '</p></section>';
    }


    private static function review_queue(array $queue): string {
        $h = '<section class="fi-command-panel fi-review-queue"><h2>' . self::e('Insight Review Queue') . '</h2><p class="fi-empty-state">' . self::e('Human review is the learning layer. Approve, reject or request revision before memory becomes trusted context.') . '</p>';
        foreach (['insights' => 'Insight', 'briefs' => 'Brief', 'drafts' => 'Draft'] as $key => $label) {
            $items = array_slice((array) ($queue[$key] ?? []), 0, 4);
            $h .= '<h3>' . self::e($label . ' candidates') . '</h3><ul class="fi-command-list fi-action-list">';
            foreach ($items as $item) {
                $id = (int) ($item['id'] ?? 0);
                $status = (string) ($item['status'] ?? 'draft');
                $evidence_count = count((array) ($item['evidence_ids'] ?? []));
                $h .= '<li><span class="fi-status-chip">' . self::e($status) . '</span><strong>' . self::e(self::cut((string) ($item['title'] ?? ('#' . $id)), 90)) . '</strong>';
                if ($key === 'insights') {
                    $h .= '<p class="fi-evidence-note">' . self::e($evidence_count > 0 ? ('Evidence refs: ' . $evidence_count) : 'Weak evidence warning: memory is context, not evidence.') . '</p>';
                    $h .= self::action_row([
                        ['approve_insight', 'insight', $id, 'Approve'], ['reject_insight', 'insight', $id, 'Reject'], ['request_insight_revision', 'insight', $id, 'Request revision'], ['archive_insight', 'insight', $id, 'Archive'], ['create_brief_from_insight', 'insight', $id, 'Create brief'],
                    ]);
                } elseif ($key === 'briefs') {
                    $h .= '<p class="fi-evidence-note">' . self::e(!empty($item['metadata']['assumption_label']) ? (string) $item['metadata']['assumption_label'] : 'Brief OS object: verify evidence/assumption label before draft generation.') . '</p>';
                    $h .= self::action_row([
                        ['mark_brief_ready', 'brief', $id, 'Mark ready'], ['approve_brief', 'brief', $id, 'Approve'], ['request_brief_revision', 'brief', $id, 'Request revision'], ['generate_draft_from_brief', 'brief', $id, 'Generate draft'],
                    ]);
                } else {
                    $h .= '<p class="fi-evidence-note">' . self::e('Drafts are reviewable candidates. No auto-publish behavior exists here.') . '</p>';
                    $h .= self::action_row([
                        ['approve_draft', 'draft', $id, 'Approve'], ['reject_draft', 'draft', $id, 'Reject'], ['request_draft_revision', 'draft', $id, 'Request revision'], ['mark_draft_primary', 'draft', $id, 'Mark primary'], ['save_memory_candidate', 'draft', $id, 'Save memory candidate'],
                    ]);
                    $h .= self::outcome_form('draft', $id);
                }
                $h .= '</li>';
            }
            if (empty($items)) { $h .= '<li class="fi-empty-state">' . self::e('No candidates in this lane.') . '</li>'; }
            $h .= '</ul>';
        }
        return $h . '</section>';
    }

    private static function right_drawer(array $run, array $objects, array $report): string {
        $h = '<aside class="fi-command-drawer" aria-label="' . self::ea('Evidence and object drawer') . '"><section><h2>' . self::e('Evidence / object drawer') . '</h2>';
        if (empty($run)) { return $h . '<p class="fi-empty-state">' . self::e('Select or run a playbook.') . '</p></section></aside>'; }
        $h .= '<p>' . self::e('Selected run') . ' <strong>#' . self::e((string) (int) $run['id']) . '</strong></p>';
        $h .= '<h3>' . self::e('Evidence refs') . '</h3><ul class="fi-command-list">';
        foreach (array_slice((array) ($objects['evidence'] ?? []), 0, 6) as $ev) { $h .= '<li><span>#' . self::e((string) (int) ($ev['id'] ?? 0)) . '</span>' . self::e(self::cut((string) ($ev['text'] ?? ''), 90)) . '</li>'; }
        if (empty($objects['evidence'])) { $h .= '<li class="fi-empty-state">' . self::e('No evidence refs yet.') . '</li>'; }
        $h .= '</ul>';

        $h .= self::object_lane('Insights', 'insight', (array) ($objects['insights'] ?? []));
        $h .= self::object_lane('Brief Builder', 'brief', (array) ($objects['briefs'] ?? []));
        $h .= self::object_lane('Draft Workbench', 'draft', (array) ($objects['drafts'] ?? []));
        $h .= self::memory_lane((array) ($objects['memory'] ?? []));
        $h .= self::decision_map_panel($run, $objects);
        if (class_exists('VES_Decision_Report_Service')) { $h .= VES_Decision_Report_Service::render_html($report); }
        $h .= self::report_export_form((int) $run['id']);
        return $h . '</section></aside>';
    }

    private static function object_lane(string $title, string $type, array $items): string {
        $h = '<h3>' . self::e($title) . '</h3><ul class="fi-command-list fi-action-list">';
        foreach (array_slice($items, 0, 5) as $item) {
            $id = (int) ($item['id'] ?? 0);
            $h .= '<li><span>' . self::e((string) ($item['status'] ?? '')) . '</span>' . self::e(self::cut((string) ($item['title'] ?? ('#' . $id)), 90));
            if ($type === 'insight') { $h .= self::action_row([['approve_insight','insight',$id,'Approve'], ['reject_insight','insight',$id,'Reject'], ['create_brief_from_insight','insight',$id,'Create brief']]); }
            if ($type === 'brief') { $h .= self::action_row([['mark_brief_ready','brief',$id,'Ready'], ['approve_brief','brief',$id,'Approve'], ['generate_draft_from_brief','brief',$id,'Draft']]); }
            if ($type === 'draft') { $h .= self::action_row([['approve_draft','draft',$id,'Approve'], ['reject_draft','draft',$id,'Reject'], ['mark_draft_primary','draft',$id,'Primary'], ['save_memory_candidate','draft',$id,'Memory']]); $h .= self::outcome_form('draft', $id); }
            $h .= '</li>';
        }
        if (empty($items)) { $h .= '<li class="fi-empty-state">' . self::e('No records yet.') . '</li>'; }
        return $h . '</ul>';
    }

    private static function memory_lane(array $items): string {
        $h = '<h3>' . self::e('Memory Review') . '</h3><p class="fi-evidence-note">' . self::e('Memory is context, not evidence.') . '</p><ul class="fi-command-list fi-action-list">';
        foreach (array_slice($items, 0, 5) as $mem) {
            $id = (int) ($mem['id'] ?? 0);
            $h .= '<li><span class="fi-status-chip">' . self::e((string) ($mem['status'] ?? 'candidate')) . '</span>' . self::e(self::cut((string) ($mem['trust_label'] ?? $mem['text'] ?? ''), 90));
            $h .= self::action_row([['activate_memory','memory_record',$id,'Activate'], ['reject_memory','memory_record',$id,'Reject'], ['pin_memory','memory_record',$id,'Pin'], ['unpin_memory','memory_record',$id,'Unpin'], ['archive_memory','memory_record',$id,'Archive'], ['mark_memory_stale','memory_record',$id,'Stale']]);
            $h .= '</li>';
        }
        if (empty($items)) { $h .= '<li class="fi-empty-state">' . self::e('No memory records for this run yet.') . '</li>'; }
        return $h . '</ul>';
    }

    private static function decision_map_panel(array $run, array $objects): string {
        if (empty($run)) { return '<section class="fi-decision-map"><h3>' . self::e('Decision Map') . '</h3><p class="fi-empty-state">' . self::e('No run selected. Decision Map appears after a canonical Run exists.') . '</p></section>'; }
        $counts = [];
        foreach (['sources','signals','evidence','insights','briefs','drafts','memory','outcomes','patterns'] as $k) { $counts[$k] = count((array) ($objects[$k] ?? [])); }
        $h = '<section class="fi-decision-map" aria-label="' . self::ea('Decision Map') . '"><h3>' . self::e('Decision Map') . '</h3><p>' . self::e('Lineage preview. Redacted, record-first, no Raw provider payloads.') . '</p><ol>';
        $h .= '<li>' . self::e('Source → Run #' . (string) (int) $run['id']) . '</li>';
        $h .= '<li>' . self::e('Run → Signals (' . (string) $counts['signals'] . ') · Evidence (' . (string) $counts['evidence'] . ')') . '</li>';
        $h .= '<li>' . self::e('Evidence → Insights (' . (string) $counts['insights'] . ') → Review Decisions') . '</li>';
        $h .= '<li>' . self::e('Insights → Briefs (' . (string) $counts['briefs'] . ') → Drafts (' . (string) $counts['drafts'] . ')') . '</li>';
        $h .= '<li>' . self::e('Drafts → Memory (' . (string) $counts['memory'] . ') · Outcomes (' . (string) $counts['outcomes'] . ') · Patterns (' . (string) $counts['patterns'] . ')') . '</li>';
        return $h . '</ol><p class="fi-evidence-note">' . self::e('Relation types: Created from · Supported by · Generated · Reviewed by · Spent usage · Created memory · Produced outcome · Informed pattern.') . '</p></section>';
    }

    private static function action_row(array $actions): string {
        $h = '<div class="fi-action-row">';
        foreach ($actions as $a) { $h .= self::action_form((string) $a[0], (string) $a[1], (int) $a[2], (string) $a[3]); }
        return $h . '</div>';
    }

    private static function action_form(string $command_action, string $target_type, int $target_id, string $label, array $extra = []): string {
        if ($target_id <= 0) { return ''; }
        $action = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = (function_exists('wp_nonce_field') && class_exists('VES_Command_Room_Action_Service')) ? wp_nonce_field(VES_Command_Room_Action_Service::ACTION, '_wpnonce', true, false) : '';
        $name = class_exists('VES_Command_Room_Action_Service') ? VES_Command_Room_Action_Service::ACTION : 'fi_command_room_action';
        $h = '<form class="fi-inline-action" method="post" action="' . self::u($action) . '">' . $nonce;
        $h .= '<input type="hidden" name="action" value="' . self::ea($name) . '"><input type="hidden" name="command_action" value="' . self::ea($command_action) . '"><input type="hidden" name="target_type" value="' . self::ea($target_type) . '"><input type="hidden" name="target_id" value="' . self::ea((string) $target_id) . '">';
        $h .= '<input type="hidden" name="idempotency_key" value="' . self::ea('fi-ui-action-' . $command_action . '-' . $target_type . '-' . $target_id) . '">';
        if (in_array($command_action, ['approve_insight','reject_insight','request_insight_revision','archive_insight','approve_brief','request_brief_revision','approve_draft','reject_draft','request_draft_revision','activate_memory','reject_memory','pin_memory','unpin_memory','archive_memory','mark_memory_stale'], true)) {
            $h .= self::reason_select();
            $h .= '<input type="text" name="notes" placeholder="' . self::ea('Review note') . '">';
        }
        foreach ($extra as $k => $v) { $h .= '<input type="hidden" name="' . self::ea((string) $k) . '" value="' . self::ea((string) $v) . '">'; }
        $h .= '<button type="submit" class="button button-small">' . self::e($label) . '</button></form>';
        return $h;
    }

    private static function reason_select(): string {
        $reasons = class_exists('VES_Review_Service') ? VES_Review_Service::reason_codes() : ['good_angle','weak_evidence','too_generic'];
        $h = '<select name="reason_code" aria-label="' . self::ea('Reason code') . '"><option value="">' . self::e('Reason') . '</option>';
        foreach ($reasons as $r) { $h .= '<option value="' . self::ea((string) $r) . '">' . self::e(str_replace('_', ' ', (string) $r)) . '</option>'; }
        return $h . '</select>';
    }

    private static function outcome_form(string $target_type, int $target_id): string {
        if ($target_id <= 0) { return ''; }
        $h = '<details class="fi-outcome-capture"><summary>' . self::e('Record outcome') . '</summary>';
        $h .= self::action_form('record_outcome', $target_type, $target_id, 'Save outcome', ['outcome_type' => 'manual_note', 'result_label' => 'pending']);
        $h .= '<p class="fi-evidence-note">' . self::e('Outcome receipts are manual in private beta; analytics integrations come later.') . '</p></details>';
        return $h;
    }

    private static function report_export_form(int $run_id): string {
        if ($run_id <= 0) { return ''; }
        return '<div class="fi-report-export"><p>' . self::e('Decision Report export is private-beta JSON through fi/v1/reports/export. No public share link is created.') . '</p></div>';
    }

    private static function run_timeline(array $run, array $counts, array $events = []): string {
        $status = (string) ($run['status'] ?? 'not_started');
        $h = '<section class="fi-command-timeline" aria-label="' . self::ea('Run timeline') . '"><p class="fi-breadcrumb">' . self::e('Diagnosis surface') . '</p><h2>' . self::e('Run timeline') . '</h2><div class="fi-timeline-filters" aria-label="' . self::ea('Timeline filters') . '"><span class="fi-status-chip">' . self::e('all events') . '</span><span class="fi-status-chip">' . self::e('warnings') . '</span><span class="fi-status-chip">' . self::e('provider') . '</span><span class="fi-status-chip">' . self::e('review') . '</span></div>';
        foreach (['Created','Provider requested','Provider authenticated','Provider result received','Rows validated','Rows rejected','Rows normalized','Signals created','Evidence created','Insights created','Briefs created','Usage settled/voided','Review pending','Completed / partial / failed'] as $step) {
            $active = ($status === 'completed' && in_array($step, ['Created','Completed / partial / failed'], true));
            $h .= '<span class="fi-timeline-step ' . ($active ? 'is-active' : '') . '">' . self::e($step) . '</span>';
        }
        if (!empty($events)) {
            $h .= '<ul class="fi-run-log-list fi-run-log-list-v2">';
            foreach (array_slice($events, 0, 18) as $ev) {
                $context = is_array($ev['events'][0]['context_preview'] ?? null) ? $ev['events'][0]['context_preview'] : [];
                $ctx = '';
                foreach ($context as $k => $v) { $ctx .= self::e((string) $k . ': ' . (string) $v) . ' '; }
                $h .= '<li class="is-' . self::ea(str_replace(' ', '-', (string) ($ev['status_chip'] ?? 'ok'))) . '"><strong>' . self::e((string) ($ev['stage'] ?? ($ev['event_type'] ?? 'event'))) . '</strong> <span>' . self::e((string) ($ev['last_message'] ?? '')) . ($ctx !== '' ? '<small>' . $ctx . '</small>' : '') . '</span><em>' . self::e((string) ($ev['status_chip'] ?? 'ok') . ' · x' . (string) ($ev['count'] ?? 1)) . '</em></li>';
            }
            $h .= '</ul>';
        }
        $h .= '<div class="fi-run-diagnostics-box"><strong>' . self::e('Why did this run fail?') . '</strong><p>' . self::e('Check authentication, replay/idempotency, provider contract validation, rejected rows, and usage settlement. Raw provider payloads are redacted.') . '</p></div><div class="fi-next-action"><strong>' . self::e('Next recommended action:') . '</strong> ' . self::e('Open Provider Ingestions, resolve rejected rows if present, then continue to Review Queue and Decision Report.') . '</div><p class="fi-empty-state">' . self::e('Provider rows are authenticated, validated and normalized by WordPress. Raw provider payloads and tokens are not shown here.') . '</p></section>';
        return $h;
    }
}

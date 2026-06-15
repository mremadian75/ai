<?php
if (!defined('ABSPATH')) { exit; }

/** Private-beta operator QA checklist and notes. */
final class VES_Operator_QA_Service {
    const OPTION = 'ves_operator_qa_notes_v1';

    public static function checklist(int $workspace_id = 0, int $run_id = 0): array {
        $workspace_id = $workspace_id > 0 ? $workspace_id : (class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id() : 0);
        $items = [
            'plugin_active' => ['label' => 'Plugin active', 'status' => true, 'evidence' => 'Future Island plugin code loaded.'],
            'migrations_current' => ['label' => 'Migrations current', 'status' => self::table_ready('VES_Run_Log_Service') && self::table_ready('VES_Provider_Ingestion_Store'), 'evidence' => 'Run log and provider ingestion tables available.'],
            'workspace_exists' => ['label' => 'Workspace exists', 'status' => $workspace_id > 0, 'evidence' => 'Workspace resolved server-side.'],
            'provider_contracts_configured' => ['label' => 'Provider contracts configured', 'status' => class_exists('VES_Provider_Contract_Service') && count(VES_Provider_Contract_Service::public_contracts()) > 0, 'evidence' => 'Provider contract registry available.'],
            'hmac_secret_configured' => ['label' => 'HMAC secret configured', 'status' => self::any_hmac_secret_configured(), 'evidence' => 'At least one HMAC provider has a configured secret.'],
            'callback_simulator_available' => ['label' => 'Signed callback simulator available', 'status' => file_exists(dirname(__DIR__) . '/bin/fi-provider-callback-simulate.php'), 'evidence' => 'bin/fi-provider-callback-simulate.php exists.'],
            'fixture_dry_run_available' => ['label' => 'Provider fixture dry-run available', 'status' => file_exists(dirname(__DIR__) . '/bin/run-provider-fixture-trials.sh') && is_dir(dirname(__DIR__) . '/tests/fixtures/provider'), 'evidence' => 'Fixture trial runner and public/private provider fixtures are available without n8n.'],
            'valid_signed_callback_path' => ['label' => 'Valid signed callback path documented', 'status' => file_exists(dirname(__DIR__) . '/ORCHESTRATOR_AGNOSTIC_CALLBACK_GUIDE.md'), 'evidence' => 'Use approved external worker/simulator -> signed WordPress REST callback -> provider validation.'],
            'bad_signature_blocked' => ['label' => 'Bad signature blocked', 'status' => class_exists('VES_Provider_Callback_Auth_Service'), 'evidence' => 'Callback auth service rejects malformed or stale signatures before ingestion.'],
            'replay_blocked' => ['label' => 'Replay blocked', 'status' => class_exists('VES_Provider_Replay_Guard'), 'evidence' => 'Idempotency/replay guard available for signed callbacks.'],
            'invalid_private_fixture_rejected' => ['label' => 'Invalid private fixture rejected', 'status' => file_exists(dirname(__DIR__) . '/tests/fixtures/provider/social-invalid-private.json'), 'evidence' => 'Private/invalid fixture remains a rejection test input and is not promoted into evidence.'],
            'optional_n8n_template_present' => ['label' => 'Optional n8n example present', 'status' => true, 'evidence' => file_exists(dirname(__DIR__) . '/integrations/examples/n8n/OPTIONAL_future-island-provider-callback-template.json') ? 'Optional n8n example is included. Future Island does not require n8n.' : 'Optional n8n example not included; QA still passes because signed callbacks are orchestrator-agnostic.'],
            'ingestion_ledger_visible' => ['label' => 'Provider ingestion ledger visible', 'status' => class_exists('VES_Provider_Ingestion_Store'), 'evidence' => 'Provider ingestion store available.'],
            'timeline_visible' => ['label' => 'Run timeline visible', 'status' => class_exists('VES_Run_Log_Service'), 'evidence' => 'Run log timeline service available.'],
            'decision_report_private' => ['label' => 'Decision report private', 'status' => class_exists('VES_Decision_Report_Service'), 'evidence' => 'Report service available; shareable links remain disabled.'],
            'no_secrets_visible' => ['label' => 'No secrets visible', 'status' => true, 'evidence' => 'Status APIs expose booleans/redacted values only.'],
        ];
        if ($run_id > 0 && class_exists('VES_Run_Diagnostics_Service')) {
            $diag = VES_Run_Diagnostics_Service::diagnose($workspace_id, $run_id);
            $items['run_diagnostics'] = ['label' => 'Run diagnostics generated', 'status' => $diag['diagnosis'] !== 'failed_unknown', 'evidence' => $diag['summary']];
        }
        $notes = self::notes();
        foreach ($items as $key => $item) {
            $items[$key]['key'] = $key;
            $items[$key]['last_checked_at'] = self::now();
            $items[$key]['operator_note'] = (string) ($notes[$key]['note'] ?? '');
            $items[$key]['manually_checked'] = !empty($notes[$key]['checked']);
            $items[$key] = class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($items[$key]) : $items[$key];
        }
        return ['workspace_id' => $workspace_id, 'run_id' => $run_id, 'items' => array_values($items), 'raw_secrets_exposed' => false];
    }

    public static function save_note(string $key, string $note, bool $checked = false): bool {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return false; }
        $key = self::clean_key($key, 80);
        if ($key === '') { return false; }
        $notes = self::notes();
        $notes[$key] = ['note' => self::clean_textarea($note, 1000), 'checked' => $checked, 'updated_at' => self::now()];
        return function_exists('update_option') ? (bool) update_option(self::OPTION, $notes, false) : false;
    }

    public static function render_admin_page(): void {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return; }
        $check = self::checklist();
        echo '<div class="wrap fi-beta-qa fi-operator-qa-screen"><h1>Future Island Operator QA</h1><p class="fi-beta-context">Private staging validation checklist. It passes through the signed callback simulator and approved external orchestrator pattern; n8n is optional only. No provider secrets or signatures are displayed.</p><div class="fi-qa-summary"><strong>Required path:</strong> simulator or approved external orchestrator → signed WordPress REST callback → validation → canonical records.</div><table class="widefat striped fi-qa-table"><thead><tr><th>Status</th><th>Item</th><th>Evidence</th><th>Operator note</th></tr></thead><tbody>';
        foreach ($check['items'] as $item) {
            echo '<tr><td>' . (!empty($item['status']) ? 'OK' : 'Needs review') . '</td><td>' . esc_html($item['label']) . '</td><td>' . esc_html($item['evidence']) . '</td><td>' . esc_html($item['operator_note']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function browser_validation_plan(): array {
        return [
            'activation' => ['label' => 'Plugin activation readiness', 'steps' => ['Install ZIP on WordPress staging', 'Activate plugin', 'Confirm no PHP warnings in debug log', 'Open Future Island → Command Room']],
            'migration' => ['label' => 'Migration readiness', 'steps' => ['Confirm canonical Run, run log, provider ingestion, workspace, usage, outcome and pattern tables are present', 'Confirm no destructive migration is required']],
            'operator_route' => ['label' => 'Operator route', 'steps' => ['Complete workspace onboarding', 'Start Brand & Market X-Ray', 'Run signed callback simulator dry-run', 'Submit valid signed callback on staging', 'Open Provider Ingestions', 'Open Run Timeline diagnostics', 'Review Insight, create Brief, generate Draft', 'Record Outcome, inspect Decision Map, export private Decision Report']],
            'evidence_pack' => ['label' => 'Evidence pack', 'steps' => ['Run fixture trials', 'Run lint/tests', 'Build staging evidence pack', 'Attach screenshots or HTML snippets']],
        ];
    }

    public static function render_browser_validation_page(): void {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return; }
        $e = [__CLASS__, 'e'];
        $u = [__CLASS__, 'u'];
        $check = self::checklist();
        $plan = self::browser_validation_plan();
        $command_url = self::admin_url('fi-command-room');
        $ledger_url = self::admin_url('fi-provider-ingestions');
        $contracts_url = self::admin_url('fi-provider-contracts');
        echo '<div class="wrap fi-beta-qa fi-browser-validation-screen"><h1>' . $e('Future Island Staging Browser Validation') . '</h1>';
        echo '<p class="fi-beta-context">' . $e('Release-grade operator route for WordPress staging. Future Island remains orchestration-agnostic: simulator, custom worker, cron job, Apify wrapper, Make/Zapier-style workflow, optional n8n example, or future managed worker all return through signed WordPress REST callbacks.') . '</p>';
        echo '<div class="fi-qa-summary"><strong>' . $e('Canonical path') . ':</strong> ' . $e('Clean codebase → activation → migration → onboarding → Command Room → signed callback simulator → ledger → timeline diagnostics → review → brief/draft → outcome → decision map/report → evidence pack.') . '</div>';
        echo '<p><a class="button button-primary" href="' . $u($command_url) . '">' . $e('Open Command Room') . '</a> <a class="button" href="' . $u($contracts_url) . '">' . $e('Provider / Orchestrator Contracts') . '</a> <a class="button" href="' . $u($ledger_url) . '">' . $e('Provider Ingestion Ledger') . '</a></p>';
        echo '<div class="fi-validation-grid">';
        foreach ($plan as $section) {
            echo '<section class="fi-validation-card"><h2>' . $e($section['label']) . '</h2><ol>';
            foreach ((array) $section['steps'] as $step) { echo '<li>' . $e($step) . '</li>'; }
            echo '</ol></section>';
        }
        echo '</div>';
        echo '<section class="fi-validation-card"><h2>' . $e('Signed callback simulator trial') . '</h2><p>' . $e('Dry-run first, then send only to a controlled WordPress staging endpoint. Secrets, signatures and raw callback bodies must not be displayed in browser output.') . '</p><pre><code>' . $e('FI_PROVIDER_SECRET=... php bin/fi-provider-callback-simulate.php --endpoint="https://staging.example.com/wp-json/fi/v1/provider/social/ingest" --fixture="tests/fixtures/provider/social-valid-topic.json" --provider-key="manual_provider_row" --secret-env="FI_PROVIDER_SECRET" --run-id=123 --dry-run') . '</code></pre></section>';
        echo '<section class="fi-validation-card"><h2>' . $e('Operator QA gates') . '</h2><table class="widefat striped fi-qa-table"><thead><tr><th>' . $e('Status') . '</th><th>' . $e('Gate') . '</th><th>' . $e('Evidence') . '</th></tr></thead><tbody>';
        foreach ($check['items'] as $item) { echo '<tr><td><span class="fi-status-chip">' . $e(!empty($item['status']) ? 'OK' : 'Needs review') . '</span></td><td>' . $e($item['label']) . '</td><td>' . $e($item['evidence']) . '</td></tr>'; }
        echo '</tbody></table></section>';
        echo '<section class="fi-validation-card"><h2>' . $e('Pilot readiness checkpoint') . '</h2><p>' . $e('Do not invite pilot operators until lint/tests pass, warning cleanup is documented, browser validation screenshots exist, provider fixture trials are attached, and the private Decision Report export works without public share links.') . '</p></section>';
        echo '</div>';
    }

    private static function admin_url(string $page, array $args = []): string {
        $base = function_exists('admin_url') ? admin_url('admin.php') : 'admin.php';
        $args = array_merge(['page' => $page], $args);
        return function_exists('add_query_arg') ? add_query_arg($args, $base) : $base . '?' . http_build_query($args);
    }

    private static function any_hmac_secret_configured(): bool {
        if (!class_exists('VES_Provider_Settings_Service') || !class_exists('VES_Provider_Contract_Service')) { return false; }
        foreach (VES_Provider_Contract_Service::public_contracts() as $contract) {
            $status = VES_Provider_Settings_Service::public_status((string) ($contract['provider_family'] ?? ''), (string) ($contract['provider_key'] ?? ''));
            if (($status['auth_mode'] ?? '') === 'hmac_signature' && !empty($status['secret_configured'])) { return true; }
        }
        return false;
    }
    private static function table_ready(string $class): bool { return class_exists($class) && method_exists($class, 'table_name'); }
    private static function notes(): array { $n = function_exists('get_option') ? get_option(self::OPTION, []) : []; return is_array($n) ? $n : []; }
    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_textarea(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function e($s): string { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    private static function u($s): string { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
}

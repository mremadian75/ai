<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Release_Candidate_Page — v0.1 RC read-only diagnostic page.
 *
 * Admin page "Future Island / Release Candidate": build status, feature flags,
 * readiness checks, the live commands an operator must run, required screenshots,
 * known limitations and the next operator action. STRICTLY read-only — no
 * mutation, no provider calls, no fake pass states, and it can never display a
 * production-ready badge: until a live validation pass is recorded the page
 * always says NOT production-ready. PHP 7.4 compatible.
 */
final class VES_Release_Candidate_Page {

    const PAGE_SLUG = 'ves-release-candidate';

    public static function init() {
        if (function_exists('add_action')) {
            add_action('admin_menu', [__CLASS__, 'register_menu'], 30);
        }
    }

    public static function register_menu() {
        if (!function_exists('add_submenu_page')) { return; }
        $parent = class_exists('VES_Admin_Console') ? VES_Admin_Console::PARENT_SLUG : 'ves-intelligence-suite';
        add_submenu_page($parent, 'Release Candidate', 'Release Candidate', 'manage_options', self::PAGE_SLUG, [__CLASS__, 'render']);
    }

    private static function e($s)  { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function ea($s) { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    public static function render() {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            if (function_exists('wp_die')) { wp_die(self::e('Insufficient capability')); }
            return;
        }
        echo self::render_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built exclusively from escaped parts below.
    }

    /** Build the full page HTML (separated for tests). All dynamic values escaped. */
    public static function render_html() {
        $report = class_exists('VES_RC_Readiness_Service')
            ? VES_RC_Readiness_Service::report()
            : ['status' => 'blocked', 'blockers' => ['VES_RC_Readiness_Service unavailable.'], 'warnings' => [], 'checks' => [], 'live_validation' => ['status' => 'unrun', 'note' => ''], 'plugin_version' => defined('FIS_VERSION') ? FIS_VERSION : 'unknown', 'rc_label' => defined('FIS_RC_LABEL') ? FIS_RC_LABEL : '', 'required_cli' => [], 'checked_at' => gmdate('Y-m-d H:i:s'), 'production_ready' => false];

        $live = is_array($report['live_validation'] ?? null) ? $report['live_validation'] : ['status' => 'unrun'];
        $live_passed = ($live['status'] ?? '') === 'passed';
        $status = (string) ($report['status'] ?? 'blocked');

        $h  = self::styles();
        $h .= '<div class="wrap fiis-rc-page"><div class="fiis-rc-inner">';

        // Header — editorial heading + mono metadata, no fake green.
        $h .= '<header class="fiis-rc-head">';
        $h .= '<p class="fiis-rc-kicker">Future Island · Intelligence Suite</p>';
        $h .= '<h1>Release Candidate</h1>';
        $h .= '<p class="fiis-rc-meta">version ' . self::e((string) ($report['plugin_version'] ?? '')) . ((string) ($report['rc_label'] ?? '') !== '' ? ' · ' . self::e((string) $report['rc_label']) : '') . ' · checked ' . self::e((string) ($report['checked_at'] ?? '')) . ' UTC</p>';
        $h .= '</header>';

        // Honest top banner.
        $missing_for_trust = is_array($live['missing_for_trust'] ?? null) ? $live['missing_for_trust'] : [];
        $missing_html = '';
        if (count($missing_for_trust) > 0) {
            $missing_html = ' Missing for trust: ';
            $parts = [];
            foreach ($missing_for_trust as $m) { $parts[] = self::e((string) $m); }
            $missing_html .= implode(' · ', $parts) . '.';
        }
        if (($live['status'] ?? '') === 'json_only_unverified') {
            $h .= '<div class="fiis-rc-banner fiis-rc-banner-warn"><strong>Evidence pack recorded WITHOUT file verification (json_only_unverified).</strong> '
                . 'The pack JSON exists but its artifact files (command outputs, screenshots, logs) were never verified on disk, so it is NOT trusted as passed.'
                . $missing_html
                . ' Re-record with <span class="fiis-rc-mono">wp ves rc-record-live-validation --evidence-pack=… --evidence-root=…</span>. '
                . 'This build remains <strong>NOT production-ready</strong>.</div>';
        } elseif (($live['status'] ?? '') === 'unverified_manual') {
            $h .= '<div class="fiis-rc-banner fiis-rc-banner-warn"><strong>Unverified manual validation record.</strong> '
                . 'A live-validation option exists but carries no verifiable evidence pack hash, so it is NOT trusted as passed. '
                . 'Re-run the validation script and record through <span class="fiis-rc-mono">wp ves rc-record-live-validation --evidence-pack=…</span>. '
                . 'This build remains <strong>NOT production-ready</strong>.</div>';
        } elseif (!$live_passed) {
            $h .= '<div class="fiis-rc-banner fiis-rc-banner-warn"><strong>Live staging validation: UNRUN.</strong> '
                . 'This build is statically verified only. It is <strong>NOT production-ready</strong> and must not be installed on a production site. '
                . 'Run the commands below on a staging copy first.' . $missing_html . '</div>';
        } else {
            $h .= '<div class="fiis-rc-banner fiis-rc-banner-info"><strong>Live staging validation recorded as passed</strong> ('
                . self::e((string) ($live['recorded_at'] ?? '')) . ') via evidence pack '
                . '<span class="fiis-rc-mono">' . self::e(substr((string) ($live['evidence_pack_hash'] ?? ''), 0, 16)) . '…</span>'
                . ((string) ($live['schema_version'] ?? '') !== '' ? ' (schema ' . self::e((string) $live['schema_version']) . ', file-backed: ' . (!empty($live['files_verified']) ? 'yes' : 'NO') . ')' : '') . '. '
                . 'Verify the operator evidence (command outputs + screenshots). '
                . 'Even with a recorded pass, production release still requires operator approval and monitored pilot usage — this page never grants production status.</div>';
        }

        // Build status.
        $h .= '<section class="fiis-rc-section"><h2>Build status</h2>';
        $h .= '<p>Overall: ' . self::badge($status) . ' &nbsp; Production-ready: ' . self::badge('blocked', 'No') . '</p>';
        if (!empty($report['blockers'])) {
            $h .= '<ul class="fiis-rc-list fiis-rc-blockers">';
            foreach ((array) $report['blockers'] as $b) { $h .= '<li>' . self::e((string) $b) . '</li>'; }
            $h .= '</ul>';
        }
        if (!empty($report['warnings'])) {
            $h .= '<ul class="fiis-rc-list fiis-rc-warnings">';
            foreach ((array) $report['warnings'] as $w) { $h .= '<li>' . self::e((string) $w) . '</li>'; }
            $h .= '</ul>';
        }
        $h .= '</section>';

        // Readiness checks table.
        $h .= '<section class="fiis-rc-section"><h2>Readiness checks</h2><table class="fiis-rc-table"><thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
        foreach ((array) ($report['checks'] ?? []) as $c) {
            $h .= '<tr><td>' . self::e((string) ($c['label'] ?? '')) . '</td><td>' . self::badge((string) ($c['status'] ?? 'unknown')) . '</td><td>' . self::e((string) ($c['detail'] ?? '')) . '</td></tr>';
        }
        $h .= '</tbody></table></section>';

        // Feature flags.
        $h .= '<section class="fiis-rc-section"><h2>Feature flags (must stay OFF for v0.1)</h2><table class="fiis-rc-table"><tbody>';
        foreach (self::flags() as $flag) {
            $h .= '<tr><td class="fiis-rc-mono">' . self::e($flag['name']) . '</td><td>' . self::badge($flag['on'] ? 'warning' : 'ready', $flag['on'] ? 'ON' : 'OFF') . '</td><td>' . self::e($flag['note']) . '</td></tr>';
        }
        $h .= '</tbody></table><p class="fiis-rc-note">AI provider execution stays disabled. There is no generate, publish or auto-approve behavior anywhere in this build.</p></section>';

        // Required live commands.
        $h .= '<section class="fiis-rc-section"><h2>Live validation commands (staging only)</h2><p>Run on a staging copy with a fresh DB backup. Never run <span class="fiis-rc-mono">--apply</span> during validation.</p><ol class="fiis-rc-cmds">';
        foreach ((array) ($report['required_cli'] ?? []) as $cmd) {
            $full = 'wp ' . (string) $cmd;
            $h .= '<li><span class="fiis-rc-mono">' . self::e($full) . '</span> '
                . '<button type="button" class="fiis-rc-copy" data-fiis-copy="' . self::ea($full) . '" aria-label="' . self::ea('Copy command: ' . $full) . '">Copy</button></li>';
        }
        $h .= '</ol><p class="fiis-rc-note">Full procedure: <span class="fiis-rc-mono">RELEASE-CANDIDATE-RUNBOOK.md</span> and <span class="fiis-rc-mono">LIVE-STAGING-VALIDATION-CHECKLIST.md</span> in the plugin folder.</p></section>';

        // Required screenshots.
        $h .= '<section class="fiis-rc-section"><details class="fiis-rc-details"><summary><h2>Required browser evidence</h2></summary><ul class="fiis-rc-list">';
        foreach (['Signal Room', 'Social Media / Signal Report', 'Evidence Gate (blocked state)', 'Operator Queue', 'Memory / Brand Context', 'Generation Context Preview', 'Prompt Package Preview', 'Brief Workbench', 'Draft Workbench', 'Release Candidate page (this page)', 'Any error state encountered'] as $shot) {
            $h .= '<li>' . self::e($shot) . '</li>';
        }
        $h .= '</ul></details></section>';

        // Phase 9 — audit & rails diagnostics (read-only, escaped, no fake green).
        $h .= self::rails_diagnostics_section();

        // Known limitations — honest by design.
        $h .= '<section class="fiis-rc-section"><details class="fiis-rc-details"><summary><h2>Known limitations</h2></summary><ul class="fiis-rc-list">';
        foreach ([
            'Live staging validation has not been performed unless explicitly recorded above — static checks cannot replace it.',
            'AI generation is a preview contract only (prompt packages); no provider execution path is enabled.',
            'Brief/Draft review controls are disabled where backend handlers are not yet wired — by design, not by accident.',
            'Usage costs for OpenAI are estimates where the provider does not return final pricing; they are labeled as estimates.',
            'This is a v0.1 pilot build, not a mature production SaaS.',
        ] as $lim) {
            $h .= '<li>' . self::e($lim) . '</li>';
        }
        $h .= '</ul></details></section>';

        // Next operator action.
        $next = $status === 'blocked'
            ? 'Resolve the blockers listed above, then re-run wp ves rc-readiness-check.'
            : (!$live_passed
                ? 'Install this build on STAGING, follow LIVE-STAGING-VALIDATION-CHECKLIST.md, capture command outputs and screenshots, then record the result.'
                : 'Review the recorded live evidence with the pilot owner and decide on a monitored pilot — production release still needs explicit operator approval.');
        $h .= '<section class="fiis-rc-section fiis-rc-next"><h2>Next operator action</h2><p>' . self::e($next) . '</p></section>';

        $h .= '</div></div>';
        $h .= self::copy_script();
        return $h;
    }

    /**
     * UI/UX upgrade — dependency-free copy-to-clipboard for the staging command
     * list. Inline, no external src, no form, no mutation; falls back to a text
     * selection prompt when the async Clipboard API is unavailable.
     */
    private static function copy_script() {
        return '<script>(function(){'
            . 'document.addEventListener("click",function(e){'
            . 'var b=e.target&&e.target.closest?e.target.closest(".fiis-rc-copy"):null;'
            . 'if(!b)return;var t=b.getAttribute("data-fiis-copy")||"";'
            . 'var done=function(){var o=b.textContent;b.textContent="Copied";b.classList.add("is-copied");'
            . 'setTimeout(function(){b.textContent=o;b.classList.remove("is-copied");},1400);};'
            . 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done,function(){window.prompt("Copy the command:",t);});}'
            . 'else{window.prompt("Copy the command:",t);}'
            . '});'
            . '})();</script>';
    }

    /**
     * Phase 9 — read-only audit & rails diagnostics: recent review decisions
     * (append-only ledger), dead-lettered jobs and the security-event summary.
     * Honest empty states; everything escaped; no mutation controls.
     */
    private static function rails_diagnostics_section() {
        $h = '<section class="fiis-rc-section"><h2>Audit &amp; rails diagnostics</h2>';

        // Review decision ledger (append-only) — newest first.
        $h .= '<h3 class="fiis-rc-subhead">Recent review decisions</h3>';
        if (class_exists('VES_Review_Decision_Ledger') && method_exists('VES_Review_Decision_Ledger', 'recent')) {
            $decisions = [];
            try { $decisions = VES_Review_Decision_Ledger::recent(['limit' => 10]); } catch (\Throwable $e) { $decisions = []; }
            if (empty($decisions)) {
                $h .= '<p class="fiis-rc-note">No review decisions recorded yet. The ledger is append-only; every approve/reject/archive/pin will appear here.</p>';
            } else {
                $h .= '<table class="fiis-rc-table"><thead><tr><th>When (UTC)</th><th>Object</th><th>Decision</th><th>Transition</th><th>Actor</th></tr></thead><tbody>';
                foreach ($decisions as $d) {
                    $h .= '<tr><td class="fiis-rc-mono">' . self::e((string) ($d['created_at'] ?? '')) . '</td>'
                        . '<td class="fiis-rc-mono">' . self::e((string) ($d['object_type'] ?? '') . ' #' . (int) ($d['object_id'] ?? 0)) . '</td>'
                        . '<td>' . self::e((string) ($d['decision'] ?? '')) . '</td>'
                        . '<td class="fiis-rc-mono">' . self::e((string) ($d['from_status'] ?? '?') . ' → ' . (string) ($d['to_status'] ?? '?')) . '</td>'
                        . '<td class="fiis-rc-mono">#' . self::e((string) (int) ($d['actor_user_id'] ?? 0)) . '</td></tr>';
                }
                $h .= '</tbody></table>';
            }
        } else {
            $h .= '<p class="fiis-rc-note">Review decision ledger unavailable on this install.</p>';
        }

        // Dead-letter rails.
        $h .= '<h3 class="fiis-rc-subhead">Queue dead letters</h3>';
        if (class_exists('VES_Job_Rails') && method_exists('VES_Job_Rails', 'dead_letters')) {
            $dead = [];
            try { $dead = VES_Job_Rails::dead_letters(5); } catch (\Throwable $e) { $dead = []; }
            if (empty($dead)) {
                $h .= '<p class="fiis-rc-note">No dead-lettered jobs. Background jobs retry up to ' . (int) VES_Job_Rails::MAX_RETRIES . ' times, then land here.</p>';
            } else {
                $h .= '<ul class="fiis-rc-list fiis-rc-warnings">';
                foreach ($dead as $row) {
                    $h .= '<li><span class="fiis-rc-mono">' . self::e((string) ($row['job_type'] ?? '')) . '</span> — '
                        . self::e((string) ($row['reason'] ?? '')) . ' <span class="fiis-rc-mono">(' . self::e((string) ($row['created_at'] ?? '')) . ')</span></li>';
                }
                $h .= '</ul>';
            }
        } else {
            $h .= '<p class="fiis-rc-note">Dead-letter rails unavailable on this install.</p>';
        }

        // Security event summary (counts only; contents stay in the scrubbed log).
        $h .= '<h3 class="fiis-rc-subhead">Security events</h3>';
        if (class_exists('VES_Security_Event_Log') && method_exists('VES_Security_Event_Log', 'summary')) {
            $sum = ['total' => 0, 'by_type' => []];
            try { $sum = VES_Security_Event_Log::summary(); } catch (\Throwable $e) { /* keep zeros */ }
            if ((int) ($sum['total'] ?? 0) === 0) {
                $h .= '<p class="fiis-rc-note">No security events recorded. Blocked dispatches, blocked transitions and workspace mismatches will appear here (scrubbed).</p>';
            } else {
                $h .= '<p class="fiis-rc-note">' . self::e((string) (int) $sum['total']) . ' scrubbed event(s):</p><ul class="fiis-rc-list">';
                foreach ((array) ($sum['by_type'] ?? []) as $type => $count) {
                    $h .= '<li><span class="fiis-rc-mono">' . self::e((string) $type) . '</span>: ' . self::e((string) (int) $count) . '</li>';
                }
                $h .= '</ul>';
            }
        } else {
            $h .= '<p class="fiis-rc-note">Security event log unavailable on this install.</p>';
        }

        $h .= '</section>';
        return $h;
    }

    private static function flags() {
        // Prefer the builder's view of the flag (it applies filters); fall back to
        // the raw option so a missing builder can never make an ON flag look OFF.
        $exec_on = function_exists('get_option') ? (bool) get_option('ves_generation_execution_enabled', false) : false;
        if (class_exists('VES_Generation_Prompt_Package_Builder')) {
            try { $exec_on = (bool) VES_Generation_Prompt_Package_Builder::execution_enabled(); } catch (\Throwable $e) { /* keep option value */ }
        }
        return [
            ['name' => 'ves_generation_execution_enabled', 'on' => $exec_on, 'note' => 'AI provider execution for generation. Must stay OFF for v0.1.'],
            ['name' => 'VES_PRODUCTION_MVP', 'on' => defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP, 'note' => 'Production MVP mode constant.'],
            ['name' => 'VES_ENABLE_DEEP_VIDEO_ANALYSIS', 'on' => defined('VES_ENABLE_DEEP_VIDEO_ANALYSIS') && VES_ENABLE_DEEP_VIDEO_ANALYSIS, 'note' => 'Deep video analysis (core).'],
            ['name' => 'FI_DTF_ENABLE_DEEP_VIDEO', 'on' => defined('FI_DTF_ENABLE_DEEP_VIDEO') && FI_DTF_ENABLE_DEEP_VIDEO, 'note' => 'Deep video analysis (Deep Trend Finder module).'],
        ];
    }

    private static function badge($state, $label = null) {
        // Map readiness tokens onto the canonical review-state vocabulary so the
        // badge colors are honest (ok=ready, warn=warning, block/blocked=blocked).
        $map = [
            'ok' => 'ready', 'warn' => 'warning', 'block' => 'blocked',
            'ready_for_staging' => 'ready', 'ready_with_warnings' => 'warning', 'blocked' => 'blocked',
        ];
        $raw = strtolower((string) $state);
        $mapped = isset($map[$raw]) ? $map[$raw] : $raw;
        if ($label === null && isset($map[$raw]) && strpos($raw, '_') !== false) { $label = str_replace('_', ' ', $raw); }
        if (class_exists('VES_Review_State')) { return VES_Review_State::badge($mapped, $label); }
        $text = $label !== null ? (string) $label : (string) $mapped;
        return '<span class="fi-status-badge">' . self::e($text) . '</span>';
    }

    /** Static scoped styles — FI palette (ink/paper/sand/blue + tactical lime/red). */
    private static function styles() {
        return '<style id="fiis-rc-styles">'
            . '.fiis-rc-page{--fi-ink:#15161a;--fi-paper:#f3efe6;--fi-sand:#e7e1d4;--fi-blue:#2f5fd0;--fi-blue-2:#1b2a4a;--fi-lime:#7faa00;--fi-red:#e8552b;--fi-bdr:#d9d2c3;--fi-muted:#6c6a62;background:var(--fi-paper);margin-left:-20px;padding:28px 32px;min-height:100%}'
            . '.fiis-rc-inner{max-width:980px}'
            . '.fiis-rc-head h1{font-size:34px;line-height:1.1;margin:2px 0 6px;color:var(--fi-ink);font-weight:800;letter-spacing:-.01em}'
            . '.fiis-rc-kicker{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--fi-blue);margin:0}'
            . '.fiis-rc-meta,.fiis-rc-mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:var(--fi-muted)}'
            . '.fiis-rc-banner{border:1px solid var(--fi-bdr);border-left:4px solid var(--fi-red);background:#fff;padding:12px 16px;margin:18px 0;color:var(--fi-ink);max-width:980px}'
            . '.fiis-rc-banner-info{border-left-color:var(--fi-blue)}'
            . '.fiis-rc-section{background:#fff;border:1px solid var(--fi-bdr);padding:18px 20px;margin:0 0 16px}'
            . '.fiis-rc-section h2{font-size:15px;text-transform:uppercase;letter-spacing:.08em;color:var(--fi-blue-2);margin:0 0 10px;border-bottom:1px solid var(--fi-sand);padding-bottom:8px}'
            . '.fiis-rc-table{width:100%;border-collapse:collapse}'
            . '.fiis-rc-table th{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--fi-muted);text-align:left;padding:6px 10px;border-bottom:1px solid var(--fi-bdr)}'
            . '.fiis-rc-table td{padding:8px 10px;border-bottom:1px solid var(--fi-sand);vertical-align:top;color:var(--fi-ink);word-break:break-word}'
            . '.fiis-rc-list li{margin:4px 0;color:var(--fi-ink)}'
            . '.fiis-rc-blockers li{color:var(--fi-red)}'
            . '.fiis-rc-warnings li{color:#b06a00}'
            . '.fiis-rc-cmds li{margin:4px 0;color:var(--fi-ink);font-size:12px}'
            . '.fiis-rc-note{color:var(--fi-muted);font-size:12px;margin-top:10px}'
            . '.fiis-rc-subhead{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--fi-muted);margin:16px 0 6px}'
            . '.fiis-rc-next{border-left:4px solid var(--fi-lime)}'
            . '.fiis-rc-page .fi-status-badge{display:inline-block;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;letter-spacing:.04em;text-transform:uppercase;padding:2px 8px;border-radius:999px;border:1px solid var(--fi-bdr);background:#fff;color:var(--fi-ink);white-space:nowrap}'
            . '.fiis-rc-page .fiis-badge-ready,.fiis-rc-page .fiis-badge-approved{background:rgba(31,122,77,.12);border-color:#1f7a4d;color:#1f7a4d}'
            . '.fiis-rc-page .fiis-badge-warning,.fiis-rc-page .fiis-badge-needs_review{background:rgba(176,106,0,.12);border-color:#b06a00;color:#b06a00}'
            . '.fiis-rc-page .fiis-badge-blocked,.fiis-rc-page .fiis-badge-missing{background:rgba(232,85,43,.12);border-color:var(--fi-red);color:var(--fi-red)}'
            . '.fiis-rc-copy{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:10px;text-transform:uppercase;letter-spacing:.06em;margin-left:8px;padding:2px 8px;border:1px solid var(--fi-bdr);border-radius:999px;background:#fff;color:var(--fi-blue);cursor:pointer}'
            . '.fiis-rc-copy:hover{border-color:var(--fi-blue)}'
            . '.fiis-rc-copy.is-copied{border-color:var(--fi-lime);color:#5c7d00}'
            . '.fiis-rc-details>summary{cursor:pointer;list-style:none}'
            . '.fiis-rc-details>summary::-webkit-details-marker{display:none}'
            . '.fiis-rc-details>summary h2{display:inline-block;margin:0 0 10px}'
            . '.fiis-rc-details>summary::after{content:" ▸";color:var(--fi-muted);font-size:12px}'
            . '.fiis-rc-details[open]>summary::after{content:" ▾"}'
            . '.fiis-rc-page a:focus-visible,.fiis-rc-page button:focus-visible,.fiis-rc-page summary:focus-visible{outline:2px solid var(--fi-blue);outline-offset:2px}'
            . '@media (prefers-color-scheme:dark){'
            . '.fiis-rc-page{--fi-ink:#ece8dd;--fi-paper:#16171b;--fi-sand:#262830;--fi-bdr:#383a44;--fi-muted:#a8a496;--fi-blue:#7d9ff0;--fi-blue-2:#aebfe8;--fi-red:#f0744e;--fi-lime:#a4cc33;background:var(--fi-paper);color:var(--fi-ink)}'
            . '.fiis-rc-page .fiis-rc-section,.fiis-rc-page .fiis-rc-banner{background:#1d1e24;color:var(--fi-ink)}'
            . '.fiis-rc-page .fiis-rc-table td{color:var(--fi-ink)}'
            . '.fiis-rc-page .fi-status-badge,.fiis-rc-page .fiis-rc-copy{background:#1d1e24;color:var(--fi-ink)}'
            . '}'
            . '@media (max-width:782px){.fiis-rc-page{margin-left:-10px;padding:16px}.fiis-rc-head h1{font-size:26px}.fiis-rc-table td,.fiis-rc-table th{padding:6px}}'
            . '</style>';
    }
}

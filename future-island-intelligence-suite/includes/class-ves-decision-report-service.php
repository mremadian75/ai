<?php
if (!defined('ABSPATH')) { exit; }

/** Private-beta Decision Report builder for one canonical Run. */
final class VES_Decision_Report_Service {
    public static function build_for_run(int $workspace_id, int $run_id): array {
        $run = class_exists('VES_Run_Service') ? VES_Run_Service::get_run($run_id) : null;
        if (!is_array($run) || (int) ($run['workspace_id'] ?? 0) !== $workspace_id) { return []; }
        $sources = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_sources(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 100]) : [];
        $signals = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_signals(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 100]) : [];
        $evidence = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_evidence(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 100]) : [];
        $insights = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_insights(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 100]) : [];
        $briefs = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_briefs(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 100]) : [];
        $drafts = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_drafts(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 100]) : [];
        $memory = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_memory_records(['workspace_id' => $workspace_id, 'limit' => 30]) : [];
        $edges = class_exists('VES_Decision_Edge_Service') ? VES_Decision_Edge_Service::list_edges_for_run($workspace_id, $run_id) : [];
        $logs = class_exists('VES_Run_Log_Service') ? VES_Run_Log_Service::list_for_run($workspace_id, $run_id) : [];
        $timeline = class_exists('VES_Run_Log_Service') ? VES_Run_Log_Service::timeline_for_run($workspace_id, $run_id) : [];
        $ingestions = class_exists('VES_Provider_Ingestion_Store') ? VES_Provider_Ingestion_Store::list(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 50]) : [];
        $diagnostics = class_exists('VES_Run_Diagnostics_Service') ? VES_Run_Diagnostics_Service::diagnose($workspace_id, $run_id) : [];
        $qa = class_exists('VES_Operator_QA_Service') ? VES_Operator_QA_Service::checklist($workspace_id, $run_id) : [];
        $families = [];
        foreach ($sources as $s) { $families[(string) ($s['source_type'] ?? 'other')] = true; }
        foreach ($evidence as $e) { $m = is_array($e['metadata'] ?? null) ? $e['metadata'] : []; if (!empty($m['source_family'])) { $families[(string) $m['source_family']] = true; } }
        $weak = 0; foreach ($evidence as $e) { if (in_array((string) ($e['evidence_type'] ?? ''), ['weak','missing','contradicting'], true)) { $weak++; } }
        $report = [
            'run_id' => $run_id,
            'workspace_id' => $workspace_id,
            'executive_summary' => 'Private-beta Decision Report: evidence, lineage, provider logs, review state, usage and next actions for this run.',
            'source_coverage' => array_values(array_keys($families)),
            'counts' => ['sources' => count($sources), 'signals' => count($signals), 'evidence' => count($evidence), 'weak_evidence' => $weak, 'insights' => count($insights), 'briefs' => count($briefs), 'drafts' => count($drafts), 'memory_records' => count($memory), 'decision_edges' => count($edges), 'run_logs' => count($logs)],
            'insights' => self::summaries($insights, 'title', 'summary'),
            'briefs' => self::summaries($briefs, 'title', 'objective'),
            'drafts' => self::summaries($drafts, 'title', 'body'),
            'timeline' => $timeline,
            'run_diagnostics' => $diagnostics,
            'provider_ingestions' => self::ingestion_summaries($ingestions),
            'operator_qa_summary' => self::qa_summary($qa),
            'evidence_appendix' => self::summaries($evidence, 'source_label', 'text'),
            'weak_evidence_section' => $weak > 0 ? 'Proof needed: one or more evidence records are weak, missing, or contradicting. Treat related claims as assumptions until reviewed.' : 'No weak evidence flags detected in this run snapshot.',
            'usage_note' => 'Usage is run-linked through canonical zero-cost or billing events when recorded. Provider beta ingestion records zero-cost usage unless paid reservation is explicitly introduced.',
            'memory_note' => 'Memory is context, not evidence; candidate memories require review before trusted reuse.',
            'privacy_note' => 'Provider raw payloads and tokens are redacted and are not exposed in this report.',
            'next_actions' => ['Review draft insights', 'Approve or revise a brief', 'Record outcome after client/channel feedback', 'Promote repeated learnings into pattern observations only after support accumulates'],
        ];
        return class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($report) : $report;
    }

    public static function render_private_html(array $report): string { return self::render_html($report); }

    public static function render_html(array $report): string {
        $e = static function($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); };
        if (empty($report)) { return '<p class="fi-empty-state">' . $e('Decision report unavailable for this run.') . '</p>'; }
        $h = '<section class="fi-decision-report fi-report-preview" aria-label="' . $e('Decision Report preview') . '"><p class="fi-private-beta-label">' . $e('Private beta · workspace-scoped report · no public share link') . '</p><h2>' . $e('Decision Report') . '</h2>';
        $h .= '<div class="fi-report-actions"><button type="button" class="button" disabled>' . $e('HTML preview') . '</button><button type="button" class="button" disabled>' . $e('Markdown export') . '</button><button type="button" class="button" disabled>' . $e('Private JSON export') . '</button></div>';
        $h .= '<section class="fi-report-section"><h3>' . $e('1. Executive summary') . '</h3><p>' . $e((string) ($report['executive_summary'] ?? '')) . '</p></section>';
        $h .= '<section class="fi-report-section"><h3>' . $e('2. Source coverage') . '</h3><p>' . $e(implode(', ', (array) ($report['source_coverage'] ?? []))) . '</p></section>';
        $h .= '<section class="fi-report-section"><h3>' . $e('3. Key signals and evidence summary') . '</h3><div class="fi-report-counts">';
        foreach ((array) ($report['counts'] ?? []) as $k => $v) { $h .= '<span><strong>' . $e((string) $v) . '</strong> ' . $e(str_replace('_', ' ', (string) $k)) . '</span>'; }
        $h .= '</div></section>';
        foreach (['insights' => '4. Insights', 'briefs' => '6. Brief outputs', 'drafts' => '7. Draft outputs'] as $key => $title) {
            $h .= '<section class="fi-report-section"><h3>' . $e($title) . '</h3><ul class="fi-command-list">';
            foreach (array_slice((array) ($report[$key] ?? []), 0, 5) as $item) { $h .= '<li><strong>' . $e((string) ($item['title'] ?? '')) . '</strong><span>' . $e((string) ($item['summary'] ?? '')) . '</span></li>'; }
            if (empty($report[$key])) { $h .= '<li class="fi-empty-state">' . $e('No records yet.') . '</li>'; }
            $h .= '</ul></section>';
        }
        $h .= '<section class="fi-report-section"><h3>' . $e('5. Review state') . '</h3><p>' . $e('Human approval/rejection remains append-only. AI or provider outputs are candidates until reviewed.') . '</p></section>';
        $h .= '<section class="fi-report-section"><h3>' . $e('8. Usage summary') . '</h3><p>' . $e((string) ($report['usage_note'] ?? '')) . '</p></section>';
        $h .= '<section class="fi-report-section"><h3>' . $e('9. Outcome receipts') . '</h3><p>' . $e('Manual outcome capture is available after draft/brief review.') . '</p></section>';
        $h .= '<section class="fi-report-section"><h3>' . $e('10. Pattern observations') . '</h3><p>' . $e('Pattern observations remain low-confidence until repeated review/outcome support accumulates.') . '</p></section>';
        $h .= '<section class="fi-report-section"><h3>' . $e('11. Timeline summary') . '</h3><ul class="fi-run-log-list">';
        foreach (array_slice((array) ($report['timeline'] ?? []), 0, 8) as $ev) { $h .= '<li><strong>' . $e((string) ($ev['event_type'] ?? 'event')) . '</strong><span>' . $e((string) ($ev['last_message'] ?? '')) . '</span><em>' . $e('x' . (string) ($ev['count'] ?? 0)) . '</em></li>'; }
        if (empty($report['timeline'])) { $h .= '<li class="fi-empty-state">' . $e('No timeline events yet.') . '</li>'; }
        $h .= '</ul></section>';
        $h .= '<section class="fi-report-section fi-proof-needed-box"><h3>' . $e('12. Proof needed / weak evidence') . '</h3><p>' . $e((string) ($report['weak_evidence_section'] ?? '')) . '</p></section>';
        $h .= '<section class="fi-report-section"><h3>' . $e('13. Appendix') . '</h3><p class="fi-memory-policy">' . $e((string) ($report['memory_note'] ?? '')) . '</p><p class="fi-provider-policy">' . $e((string) ($report['privacy_note'] ?? '')) . '</p></section></section>';
        return $h;
    }

    public static function render_markdown(array $report): string {
        if (empty($report)) { return "# Decision Report\n\nReport unavailable for this run.\n"; }
        $lines = ['# Future Island Decision Report', '', (string) ($report['executive_summary'] ?? ''), '', '## Counts'];
        foreach ((array) ($report['counts'] ?? []) as $k => $v) { $lines[] = '- ' . str_replace('_', ' ', (string) $k) . ': ' . (string) $v; }
        $lines[] = ''; $lines[] = '## Source coverage'; $lines[] = implode(', ', (array) ($report['source_coverage'] ?? []));
        $lines[] = ''; $lines[] = '## Timeline';
        foreach ((array) ($report['timeline'] ?? []) as $ev) { $lines[] = '- ' . (string) ($ev['event_type'] ?? 'event') . ' x' . (string) ($ev['count'] ?? 0) . ': ' . (string) ($ev['last_message'] ?? ''); }
        $lines[] = ''; $lines[] = '## Provider ingestion summary';
        foreach ((array) ($report['provider_ingestions'] ?? []) as $ing) { $lines[] = '- #' . (string) ($ing['id'] ?? 0) . ' ' . (string) ($ing['provider_family'] ?? '') . '/' . (string) ($ing['provider_key'] ?? '') . ': ' . (string) ($ing['status'] ?? '') . ', accepted ' . (string) ($ing['accepted_count'] ?? 0) . ', rejected ' . (string) ($ing['rejected_count'] ?? 0); }
        $lines[] = ''; $lines[] = '## Evidence appendix';
        foreach ((array) ($report['evidence_appendix'] ?? []) as $ev) { $lines[] = '- Evidence #' . (string) ($ev['id'] ?? 0) . ': ' . (string) ($ev['title'] ?? '') . ' — ' . (string) ($ev['summary'] ?? ''); }
        $lines[] = ''; $lines[] = '## Outcome receipts'; $lines[] = 'Manual outcome capture is available after review.'; $lines[] = ''; $lines[] = '## Pattern observations'; $lines[] = 'Patterns require repeated review/outcome support before confidence increases.'; $lines[] = ''; $lines[] = '## Weak evidence / proof needed'; $lines[] = (string) ($report['weak_evidence_section'] ?? '');
        $lines[] = ''; $lines[] = '## Policy notes'; $lines[] = (string) ($report['memory_note'] ?? 'Memory is context, not evidence.'); $lines[] = (string) ($report['privacy_note'] ?? 'Provider payloads are redacted.');
        return implode("\n", $lines) . "\n";
    }

    private static function ingestion_summaries(array $rows): array {
        $out = [];
        foreach (array_slice($rows, 0, 20) as $r) {
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'provider_family' => (string) ($r['provider_family'] ?? ''),
                'provider_key' => (string) ($r['provider_key'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
                'accepted_count' => (int) ($r['accepted_count'] ?? 0),
                'rejected_count' => (int) ($r['rejected_count'] ?? 0),
                'raw_payload_exposed' => false,
            ];
        }
        return $out;
    }

    private static function qa_summary(array $qa): array {
        $items = is_array($qa['items'] ?? null) ? $qa['items'] : [];
        $total = count($items); $ok = 0;
        foreach ($items as $item) { if (!empty($item['status']) || !empty($item['manually_checked'])) { $ok++; } }
        return ['total' => $total, 'ready_or_checked' => $ok, 'private_beta_only' => true];
    }

    private static function summaries(array $rows, string $title_key, string $body_key): array {
        $out = [];
        foreach (array_slice($rows, 0, 20) as $r) { $out[] = ['id' => (int) ($r['id'] ?? 0), 'title' => self::trim((string) ($r[$title_key] ?? ''), 180), 'summary' => self::trim((string) ($r[$body_key] ?? ''), 500)]; }
        return $out;
    }
    private static function trim(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
}

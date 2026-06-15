<?php
if (!defined('ABSPATH')) { exit; }

/** FI_Memory_Renderer — memory candidates with review rail. */
final class FI_Memory_Renderer {

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function ea($s) { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function u($s) { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function cut($s, $max): string { return function_exists('mb_substr') ? mb_substr((string) $s, 0, (int) $max, 'UTF-8') : substr((string) $s, 0, (int) $max); }

    private static function review_form(string $action, array $fields, string $label, string $class = 'button button-small'): string {
        $post_url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function_exists('wp_nonce_field') ? wp_nonce_field($action, '_wpnonce', true, false) : '';
        $h = '<form class="fi-intake-inline-action" method="post" action="' . self::u($post_url) . '">' . $nonce;
        $h .= '<input type="hidden" name="action" value="' . self::ea($action) . '">';
        foreach ($fields as $key => $value) {
            $h .= '<input type="hidden" name="' . self::ea((string) $key) . '" value="' . self::ea((string) $value) . '">';
        }
        $h .= '<button type="submit" class="' . self::ea($class) . '">' . self::e($label) . '</button></form>';
        return $h;
    }

    private static function notice_html(): string {
        $notice = isset($_GET['fi_notice']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $_GET['fi_notice'])) : '';
        $err = isset($_GET['fi_err']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $_GET['fi_err'])) : '';
        if ($notice === 'memory_approved') { return '<div class="notice notice-success"><p>' . self::e('Memory candidate approved.') . '</p></div>'; }
        if ($notice === 'memory_rejected') { return '<div class="notice notice-success"><p>' . self::e('Memory candidate rejected (kept, flagged — never fed to generation).') . '</p></div>'; }
        if ($err !== '') {
            $map = [
                'fi_memory_not_found' => 'Memory record not found.',
                'fi_memory_bad_input' => 'workspace_id and memory_id are required.',
                'ves_workspace_mismatch' => 'That memory record belongs to a different workspace.',
                'fi_memory_store_missing' => 'Memory review is unavailable: intelligence store not loaded.',
            ];
            return '<div class="notice notice-error"><p>' . self::e($map[$err] ?? ('The action could not be completed (' . $err . ').')) . '</p></div>';
        }
        return '';
    }

    public static function render_html(): string {
        $ws = FI_Memory_Service::workspace_id();
        $records = FI_Memory_Service::records($ws);
        $status = FI_Memory_Service::status();
        $can_review = FI_Memory_Service::store_available();

        $h  = '<div class="wrap ves-wrap fi-signal-room fi-module-page" id="fi-memory">';
        $h .= '<header class="fi-room-context">';
        $h .= '<p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Memory') . '</p>';
        $h .= '<h1>' . self::e('Memory') . '</h1>';
        $h .= '<p class="fi-intake-sub">' . self::e('Reviewable continuity objects created from approved work. Memory is not evidence; nothing is learned across tenants; rejected candidates are kept and flagged, never silently deleted.') . '</p>';
        $h .= '<p class="fi-module-detail">' . self::e((string) ($status['detail'] ?? '')) . '</p>';
        $h .= '</header>';
        $h .= self::notice_html();

        $h .= '<div class="fi-room-grid"><main class="fi-room-main">';
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Memory candidates (workspace ' . $ws . ')') . '</h2>';
        if (empty($records)) {
            $h .= '<p class="fi-empty-state">' . self::e('No memory records yet — create candidates from drafts in the Workbench or Asset Studio.') . '</p>';
        } else {
            $h .= '<table class="widefat striped"><thead><tr><th>' . self::e('id') . '</th><th>' . self::e('run') . '</th><th>' . self::e('type') . '</th><th>' . self::e('text') . '</th><th>' . self::e('source object') . '</th><th>' . self::e('trust label') . '</th><th>' . self::e('review state') . '</th><th>' . self::e('decision') . '</th></tr></thead><tbody>';
            foreach ($records as $record) {
                $id = (int) ($record['id'] ?? 0);
                $meta = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
                $approval = (string) ($meta['approval_status'] ?? (!empty($meta['requires_review']) ? 'pending_review' : ''));
                if ($approval === '') { $approval = 'pending_review'; }
                $badge_class = $approval === 'approved' ? 'fiis-badge-approved' : ($approval === 'rejected' ? 'fiis-badge-rejected' : 'fiis-badge-needs_review');
                $source_ref = trim((string) ($record['source_entity_type'] ?? '') . ' #' . (string) ($record['source_entity_id'] ?? ''));
                $trust_label = (string) ($record['trust_label'] ?? ($meta['trust_label'] ?? 'candidate_context_not_evidence'));
                if ($can_review && $approval === 'pending_review') {
                    $decision = self::review_form(FI_Memory_Service::ACTION_APPROVE, ['workspace_id' => $ws, 'memory_id' => $id], 'Approve')
                        . self::review_form(FI_Memory_Service::ACTION_REJECT, ['workspace_id' => $ws, 'memory_id' => $id], 'Reject', 'button button-small fi-intake-danger');
                } elseif ($approval === 'pending_review') {
                    $decision = '<span class="fi-intake-disabled">' . self::e('Review unavailable') . '</span>';
                } else {
                    $decision = '<span class="fi-intake-disabled">' . self::e('Decided') . '</span>';
                }
                $h .= '<tr><td>' . self::e((string) $id) . '</td>'
                    . '<td>' . self::e(!empty($record['run_id']) ? ('#' . (int) $record['run_id']) : '—') . '</td>'
                    . '<td>' . self::e((string) ($record['memory_type'] ?? '')) . '</td>'
                    . '<td>' . self::e(self::cut((string) ($record['text'] ?? ''), 90)) . '</td>'
                    . '<td>' . self::e($source_ref !== '#' ? $source_ref : '—') . '</td>'
                    . '<td><span class="fi-status-badge fiis-badge-needs_review">' . self::e(ucwords(str_replace('_', ' ', $trust_label))) . '</span></td>'
                    . '<td><span class="fi-status-badge ' . self::ea($badge_class) . '">' . self::e(ucwords(str_replace('_', ' ', $approval))) . '</span></td>'
                    . '<td>' . $decision . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '</section>';
        $h .= '</main><aside class="fi-room-rail">';
        $h .= '<section class="fi-intake-card"><h2>' . self::e('How memory works here') . '</h2>';
        $h .= '<ul class="fi-policy-list">';
        $h .= '<li>' . self::e('Candidates arrive pending_review from drafts/outputs.') . '</li>';
        $h .= '<li>' . self::e('Only approved memory may inform future context.') . '</li>';
        $h .= '<li>' . self::e('Rejection keeps the record, flagged — audit stays intact.') . '</li>';
        $h .= '<li>' . self::e('Hard deletion is a deliberate admin cleanup, never a side effect.') . '</li>';
        $h .= '</ul></section>';
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Ops view') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('The full knowledge/ops surface (schema health, raw records) stays in the admin tools.') . '</p>';
        $h .= '<p><a class="button" href="' . self::u(function_exists('admin_url') ? admin_url('tools.php?page=ves-memory-knowledge') : '#') . '">' . self::e('Open Memory / Knowledge (ops)') . '</a></p>';
        $h .= '</section>';
        $h .= '</aside></div></div>';
        return $h;
    }
}

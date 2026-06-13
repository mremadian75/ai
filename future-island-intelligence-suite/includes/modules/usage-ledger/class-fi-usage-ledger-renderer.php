<?php
if (!defined('ABSPATH')) { exit; }

/** FI_Usage_Ledger_Renderer — explainable ledger rows (signal-room system). */
final class FI_Usage_Ledger_Renderer {

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function u($s) { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    public static function render_html(): string {
        $entries = FI_Usage_Ledger_Service::entries();
        $summary = FI_Usage_Ledger_Service::summary();
        $status = FI_Usage_Ledger_Service::status();

        $h  = '<div class="wrap ves-wrap fi-signal-room fi-module-page" id="fi-usage-ledger">';
        $h .= '<header class="fi-room-context">';
        $h .= '<p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Usage Ledger') . '</p>';
        $h .= '<h1>' . self::e('Usage Ledger') . '</h1>';
        $h .= '<p class="fi-intake-sub">' . self::e('Every usage event with its action, object trace, user, workspace and credit status — explainable rows, not aggregate counters. Operator usage events are idempotent on retry.') . '</p>';
        $h .= '<p class="fi-module-detail">' . self::e((string) ($status['detail'] ?? '')) . '</p>';
        $h .= '</header>';

        $h .= '<div class="fi-room-grid"><main class="fi-room-main">';
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Ledger entries') . '</h2>';
        if (empty($entries)) {
            $h .= '<p class="fi-empty-state">' . self::e('No usage events recorded yet (or the tracker table is unavailable in this install).') . '</p>';
        } else {
            $h .= '<table class="widefat striped"><thead><tr>'
                . '<th>' . self::e('id') . '</th><th>' . self::e('when') . '</th><th>' . self::e('action') . '</th>'
                . '<th>' . self::e('object trace') . '</th><th>' . self::e('user') . '</th><th>' . self::e('workspace') . '</th>'
                . '<th>' . self::e('status') . '</th><th>' . self::e('credits') . '</th></tr></thead><tbody>';
            foreach ($entries as $row) {
                $action = trim((string) ($row['module'] ?? '') . ' · ' . (string) ($row['operation_type'] ?? ''), ' ·');
                $h .= '<tr><td>' . self::e((string) ($row['id'] ?? '')) . '</td>'
                    . '<td>' . self::e((string) ($row['created_at'] ?? '')) . '</td>'
                    . '<td>' . self::e(ucwords(str_replace('_', ' ', $action))) . '</td>'
                    . '<td class="fi-intake-mono">' . self::e((string) ($row['object_trace'] ?? '—')) . '</td>'
                    . '<td>' . self::e('#' . (string) ($row['user_id'] ?? 0)) . '</td>'
                    . '<td>' . self::e('#' . (string) ($row['workspace_id'] ?? 0)) . '</td>'
                    . '<td>' . self::e(ucwords(str_replace('_', ' ', (string) ($row['status'] ?? '')))) . '</td>'
                    . '<td>' . self::e((string) ($row['credits_charged'] ?? 0)) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '</section>';
        $h .= '</main><aside class="fi-room-rail">';
        if (!empty($summary)) {
            $h .= '<section class="fi-intake-card"><h2>' . self::e('Last 24 h') . '</h2><ul class="fi-policy-list">';
            foreach (['events' => 'Events', 'completed' => 'Completed', 'failed' => 'Failed', 'credits_charged' => 'Credits charged'] as $key => $label) {
                if (isset($summary[$key])) {
                    $h .= '<li>' . self::e($label . ': ' . $summary[$key]) . '</li>';
                }
            }
            $h .= '</ul></section>';
        }
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Billing & reconciliation') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Credit policy, settlement and the billing ledger stay in the ops surfaces.') . '</p>';
        $h .= '<p><a class="button" href="' . self::u(function_exists('admin_url') ? admin_url('tools.php?page=ves-billing-ledger') : '#') . '">' . self::e('Open billing ledger (ops)') . '</a></p>';
        $h .= '</section>';
        $h .= '</aside></div></div>';
        return $h;
    }
}

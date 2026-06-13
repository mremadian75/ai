<?php
if (!defined('ABSPATH')) { exit; }

/** FI_Trend_Finder_Renderer — module hub markup (signal-room visual system). */
final class FI_Trend_Finder_Renderer {

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function u($s) { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    private static function source_label(string $source): string {
        $map = ['tiktok' => 'TikTok', 'instagram' => 'Instagram', 'reddit' => 'Reddit', 'google_trends' => 'Google Trends', 'google_news' => 'Google News', 'ai_research' => 'AI Research'];
        return $map[$source] ?? ucwords(str_replace('_', ' ', $source));
    }

    public static function render_html(): string {
        $status = FI_Trend_Finder_Service::status();
        $workspace = FI_Trend_Finder_Service::workspace_url();
        $rows = FI_Trend_Finder_Service::source_status_rows();
        $runs = FI_Trend_Finder_Service::recent_runs();
        $preflight = FI_Trend_Finder_Service::preflight();

        $h  = '<div class="wrap ves-wrap fi-signal-room fi-module-page" id="fi-trend-finder">';
        $h .= '<header class="fi-room-context">';
        $h .= '<p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Trend Finder') . '</p>';
        $h .= '<h1>' . self::e('Trend Finder') . '</h1>';
        $h .= '<p class="fi-intake-sub">' . self::e('The canonical multi-source trend engine: live source collection, scored evidence, claim-readiness, and a report artifact. The legacy "Trend Finder" page now routes here.') . '</p>';
        $h .= '<p class="fi-module-detail">' . self::e((string) ($status['detail'] ?? '')) . '</p>';
        $h .= '</header>';

        // Decision rail: the ONE next action.
        $h .= '<div class="fi-room-grid"><main class="fi-room-main">';

        // Provider/source status rail (truth, including allowlist).
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Source / provider status') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Preflight truth per source: an unavailable actor is never offered as runnable.') . '</p>';
        if (empty($rows)) {
            $h .= '<p class="fi-empty-state">' . self::e('Engine unavailable — no source status to show.') . '</p>';
        } else {
            $h .= '<div class="fidtf-source-rail">';
            foreach ($rows as $row) {
                $cls = $row['state'] === 'live_ready' ? 'is-live' : ($row['state'] === 'unavailable' ? 'is-err' : 'is-warn');
                $badge = $row['state'] === 'live_ready' ? 'Ready for live' : ($row['state'] === 'unavailable' ? 'Unavailable' : 'Planned only');
                $h .= '<div class="fidtf-source-row ' . self::e($cls) . '">';
                $h .= '<div class="fidtf-source-row-head"><strong>' . self::e(self::source_label((string) $row['source'])) . '</strong><span class="fidtf-chip">' . self::e($badge) . '</span></div>';
                $h .= '<small>' . self::e((string) $row['detail']) . '</small>';
                if (!empty($row['actor_id'])) {
                    $h .= '<details class="fidtf-diagnostics"><summary>' . self::e('Technical diagnostics') . '</summary><small>' . self::e('Actor: ' . $row['actor_id']) . '</small></details>';
                }
                $h .= '</div>';
            }
            $h .= '</div>';
        }
        $h .= '</section>';

        // Run history (read-only, from existing data).
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Run history') . '</h2>';
        if (empty($runs)) {
            $h .= '<p class="fi-empty-state">' . self::e('No runs recorded yet — start one in the workspace.') . '</p>';
        } else {
            $h .= '<table class="widefat striped"><thead><tr><th>' . self::e('id') . '</th><th>' . self::e('status') . '</th><th>' . self::e('brief') . '</th><th>' . self::e('market') . '</th><th>' . self::e('created') . '</th></tr></thead><tbody>';
            foreach ($runs as $run) {
                $h .= '<tr><td>' . self::e((string) ($run['id'] ?? '')) . '</td>'
                    . '<td>' . self::e(ucwords(str_replace('_', ' ', (string) ($run['status'] ?? '')))) . '</td>'
                    . '<td>' . self::e(mb_substr((string) ($run['user_brief'] ?? ''), 0, 80)) . '</td>'
                    . '<td>' . self::e((string) ($run['market'] ?? '')) . '</td>'
                    . '<td>' . self::e((string) ($run['created_at'] ?? '')) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '</section>';

        $h .= '</main><aside class="fi-room-rail">';
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Next action') . '</h2>';
        if ($workspace !== '') {
            $h .= '<p class="fi-intake-hint">' . self::e('Run form, live results and the report artifact live in the dedicated workspace.') . '</p>';
            $h .= '<p><a class="button button-primary" href="' . self::u($workspace) . '">' . self::e('Open Trend Finder workspace') . '</a></p>';
        } else {
            $h .= '<p class="fi-intake-hint">' . self::e('The workspace page does not exist yet. Create it from the module settings — until then this module is configuration-needed, not runnable.') . '</p>';
        }
        $h .= '<p><a class="button" href="' . self::u(FI_Trend_Finder_Service::settings_url()) . '">' . self::e('Module settings') . '</a></p>';
        $h .= '</section>';

        // Diagnostics: collapsed, secondary.
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Diagnostics') . '</h2>';
        $h .= '<details class="fidtf-diagnostics"><summary>' . self::e('Preflight detail') . '</summary>';
        $h .= '<small>' . self::e('Live: ' . implode(', ', array_map([__CLASS__, 'source_label'], (array) ($preflight['live_sources'] ?? [])))) . '</small><br>';
        $h .= '<small>' . self::e('Planned only: ' . implode(', ', array_map([__CLASS__, 'source_label'], (array) ($preflight['planned_only_sources'] ?? [])))) . '</small><br>';
        $h .= '<small>' . self::e('Blocking: ' . (implode(', ', (array) ($preflight['blocking_reasons'] ?? [])) ?: 'none')) . '</small>';
        $h .= '</details></section>';
        $h .= '</aside></div></div>';
        return $h;
    }
}

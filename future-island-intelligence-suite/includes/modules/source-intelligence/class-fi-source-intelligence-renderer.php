<?php
if (!defined('ABSPATH')) { exit; }

/** FI_Source_Intelligence_Renderer — provider truth page (signal-room system). */
final class FI_Source_Intelligence_Renderer {

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function u($s) { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    private static function source_label(string $source): string {
        $map = ['tiktok' => 'TikTok', 'instagram' => 'Instagram', 'reddit' => 'Reddit', 'google_trends' => 'Google Trends', 'google_news' => 'Google News', 'ai_research' => 'AI Research'];
        return $map[$source] ?? ucwords(str_replace('_', ' ', $source));
    }

    public static function render_html(): string {
        $rows = FI_Source_Intelligence_Service::dtf_source_rows();
        $summary = FI_Source_Intelligence_Service::registry_summary();
        $status = FI_Source_Intelligence_Service::status();

        $h  = '<div class="wrap ves-wrap fi-signal-room fi-module-page" id="fi-source-intelligence">';
        $h .= '<header class="fi-room-context">';
        $h .= '<p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Source Intelligence') . '</p>';
        $h .= '<h1>' . self::e('Source Intelligence') . '</h1>';
        $h .= '<p class="fi-intake-sub">' . self::e('Where evidence enters the loop: provider/actor truth, allowlist preflight, and manual/URL intake. A source that cannot pass preflight is shown blocked here — never offered as runnable elsewhere.') . '</p>';
        $h .= '<p class="fi-module-detail">' . self::e((string) ($status['detail'] ?? '')) . '</p>';
        $h .= '</header>';

        $h .= '<div class="fi-room-grid"><main class="fi-room-main">';
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Provider actors — preflight truth') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Each enabled source, the actor that backs it, and whether the dispatch gate will accept it. Provider-returned vs parsed vs usable evidence is reported per run in the Trend Finder report.') . '</p>';
        if (empty($rows)) {
            $h .= '<p class="fi-empty-state">' . self::e('No provider-backed sources are enabled (or the engine is not loaded).') . '</p>';
        } else {
            $h .= '<div class="fidtf-source-rail">';
            foreach ($rows as $row) {
                $cls = !$row['allowlisted'] ? 'is-err' : ($row['live'] ? 'is-live' : 'is-warn');
                $badge = !$row['allowlisted'] ? 'Blocked by preflight' : ($row['live'] ? 'Ready for live' : 'Planned only');
                $h .= '<div class="fidtf-source-row ' . self::e($cls) . '">';
                $h .= '<div class="fidtf-source-row-head"><strong>' . self::e(self::source_label((string) $row['source'])) . '</strong><span class="fidtf-chip">' . self::e($badge) . '</span></div>';
                if (!$row['allowlisted']) {
                    $h .= '<small>' . self::e($row['detail'] !== '' ? $row['detail'] : 'Actor is not registered in the server actor registry.') . '</small>';
                } else {
                    $h .= '<small>' . self::e($row['live'] ? 'Dispatch gate and live bridge are ready.' : 'Actor passes the gate; the live bridge/toggle is off.') . '</small>';
                }
                $h .= '<details class="fidtf-diagnostics"><summary>' . self::e('Technical diagnostics') . '</summary>';
                $h .= '<small>' . self::e('Actor: ' . ($row['actor_id'] !== '' ? $row['actor_id'] : '—')) . '</small><br>';
                $h .= '<small>' . self::e('Preflight: ' . ($row['allowlisted'] ? 'ok' : $row['reason'])) . '</small>';
                $h .= '</details>';
                $h .= '</div>';
            }
            $h .= '</div>';
        }
        $h .= '</section>';
        $h .= '</main><aside class="fi-room-rail">';

        $h .= '<section class="fi-intake-card"><h2>' . self::e('Add a source') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Manual notes and URL references enter through the Workbench intake — recorded, never fetched.') . '</p>';
        $h .= '<p><a class="button button-primary" href="' . self::u(FI_Source_Intelligence_Service::intake_url()) . '">' . self::e('Open intake') . '</a></p>';
        $h .= '</section>';

        $h .= '<section class="fi-intake-card"><h2>' . self::e('Actor registry') . '</h2>';
        if (!empty($summary['available'])) {
            $h .= '<p class="fi-intake-hint">' . self::e($summary['entries'] . ' registry entries · ' . $summary['allowlist_count'] . ' allowlisted slugs · ' . $summary['disabled'] . ' disabled by policy.') . '</p>';
        } else {
            $h .= '<p class="fi-intake-hint">' . self::e('Core actor registry unavailable.') . '</p>';
        }
        $h .= '<p><a class="button" href="' . self::u(FI_Source_Intelligence_Service::registry_admin_url()) . '">' . self::e('Open registry (admin)') . '</a></p>';
        $h .= '</section>';
        $h .= '</aside></div></div>';
        return $h;
    }
}

<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Report_Template_Registry {
    public static function templates(): array {
        return [
            'pulso-dashboard' => [
                'label' => __('PULSO dashboard', 'ves'),
                'template' => VES_PLUGIN_DIR . 'templates/reports/pulso-dashboard.php',
                'schema' => VES_PLUGIN_DIR . 'schemas/ves-report-core.schema.json',
                'description' => __('Signal overview, evidence counts, insights and next actions.', 'ves'),
            ],
            'trend-deep-dive' => [
                'label' => __('Trend deep dive', 'ves'),
                'template' => VES_PLUGIN_DIR . 'templates/reports/trend-deep-dive.php',
                'schema' => VES_PLUGIN_DIR . 'schemas/ves-report-core.schema.json',
                'description' => __('Trend anatomy, evidence, tensions, brand opportunities and tests.', 'ves'),
            ],
            'tiktok-radar' => [
                'label' => __('TikTok radar', 'ves'),
                'template' => VES_PLUGIN_DIR . 'templates/reports/tiktok-radar.php',
                'schema' => VES_PLUGIN_DIR . 'schemas/ves-report-core.schema.json',
                'description' => __('Short-form signal cards, hooks, creators and format patterns.', 'ves'),
            ],
            'strategic-playbook' => [
                'label' => __('Strategic playbook', 'ves'),
                'template' => VES_PLUGIN_DIR . 'templates/reports/strategic-playbook.php',
                'schema' => VES_PLUGIN_DIR . 'schemas/ves-report-core.schema.json',
                'description' => __('Insight-to-brief report for campaigns and human review.', 'ves'),
            ],
            'empty-report' => [
                'label' => __('Empty evidence state', 'ves'),
                'template' => VES_PLUGIN_DIR . 'templates/reports/empty-report.php',
                'schema' => VES_PLUGIN_DIR . 'schemas/ves-report-core.schema.json',
                'description' => __('Safe fallback when a source returns no usable signals.', 'ves'),
            ],
            // v0.9.31.5: curated static narrative report templates. These are
            // selected when the run topic / source family matches the curated
            // subject. They render verbatim Spanish content and do not depend
            // on the report schema fields beyond what choose() inspects.
            'verano-silencioso' => [
                'label' => __('Verano Silencioso — análisis 2026', 'ves'),
                'template' => VES_PLUGIN_DIR . 'templates/reports/verano-silencioso.php',
                'schema' => VES_PLUGIN_DIR . 'schemas/ves-report-core.schema.json',
                'description' => __('Análisis curado de la tendencia de slow living para el verano 2026 en España.', 'ves'),
            ],
            'tiktok-trends-2026' => [
                'label' => __('TikTok Tendencias 2026', 'ves'),
                'template' => VES_PLUGIN_DIR . 'templates/reports/tiktok-trends-2026.php',
                'schema' => VES_PLUGIN_DIR . 'schemas/ves-report-core.schema.json',
                'description' => __('Radar curado de tendencias TikTok 2026 (Next Report).', 'ves'),
            ],
            'tastemakers-2026' => [
                'label' => __('Economía de tastemakers 2026', 'ves'),
                'template' => VES_PLUGIN_DIR . 'templates/reports/tastemakers-2026.php',
                'schema' => VES_PLUGIN_DIR . 'schemas/ves-report-core.schema.json',
                'description' => __('Guía curada sobre el paso del modelo influencer al tastemaker.', 'ves'),
            ],
        ];
    }

    public static function get(string $key): array {
        $key = sanitize_key($key);
        $templates = self::templates();
        return $templates[$key] ?? $templates['empty-report'];
    }

    public static function choose(array $report): string {
        $type = sanitize_key((string)($report['report_type'] ?? ''));
        $source = sanitize_key((string)($report['source_family'] ?? $report['platform'] ?? ''));
        $evidence_count = (int)($report['summary']['evidence_count'] ?? 0);
        if ($evidence_count <= 0) { return 'empty-report'; }
        if ($type !== '' && isset(self::templates()[$type])) { return $type; }
        // v0.9.31.5: curated narrative auto-routing. Runs whose topic blob
        // matches one of the curated subjects get the matching static report
        // template. Explicit report_type still wins (handled above).
        $topic_blob = function_exists('mb_strtolower')
            ? mb_strtolower((string) implode(' ', [
                (string) ($report['title'] ?? ''),
                (string) ($report['subtitle'] ?? ''),
                (string) ($report['platform'] ?? ''),
                (string) ($report['source_family'] ?? ''),
                is_array($report['topic_tags'] ?? null) ? implode(' ', $report['topic_tags']) : '',
            ]), 'UTF-8')
            : strtolower((string) implode(' ', [
                (string) ($report['title'] ?? ''),
                (string) ($report['subtitle'] ?? ''),
                (string) ($report['platform'] ?? ''),
                (string) ($report['source_family'] ?? ''),
                is_array($report['topic_tags'] ?? null) ? implode(' ', $report['topic_tags']) : '',
            ]));
        if ($source === 'tiktok' && (strpos($topic_blob, 'tendencias') !== false || strpos($topic_blob, '2026') !== false)) {
            return 'tiktok-trends-2026';
        }
        if (preg_match('/verano silencioso|slow living|slow summer|silent summer/u', $topic_blob)) {
            return 'verano-silencioso';
        }
        if (preg_match('/tastemaker|tastemakers|econom[ií]a de confianza|confidence economy/u', $topic_blob)) {
            return 'tastemakers-2026';
        }
        if ($source === 'tiktok') { return 'tiktok-radar'; }
        if (strpos($source, 'trend') !== false) { return 'trend-deep-dive'; }
        if (in_array($source, ['facebook_ads','google_ads','ads','meta_ads'], true)) { return 'strategic-playbook'; }
        return 'pulso-dashboard';
    }
}

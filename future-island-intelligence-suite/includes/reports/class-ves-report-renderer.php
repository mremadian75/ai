<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Report_Renderer {
    public static function render(array $report, string $template_key = ''): string {
        $validation = VES_Report_Schema_Validator::validate($report);
        $report = $validation['report'];
        if ($template_key === '') { $template_key = VES_Report_Template_Registry::choose($report); }
        $template = VES_Report_Template_Registry::get($template_key);
        $file = (string)($template['template'] ?? '');
        if (!is_readable($file)) {
            // v0.9.31.5 BUG-06: emit an admin diagnostic before the silent
            // empty-report fallback so missing/permissions issues are visible.
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic(
                    'report_template_unreadable',
                    "Report template file not readable: {$file}",
                    ['template_key' => $template_key, 'requested_file' => $file]
                );
            }
            $file = VES_Report_Template_Registry::get('empty-report')['template'];
        }
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }
}

if (!function_exists('ves_render_report_template')) {
    function ves_render_report_template(array $report, string $template_key = ''): string {
        return class_exists('VES_Report_Renderer') ? VES_Report_Renderer::render($report, $template_key) : '';
    }
}

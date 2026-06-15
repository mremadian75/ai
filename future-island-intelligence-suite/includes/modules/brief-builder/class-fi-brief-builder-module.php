<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Brief_Builder_Module — navigation shell for the brief workbench:
 * approved insight → evidence-backed brief with prompt package preview.
 * Canonical surface: tools.php?page=fi-brief-workbench (VES_Workbench).
 * Link entry — no duplicate page. Brief creation itself is the audited
 * intake action (only APPROVED insights can become briefs).
 */
final class FI_Brief_Builder_Module extends FI_Abstract_Module {

    public function id(): string { return 'brief_builder'; }
    public function label(): string { return 'Brief Builder'; }
    public function description(): string {
        return 'Turn an approved insight into an evidence-backed brief with proof-needed notes and a campaign/content route.';
    }
    public function nav(): array { return ['type' => 'link', 'url' => 'tools.php?page=fi-brief-workbench']; }
    public function status(): array {
        if (!class_exists('VES_Workbench')) {
            return ['state' => 'unavailable', 'detail' => 'Workbench renderer is not loaded.'];
        }
        if (!class_exists('VES_Insight_Brief_Builder')) {
            return ['state' => 'configuration_needed', 'detail' => 'Brief builder service unavailable.'];
        }
        return ['state' => 'available', 'detail' => ''];
    }
    public function service_class(): string { return 'VES_Insight_Brief_Builder'; }
    public function renderer_class(): string { return 'VES_Workbench'; }
    public function actions(): array {
        return class_exists('VES_Source_Intake') ? [VES_Source_Intake::ACTION_BRIEF] : [];
    }
}

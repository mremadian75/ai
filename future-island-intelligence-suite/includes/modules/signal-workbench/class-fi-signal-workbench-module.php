<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Signal_Workbench_Module — navigation shell for the interpretation room:
 * source → signal → insight with review actions, evidence trace and per-row
 * action rails. Canonical surface: tools.php?page=fi-intake (VES_Source_Intake,
 * rebuilt as a signal room in v0.3.55). Link entry — no duplicate page.
 */
final class FI_Signal_Workbench_Module extends FI_Abstract_Module {

    public function id(): string { return 'signal_workbench'; }
    public function label(): string { return 'Workbench'; }
    public function description(): string {
        return 'The working room: record sources and signals, promote to insights, approve/reject, and move objects down the loop without id copying.';
    }
    public function nav(): array { return ['type' => 'link', 'url' => 'tools.php?page=fi-intake']; }
    public function status(): array {
        if (!class_exists('VES_Source_Intake')) {
            return ['state' => 'unavailable', 'detail' => 'Intake surface is not loaded.'];
        }
        if (!class_exists('VES_Intelligence_Store')) {
            return ['state' => 'configuration_needed', 'detail' => 'Intelligence store unavailable — pipeline actions cannot persist.'];
        }
        return ['state' => 'available', 'detail' => ''];
    }
    public function service_class(): string { return 'VES_Source_Intake'; }
    public function actions(): array {
        if (!class_exists('VES_Source_Intake')) { return []; }
        return [
            VES_Source_Intake::ACTION_SOURCE,
            VES_Source_Intake::ACTION_SIGNAL,
            VES_Source_Intake::ACTION_INSIGHT,
            VES_Source_Intake::ACTION_APPROVE_INSIGHT,
            VES_Source_Intake::ACTION_REJECT_INSIGHT,
            VES_Source_Intake::ACTION_BRIEF,
        ];
    }
}

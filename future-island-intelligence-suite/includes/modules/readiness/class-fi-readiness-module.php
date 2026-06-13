<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Readiness_Module — navigation shell for the existing Release Candidate /
 * readiness surface. It supports validation; it is deliberately NOT the
 * product, so it stays a link entry at the end of the navigation.
 */
final class FI_Readiness_Module extends FI_Abstract_Module {

    public function id(): string { return 'readiness'; }
    public function label(): string { return 'Readiness / Evidence'; }
    public function description(): string {
        return 'Release-candidate readiness checks and the evidence pack — validation support, not the product.';
    }
    public function nav(): array {
        $slug = class_exists('VES_Release_Candidate_Page') ? VES_Release_Candidate_Page::PAGE_SLUG : 'ves-release-candidate';
        return ['type' => 'link', 'url' => 'admin.php?page=' . $slug];
    }
    public function status(): array {
        if (!class_exists('VES_Release_Candidate_Page')) {
            return ['state' => 'unavailable', 'detail' => 'Release candidate surface is not loaded.'];
        }
        return ['state' => 'available', 'detail' => ''];
    }
    public function renderer_class(): string { return 'VES_Release_Candidate_Page'; }
}

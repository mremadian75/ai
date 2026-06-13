<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Trend_Finder_Service — data assembly for the canonical Trend Finder
 * module hub. The CANONICAL Trend Finder is the Deep Trend Finder engine
 * (FIDTF_*: REST lifecycle, source jobs, evidence quality, report artifact).
 * The legacy core "Trend Finder" page in the SaaS app is deprecated and
 * routed here (see FUTUREISLAND_MIGRATION_NOTES.md).
 */
final class FI_Trend_Finder_Service {

    public static function engine_available(): bool {
        return class_exists('FIDTF_Run_Service') && class_exists('FIDTF_Settings');
    }

    /** Frontend workspace URL (the run form + results live there). */
    public static function workspace_url(): string {
        if (class_exists('VES_Deep_Trend_Addon_Bridge') && method_exists('VES_Deep_Trend_Addon_Bridge', 'addon_page_url')) {
            return (string) VES_Deep_Trend_Addon_Bridge::addon_page_url();
        }
        return '';
    }

    public static function settings_url(): string {
        return function_exists('admin_url') ? admin_url('options-general.php?page=future-island-deep-trend-finder') : '';
    }

    /** Live preflight including unavailable sources (actor allowlist truth). */
    public static function preflight(): array {
        if (!class_exists('FIDTF_Settings') || !method_exists('FIDTF_Settings', 'live_preflight_status')) {
            return [];
        }
        try {
            return (array) FIDTF_Settings::live_preflight_status();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Per-source actor + readiness rows for the provider status rail. */
    public static function source_status_rows(): array {
        if (!class_exists('FIDTF_Settings')) { return []; }
        $rows = [];
        $preflight = self::preflight();
        $live = (array) ($preflight['live_sources'] ?? []);
        $unavailable = is_array($preflight['unavailable_sources'] ?? null) ? $preflight['unavailable_sources'] : [];
        foreach (FIDTF_Settings::ordered_source_keys() as $source) {
            if (!FIDTF_Settings::source_enabled($source)) { continue; }
            $actor = method_exists('FIDTF_Settings', 'actor_for') ? FIDTF_Settings::actor_for($source) : '';
            if (isset($unavailable[$source])) {
                $state = 'unavailable';
                $detail = (string) ($unavailable[$source]['detail'] ?? 'Actor not registered on this server.');
            } elseif (in_array($source, $live, true)) {
                $state = 'live_ready';
                $detail = 'Can start live provider collection.';
            } else {
                $state = 'planned_only';
                $detail = 'No provider call will be made until its bridge/actor mapping is enabled.';
            }
            $rows[] = [
                'source' => $source,
                'actor_id' => $actor,
                'state' => $state,
                'detail' => $detail,
            ];
        }
        return $rows;
    }

    /** Recent run history (read-only). */
    public static function recent_runs(int $limit = 8): array {
        if (!class_exists('FIDTF_Run_Service') || !method_exists('FIDTF_Run_Service', 'list_recent_runs')) {
            return [];
        }
        return FIDTF_Run_Service::list_recent_runs($limit);
    }

    public static function status(): array {
        if (!self::engine_available()) {
            return ['state' => 'unavailable', 'detail' => 'Deep Trend Finder engine classes are not loaded.'];
        }
        if (self::workspace_url() === '') {
            return ['state' => 'configuration_needed', 'detail' => 'Workspace page missing — create it from Settings → Deep Trend Finder.'];
        }
        $preflight = self::preflight();
        if (empty($preflight['live_sources'])) {
            return ['state' => 'configuration_needed', 'detail' => 'No live source is ready; runs stay planning-only until a bridge/actor is enabled.'];
        }
        return ['state' => 'available', 'detail' => count((array) $preflight['live_sources']) . ' live source(s) ready.'];
    }
}

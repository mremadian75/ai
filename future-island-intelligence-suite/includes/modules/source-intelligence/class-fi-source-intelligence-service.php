<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Source_Intelligence_Service — provider/actor truth for the Source
 * Intelligence module: which sources exist, which actor backs each one,
 * whether that actor passes the dispatch allowlist preflight, and where the
 * operator adds manual/URL sources. Read-only assembly over existing services
 * (VES_Apify_Actor_Registry, FIDTF_Settings, VES_Config).
 */
final class FI_Source_Intelligence_Service {

    public static function registry_available(): bool {
        return class_exists('VES_Apify_Actor_Registry');
    }

    /** Deep Trend Finder source rows (actor + preflight + live state). */
    public static function dtf_source_rows(): array {
        if (!class_exists('FIDTF_Settings')) { return []; }
        $rows = [];
        $preflight = method_exists('FIDTF_Settings', 'live_preflight_status') ? (array) FIDTF_Settings::live_preflight_status() : [];
        $live = (array) ($preflight['live_sources'] ?? []);
        $unavailable = is_array($preflight['unavailable_sources'] ?? null) ? $preflight['unavailable_sources'] : [];
        foreach (FIDTF_Settings::ordered_source_keys() as $source) {
            if (!FIDTF_Settings::source_enabled($source)) { continue; }
            $actor = method_exists('FIDTF_Settings', 'actor_for') ? FIDTF_Settings::actor_for($source) : '';
            $check = method_exists('FIDTF_Settings', 'actor_preflight') ? FIDTF_Settings::actor_preflight($source) : ['ok' => true, 'reason' => 'ok'];
            $rows[] = [
                'module'   => 'trend_finder',
                'source'   => $source,
                'actor_id' => $actor,
                'allowlisted' => !empty($check['ok']),
                'reason'   => (string) ($check['reason'] ?? ''),
                'detail'   => (string) ($check['detail'] ?? ''),
                'live'     => in_array($source, $live, true),
                'unavailable' => isset($unavailable[$source]),
            ];
        }
        return $rows;
    }

    /** Core actor-registry summary: allowlist size + disabled/setup entries. */
    public static function registry_summary(): array {
        if (!self::registry_available()) {
            return ['available' => false, 'allowlist_count' => 0, 'entries' => 0, 'disabled' => 0];
        }
        $registry = VES_Apify_Actor_Registry::registry();
        $disabled = 0;
        foreach ($registry as $entry) {
            if (is_array($entry) && empty($entry['enabled'])) { $disabled++; }
        }
        return [
            'available' => true,
            'allowlist_count' => count((array) VES_Apify_Actor_Registry::allowed_slugs()),
            'entries' => count($registry),
            'disabled' => $disabled,
        ];
    }

    public static function intake_url(): string {
        return function_exists('admin_url') ? admin_url('tools.php?page=fi-intake') : '';
    }

    public static function registry_admin_url(): string {
        return function_exists('admin_url') ? admin_url('tools.php?page=ves-actor-registry') : '';
    }

    public static function status(): array {
        if (!self::registry_available()) {
            return ['state' => 'unavailable', 'detail' => 'Actor registry is not loaded.'];
        }
        $rows = self::dtf_source_rows();
        $blocked = 0;
        foreach ($rows as $row) { if (!$row['allowlisted']) { $blocked++; } }
        if ($blocked > 0) {
            return ['state' => 'configuration_needed', 'detail' => $blocked . ' source(s) blocked by actor preflight — register the actor(s) before they can run.'];
        }
        return ['state' => 'available', 'detail' => 'All enabled source actors pass the dispatch allowlist preflight.'];
    }
}

<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Usage_Ledger_Service — explainable usage events, not aggregate counters.
 * Read-only over VES_AI_Usage_Tracker rows: every entry shows action, object
 * trace (run_id / metadata target), user, workspace, credits and idempotent
 * retry behavior where the writing flow provides it.
 */
final class FI_Usage_Ledger_Service {

    public static function tracker_available(): bool {
        return class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'recent');
    }

    public static function entries(int $limit = 40): array {
        if (!self::tracker_available()) { return []; }
        try {
            $rows = (array) VES_AI_Usage_Tracker::recent($limit);
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as &$row) {
            $meta = json_decode((string) ($row['metadata'] ?? ''), true);
            $row['metadata_decoded'] = is_array($meta) ? $meta : [];
            $row['object_trace'] = self::object_trace($row);
        }
        unset($row);
        return $rows;
    }

    /** Human object trace: "draft #77" / run id / module operation. */
    private static function object_trace(array $row): string {
        $meta = is_array($row['metadata_decoded'] ?? null) ? $row['metadata_decoded'] : [];
        $type = (string) ($meta['target_type'] ?? '');
        $id = (string) ($meta['target_id'] ?? '');
        if ($type !== '' && $id !== '') { return $type . ' #' . $id; }
        $run = (string) ($row['run_id'] ?? '');
        return $run !== '' ? 'run ' . $run : '—';
    }

    public static function summary(): array {
        if (!self::tracker_available() || !method_exists('VES_AI_Usage_Tracker', 'summary')) { return []; }
        try {
            return (array) VES_AI_Usage_Tracker::summary(24);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function status(): array {
        if (!self::tracker_available()) {
            return ['state' => 'unavailable', 'detail' => 'Usage tracker is not loaded.'];
        }
        return ['state' => 'read_only', 'detail' => 'Ledger entries are written by product actions; this surface reads and explains them.'];
    }
}

<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Memory_Cleanup_Service {
    public static function cleanup_expired_memory() {
        // v0.9.8.0 bug fix: previous build called the non-existent method
        // `cleanup_expired_records`. The actual method is `cleanup_expired`.
        // We try both names so future renames don't silently break cleanup.
        if (class_exists('VES_Temp_Memory')) {
            if (method_exists('VES_Temp_Memory', 'cleanup_expired_records')) {
                VES_Temp_Memory::cleanup_expired_records();
            } elseif (method_exists('VES_Temp_Memory', 'cleanup_expired')) {
                VES_Temp_Memory::cleanup_expired();
            }
        }
        return 0;
    }

    public static function cleanup_orphan_async_state() {
        return 0;
    }
}

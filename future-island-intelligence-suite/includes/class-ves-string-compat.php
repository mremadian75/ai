<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Lightweight string compatibility for environments without ext-mbstring.
 *
 * The plugin prefers PHP's mb_* functions when present. In minimal CLI or host
 * environments where mbstring is absent, these fallbacks keep rendering and
 * tests non-fatal without making mbstring a hard runtime dependency. They are
 * byte-based fallbacks, so they preserve availability rather than exact Unicode
 * semantics.
 */
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        $string = (string) $string;
        $start = (int) $start;
        if ($length === null) {
            return substr($string, $start);
        }
        return substr($string, $start, (int) $length);
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) {
        return strlen((string) $string);
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) {
        return strtolower((string) $string);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($string, $encoding = null) {
        return strtoupper((string) $string);
    }
}

if (!function_exists('mb_stripos')) {
    function mb_stripos($haystack, $needle, $offset = 0, $encoding = null) {
        return stripos((string) $haystack, (string) $needle, (int) $offset);
    }
}

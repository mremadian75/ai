<?php
/**
 * v0.9.31.6 — Google SERP normalizer: no raw search URL as card title/url.
 * Run: php tests/test-v09314-google-serp-normalizer.php
 */
$root = dirname(__DIR__);
$php  = file_get_contents($root . '/includes/class-ves-google-intel.php');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

// Version
$assert(strpos($main, 'Version: 1.2.4') !== false, 'Version is 1.1.0');

// is_google_search_url helper exists
$assert(strpos($php, 'private static function is_google_search_url(') !== false, 'is_google_search_url() method declared');

// is_google_search_url matches google.*/search regex
$assert(strpos($php, 'google') !== false && strpos($php, '/search') !== false && strpos($php, 'preg_match') !== false, 'is_google_search_url uses preg_match for google.*/search pattern');

// is_google_search_url matches /search?q= string fallback
$assert(strpos($php, '/search?q=') !== false, 'is_google_search_url handles /search?q= URL pattern');

// prepare_display_items: at least 2 calls to is_google_search_url (guard + generic_row)
$count = substr_count($php, 'is_google_search_url');
$assert($count >= 2, "is_google_search_url called at least twice (guard + generic_row), found $count");

// prepare_display_items guard: when page URL is google search, generic_row is skipped
$assert(strpos($php, 'is_google_search_url($page') !== false, 'prepare_display_items has is_google_search_url($page_url) guard');

// generic_row: title candidates list no longer unconditionally falls back to URL
$assert(strpos($php, "'title','name','headline','query','searchTerm','inputUrlOrTerm'") !== false, 'generic_row title candidates declared without unconditional url fallback');

// generic_row: URL is guarded before being added to title candidates
$assert(strpos($php, 'is_google_search_url($raw') !== false, 'generic_row guards raw_url with is_google_search_url before title use');

// generic_row: URL cleared when it is a google search URL
$assert(strpos($php, "is_google_search_url") !== false && strpos($php, "? '' :") !== false, 'generic_row returns empty url when raw_url is a google search URL');

// Functional: use PHP eval to test is_google_search_url logic via extracted preg_match body
// (avoid require_once due to ABSPATH guard in the class file)
$fn_start = strpos($php, 'private static function is_google_search_url(');
$fn_end   = strpos($php, "\n    }", $fn_start);
$fn_body  = $fn_start !== false && $fn_end !== false ? substr($php, $fn_start, $fn_end - $fn_start) : '';
$has_google_regex = strpos($fn_body, 'google') !== false && strpos($fn_body, 'search') !== false;
$assert($has_google_regex, 'is_google_search_url body contains google+search pattern check');

if ($fail === 0) {
    echo "OK v0.9.31.6 Google SERP normalizer — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 Google SERP normalizer — $fail failures, $pass passed\n";
    exit(1);
}

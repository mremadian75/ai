<?php
/**
 * VES_Evidence_Store::normalize_evidence_refs — the AI-safety allowlist gate.
 *
 * v0.9.25 hardened the brand-audit allowlist so that ONLY canonical
 * `ev_<digits>_<hex>` references may enter the set the AI is permitted to cite —
 * whether they arrive from the request payload or are derived from scraped
 * evidence. The whole anti-hallucination contract leans on this one normalizer
 * being un-bypassable, so these tests attack it directly with the kinds of
 * malformed / injected refs a compromised provider, a prompt-injected model, or
 * a corrupted upstream payload might try to smuggle in.
 *
 * The canonical contract (mirrors the regex in normalize_evidence_refs):
 *   ev_  +  one-or-more digits  +  _  +  6..20 lowercase hex chars
 *
 * Run: php tests/test-ves-evidence-ref-allowlist.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

// ── Minimal WP shims (only what normalize_evidence_refs touches) ─────────────
if (!function_exists('sanitize_text_field')) {
    // Faithful-enough: strip tags, collapse internal whitespace, trim, drop NUL/newlines.
    function sanitize_text_field($s) {
        $s = (string) $s;
        $s = strip_tags($s);
        $s = preg_replace('/[\r\n\t\0]+/', '', $s);
        $s = preg_replace('/\s{2,}/', ' ', $s);
        return trim($s);
    }
}

require_once dirname(__DIR__) . '/includes/class-ves-evidence-store.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$N = ['VES_Evidence_Store', 'normalize_evidence_refs'];

// ── 1. Canonical refs survive intact ─────────────────────────────────────────
$good = ['ev_1_a1b2c3', 'ev_42_deadbeef', 'ev_999999_0123456789abcdef'];
$ok(call_user_func($N, $good) === $good, '1: canonical ev_<digits>_<hex> refs pass through unchanged');

// ── 2. Injected / smuggled non-canonical refs are dropped ────────────────────
$attacks = [
    'ev_1_a1b2c3; DROP TABLE wp_posts',     // SQL-ish suffix
    'ev_1_a1b2c3 OR 1=1',                    // injection tail
    '../../etc/passwd',                      // path traversal
    'ev_1_a1b2c3<script>alert(1)</script>',  // XSS tail
    'EV_1_A1B2C3',                           // wrong case (hex must be lowercase)
    'ev_1_A1B2C3',                           // uppercase hex
    'ev_a_a1b2c3',                           // non-digit index
    'ev_1_xyz123',                           // non-hex chars
    'ev_1_abc',                              // hex too short (<6)
    'ev_1_' . str_repeat('a', 21),           // hex too long (>20)
    'ev_1_a1b2c3_extra',                     // trailing segment
    'prefix_ev_1_a1b2c3',                    // leading garbage
    'ev1_a1b2c3',                            // missing underscore after ev
    'ev__a1b2c3',                            // missing digits
    'evidence_1_a1b2c3',                     // wrong prefix
    '',                                      // empty
    '   ',                                   // whitespace only
];
$res = call_user_func($N, $attacks);
$ok($res === [], '2: every malformed / injected ref is rejected (allowlist stays empty)');

// ── 3. A poisoned batch keeps the good, drops the bad ────────────────────────
$mixed = ['ev_7_abcdef', 'ev_7_abcdef; DELETE FROM x', 'ev_8_0a0a0a', 'not-a-ref'];
$ok(call_user_func($N, $mixed) === ['ev_7_abcdef', 'ev_8_0a0a0a'], '3: mixed batch retains only the canonical refs');

// ── 4. Array-shaped refs: pulls evidence_ref/ref/id, still validates ─────────
$shaped = [
    ['evidence_ref' => 'ev_10_aaaaaa'],
    ['ref' => 'ev_11_bbbbbb'],
    ['id' => 'ev_12_cccccc'],
    ['evidence_ref' => 'ev_13_NOPE!!'],   // present but non-canonical -> dropped
    ['unrelated' => 'ev_99_ffffff'],       // no recognized key -> '' -> dropped
];
$ok(call_user_func($N, $shaped) === ['ev_10_aaaaaa', 'ev_11_bbbbbb', 'ev_12_cccccc'],
    '4: array-shaped refs extract the canonical id and drop malformed/keyless ones');

// ── 5. String input is split on whitespace/commas then validated ─────────────
$ok(call_user_func($N, "ev_1_aaaaaa, ev_2_bbbbbb  ev_3_zzzzzz") === ['ev_1_aaaaaa', 'ev_2_bbbbbb'],
    '5: a delimited string is split and only canonical tokens survive');

// ── 6. Duplicates are collapsed (set semantics) ──────────────────────────────
$ok(call_user_func($N, ['ev_5_abcdef', 'ev_5_abcdef', 'ev_5_abcdef']) === ['ev_5_abcdef'],
    '6: duplicate refs collapse to a single allowlist entry');

// ── 7. The limit caps allowlist size (cost/abuse guard) ──────────────────────
$many = [];
for ($i = 1; $i <= 50; $i++) { $many[] = 'ev_' . $i . '_aaaaaa'; }
$ok(count(call_user_func($N, $many, 10)) === 10, '7: the limit argument caps the number of allowed refs');

// ── 8. Non-array / non-string input is safe (no throw, empty result) ─────────
$ok(call_user_func($N, null) === [] && call_user_func($N, 123) === [] && call_user_func($N, new stdClass()) === [],
    '8: null / int / object inputs yield an empty allowlist without error');

// ── 9. Whitespace and embedded control chars are sanitized then validated ────
$ok(call_user_func($N, ['  ev_4_abcdef  ', "ev_4_abc\ndef"]) === ['ev_4_abcdef'],
    '9: surrounding whitespace is trimmed and embedded newlines collapsed before matching');

// ── 10. A non-scalar nested value is skipped, not coerced ────────────────────
$ok(call_user_func($N, [['evidence_ref' => ['nested' => 'ev_1_aaaaaa']], 'ev_2_bbbbbb']) === ['ev_2_bbbbbb'],
    '10: a non-scalar extracted ref is skipped rather than stringified into a fake match');

echo "\nVES_Evidence_Store evidence-ref allowlist (AI safety): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }

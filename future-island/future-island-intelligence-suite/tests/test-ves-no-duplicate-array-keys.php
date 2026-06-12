<?php
/**
 * Phase 4/5 cleanup — duplicate-array-key regression guard.
 *
 * PHP silently accepts `['k' => 1, 'k' => 2]` (the last wins), which is how
 * sloppy duplicated payload keys ship unnoticed. This test token-scans every
 * `[...]` array literal in includes/ (depth-aware) and fails on any literal
 * string key repeated inside the SAME literal. Current state: zero.
 *
 * Run: php tests/test-ves-no-duplicate-array-keys.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; } else { $fail++; fwrite(STDERR, "FAIL: $l\n"); } };

$files = glob(dirname(__DIR__) . '/includes/*.php');
$ok(count($files) > 50, 'includes/ scanned (' . count($files) . ' files)');

$duplicates = [];
foreach ($files as $file) {
    $toks = token_get_all((string) file_get_contents($file));
    $stack = [];
    for ($i = 0; $i < count($toks); $i++) {
        $t = $toks[$i];
        if ($t === '[') { $stack[] = []; continue; }
        if ($t === '(') { $stack[] = null; continue; } // non-literal scope (calls, control flow)
        if ($t === ']' || $t === ')') { array_pop($stack); continue; }
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && count($stack) > 0 && is_array($stack[count($stack) - 1])) {
            for ($j = $i + 1; $j < count($toks); $j++) {
                $n = $toks[$j];
                if (is_array($n) && in_array($n[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                if (is_array($n) && $n[0] === T_DOUBLE_ARROW) {
                    $key = $t[1];
                    $top = &$stack[count($stack) - 1];
                    if (isset($top[$key])) {
                        $duplicates[] = basename($file) . ':' . $t[2] . ' key ' . $key . ' (first at line ' . $top[$key] . ')';
                    } else {
                        $top[$key] = $t[2];
                    }
                    unset($top);
                }
                break;
            }
        }
    }
}
$ok($duplicates === [], 'no duplicate literal keys in any array literal (' . implode('; ', array_slice($duplicates, 0, 5)) . ')');

// The detector itself must actually detect (self-check against a known-bad snippet).
$probe = '<?php $a = ["source_title" => 1, "x" => ["source_title" => 2], "source_title" => 3];';
$toks = token_get_all($probe); $stack = []; $found = 0;
for ($i = 0; $i < count($toks); $i++) {
    $t = $toks[$i];
    if ($t === '[') { $stack[] = []; continue; }
    if ($t === '(') { $stack[] = null; continue; }
    if ($t === ']' || $t === ')') { array_pop($stack); continue; }
    if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && count($stack) > 0 && is_array($stack[count($stack) - 1])) {
        for ($j = $i + 1; $j < count($toks); $j++) {
            $n = $toks[$j];
            if (is_array($n) && in_array($n[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            if (is_array($n) && $n[0] === T_DOUBLE_ARROW) {
                $top = &$stack[count($stack) - 1];
                if (isset($top[$t[1]])) { $found++; } else { $top[$t[1]] = $t[2]; }
                unset($top);
            }
            break;
        }
    }
}
$ok($found === 1, 'detector self-check: catches a true duplicate, ignores the same key in a NESTED literal');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Phase 9B.4 — security event log contract (real class).
 *
 * Proves:
 *  1. events are recorded with scrubbed details/context — secret VALUES never land
 *  2. forbidden context keys are redacted wholesale
 *  3. the ring is bounded (oldest dropped past MAX_EVENTS)
 *  4. there is no edit/delete API (append-only)
 *  5. summary() counts by type for the RC page
 *  6. unknown types collapse to 'other' (no enum injection)
 *
 * Run: php tests/test-ves-security-event-log-9b.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function current_time($t='mysql',$g=0){return '2026-06-14 13:00:00';}
function get_current_user_id(){return 2;}
$GLOBALS['__o']=[];

require_once dirname(__DIR__) . '/includes/class-ves-security-event-log.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

// ── 1. Scrubbing ─────────────────────────────────────────────────────────────
VES_Security_Event_Log::record('provider_dispatch_blocked', 'blocked dispatch with token apify_api_SUPERSECRET12345 and key sk-LEAKYLEAKYLEAK99', [
    'actor' => 'evil~actor',
    'token' => 'apify_api_ANOTHERSECRET99999',
    'authorization' => 'Bearer abc.def',
    'note' => 'Bearer xyz1234567890abcdef should vanish',
]);
$raw = json_encode(get_option('ves_security_event_log', []));
$ok(strpos($raw, 'apify_api_SUPERSECRET12345') === false, 'detail token scrubbed');
$ok(strpos($raw, 'sk-LEAKYLEAKYLEAK99') === false, 'detail sk- key scrubbed');
$ok(strpos($raw, 'apify_api_ANOTHERSECRET99999') === false, 'forbidden context key (token) redacted wholesale');
$ok(strpos($raw, 'abc.def') === false, 'authorization context value never stored');
$ok(strpos($raw, '[redacted]') !== false, 'redaction placeholder present');
$ok(strpos($raw, 'evil~actor') !== false, 'non-secret context survives');

// ── 2. Type discipline ───────────────────────────────────────────────────────
VES_Security_Event_Log::record('totally_made_up_type', 'whatever');
$events = VES_Security_Event_Log::recent(2);
$ok($events[0]['type'] === 'other', 'unknown type collapses to other');
$ok($events[1]['type'] === 'provider_dispatch_blocked', 'known type preserved');

// ── 3. Bounded ring ──────────────────────────────────────────────────────────
for ($i = 0; $i < 230; $i++) {
    VES_Security_Event_Log::record('workspace_mismatch', 'probe ' . $i);
}
$all = get_option('ves_security_event_log', []);
$ok(count($all) === VES_Security_Event_Log::MAX_EVENTS, 'ring is bounded to MAX_EVENTS');
$recent = VES_Security_Event_Log::recent(1);
$ok(strpos((string)$recent[0]['detail'], 'probe 229') !== false, 'newest event survives the trim');

// ── 4. Append-only API ───────────────────────────────────────────────────────
$ok(!method_exists('VES_Security_Event_Log', 'update') && !method_exists('VES_Security_Event_Log', 'delete') && !method_exists('VES_Security_Event_Log', 'clear'), 'no update/delete/clear API');

// ── 5. Summary ───────────────────────────────────────────────────────────────
$sum = VES_Security_Event_Log::summary();
$ok((int)$sum['total'] === VES_Security_Event_Log::MAX_EVENTS, 'summary total matches ring size');
$ok((int)($sum['by_type']['workspace_mismatch'] ?? 0) > 0, 'summary counts by type');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

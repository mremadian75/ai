<?php
/**
 * Phase 9A.5 — queue retry / dead-letter rails contract (real classes).
 *
 * Proves:
 *  1. failures increment the retry count per idempotency key
 *  2. reaching max retries appends a SCRUBBED dead letter
 *  3. a dead-lettered key is refused further execution (with_lock skips it)
 *  4. success clears the retry counter (transient errors don't accumulate)
 *  5. dead letters are bounded, append-only, and operator-cleared explicitly
 *  6. retries do not duplicate side effects (callback runs once per attempt)
 *  7. status() feeds readiness honestly
 *
 * Run: php tests/test-ves-dead-letter-rails-9a.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function current_time($t='mysql',$g=0){return '2026-06-14 15:00:00';}
function current_user_can($c){return $GLOBALS['__can'] ?? true;}
$GLOBALS['__o']=[]; $GLOBALS['__can']=true;

require_once dirname(__DIR__) . '/includes/class-ves-security-event-log.php';
require_once dirname(__DIR__) . '/includes/class-ves-job-rails.php';

// Minimal Action Scheduler job harness around the REAL with_lock wrapper.
final class VES_Run_Lock_Manager {
    public static function acquire($r, $k, $t = 0) { return true; }
    public static function release($r, $k) {}
}
final class VES_Run_Log_Service {
    public static $entries = [];
    public static function warn($r, $c, $m, $ctx = []) { self::$entries[] = ['warn', $c, $m, $ctx]; }
    public static function error($r, $c, $m, $ctx = []) { self::$entries[] = ['error', $c, $m, $ctx]; }
}
final class VES_Run_Execution_Service {
    public static $failed = [];
    public static function mark_failed($b, $m, $c = []) { self::$failed[] = $b; }
    public static function mark_failed_by_id($id, $m, $c = []) { self::$failed[] = $id; }
}
require_once dirname(__DIR__) . '/includes/class-ves-action-scheduler-jobs.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

// ── 1. Failure increments retry count ────────────────────────────────────────
$key = VES_Job_Rails::job_key('run_source', ['run_id' => 12, 'source_key' => 'tiktok_1']);
$ok(is_string($key) && strpos($key, 'job_') === 0, 'job key is deterministic and prefixed');
$ok($key === VES_Job_Rails::job_key('run_source', ['run_id' => 12, 'source_key' => 'tiktok_1']), 'same payload yields the same key');

$r1 = VES_Job_Rails::record_failure($key, 'run_source', 'transient timeout with token apify_api_SECRETXYZ12345');
$ok($r1['attempts'] === 1 && $r1['dead'] === false, 'first failure tracked, not dead');
$r2 = VES_Job_Rails::record_failure($key, 'run_source', 'transient timeout again');
$ok($r2['attempts'] === 2 && $r2['dead'] === false, 'second failure tracked, not dead');

// ── 2. Max retries dead-letters with a scrubbed reason ───────────────────────
$r3 = VES_Job_Rails::record_failure($key, 'run_source', 'final failure with token apify_api_SECRETXYZ12345');
$ok($r3['attempts'] === 3 && $r3['dead'] === true, 'third failure reaches max retries and dead-letters');
$dead = VES_Job_Rails::dead_letters(5);
$ok(count($dead) === 1 && $dead[0]['job_key'] === $key, 'dead letter recorded for the key');
$ok(strpos(json_encode($dead), 'apify_api_SECRETXYZ12345') === false, 'dead-letter reason is scrubbed');
$ok(VES_Job_Rails::is_dead($key) === true, 'key reports dead');

// ── 3. with_lock refuses a dead-lettered job (no side effects) ───────────────
$ran = 0;
$reflect = new ReflectionMethod('VES_Action_Scheduler_Jobs', 'with_lock');
$reflect->setAccessible(true);
$reflect->invoke(null, ['run_id' => 12, 'source_key' => 'tiktok_1'], 'run_source', function () use (&$ran) { $ran++; });
$ok($ran === 0, 'dead-lettered job is skipped — callback never runs');
$ok(strpos(json_encode(VES_Run_Log_Service::$entries), 'dead-lettered') !== false, 'skip is logged to the run log');

// ── 4. Success clears the retry counter ─────────────────────────────────────
$key2 = VES_Job_Rails::job_key('collect_source_result', ['run_id' => 30, 'source_key' => 'ig_1']);
VES_Job_Rails::record_failure($key2, 'collect_source_result', 'flaky once');
$reflect->invoke(null, ['run_id' => 30, 'source_key' => 'ig_1'], 'collect_source_result', function () use (&$ran) { $ran++; });
$ok($ran === 1, 'healthy job executes exactly once per attempt');
$counts = get_option('ves_job_retry_counts', []);
$ok(!isset($counts[$key2]), 'success cleared the retry counter');

// ── 5. with_lock catch path feeds the rails ──────────────────────────────────
$key3 = VES_Job_Rails::job_key('run_source', ['run_id' => 44, 'source_key' => 'yt_1']);
for ($i = 0; $i < 3; $i++) {
    $reflect->invoke(null, ['run_id' => 44, 'source_key' => 'yt_1'], 'run_source', function () { throw new RuntimeException('boom with apify_api_LEAK999999'); });
}
$ok(VES_Job_Rails::is_dead($key3) === true, 'three throwing executions dead-letter the job');
$reflect->invoke(null, ['run_id' => 44, 'source_key' => 'yt_1'], 'run_source', function () use (&$ran) { $ran += 100; });
$ok($ran === 1, 'fourth attempt is refused — no resurrection');
$ok(strpos(json_encode(VES_Job_Rails::dead_letters(10)), 'apify_api_LEAK999999') === false, 'thrown reason scrubbed in dead letter');

// ── 6. Bounded + explicit operator clear ─────────────────────────────────────
for ($i = 0; $i < 120; $i++) {
    VES_Job_Rails::record_failure('job_bulk' . $i, 'run_source', 'x');
    VES_Job_Rails::record_failure('job_bulk' . $i, 'run_source', 'x');
    VES_Job_Rails::record_failure('job_bulk' . $i, 'run_source', 'x');
}
$ok(count(get_option('ves_job_dead_letter', [])) <= VES_Job_Rails::MAX_DEAD_LETTERS, 'dead-letter ring is bounded');
$GLOBALS['__can'] = false;
$ok(VES_Job_Rails::clear_dead_letter($key3) === false, 'non-admin cannot clear a dead letter');
$GLOBALS['__can'] = true;
// $key3 may have been trimmed by the bulk flood; verify clear on a fresh dead letter.
VES_Job_Rails::record_failure('job_cleartest', 'run_source', 'x');
VES_Job_Rails::record_failure('job_cleartest', 'run_source', 'x');
VES_Job_Rails::record_failure('job_cleartest', 'run_source', 'x');
$ok(VES_Job_Rails::is_dead('job_cleartest'), 'fresh dead letter exists');
$ok(VES_Job_Rails::clear_dead_letter('job_cleartest') === true, 'admin can clear a dead letter explicitly');
$ok(VES_Job_Rails::is_dead('job_cleartest') === false, 'cleared key may run again');

// ── 7. Status feeds readiness ────────────────────────────────────────────────
$status = VES_Job_Rails::status();
$ok(!empty($status['available']) && $status['dead_letters'] > 0 && $status['healthy'] === false, 'status reports dead letters honestly');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

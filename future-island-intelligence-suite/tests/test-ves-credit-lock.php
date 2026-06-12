<?php
/**
 * VES_Usage_Billing — real cross-process credit mutex (MySQL GET_LOCK).
 *
 * v0.9.25 replaced the transient "advisory lock" (which could not be made
 * atomic on object-cache hosts) with a genuine MySQL GET_LOCK / RELEASE_LOCK
 * mutex. These tests drive the private lock primitives through Reflection
 * against a fake $wpdb so we can assert:
 *   - '1' from GET_LOCK acquires and records the held lock
 *   - '0' (timeout) and NULL (error) degrade-to-proceed without recording
 *   - release issues RELEASE_LOCK for the exact held name, and is idempotent
 *   - lock names are unique per install + user, and within MySQL's 64-char cap
 *   - the degrade-to-proceed escape hatch is filterable
 *   - with_credit_lock() runs the callback and always releases (even on throw)
 *
 * Run: php tests/test-ves-credit-lock.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('WP_DEBUG')) { define('WP_DEBUG', false); }

// ── WP shims ─────────────────────────────────────────────────────────────────
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('delete_transient')) {
    $GLOBALS['__deleted_transients'] = [];
    function delete_transient($k) { $GLOBALS['__deleted_transients'][] = $k; return true; }
}
if (!function_exists('apply_filters')) {
    // Honor a registered override so we can test the degrade escape hatch.
    function apply_filters($tag, $value) {
        if (isset($GLOBALS['__filter_overrides'][$tag])) {
            return $GLOBALS['__filter_overrides'][$tag];
        }
        return $value;
    }
}
if (!function_exists('add_action')) { function add_action() { return true; } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code; private $message;
        public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}

// ── Fake $wpdb that records GET_LOCK / RELEASE_LOCK traffic ───────────────────
class _LockWPDB {
    public $dbname = 'fiis_db';
    public $prefix = 'wp_';
    /** @var array<int,string> every prepared query executed */
    public $queries = [];
    /** Next value GET_LOCK should return: '1' | '0' | null */
    public $get_lock_result = '1';
    public $release_calls = 0;

    public function prepare($q, ...$args) {
        // Crude but faithful: substitute %s as quoted, %d as int, in order.
        foreach ($args as $a) {
            $repl = is_int($a) || ctype_digit((string) $a) && strpos($q, '%d') !== false && strpos($q, '%s') === false
                ? (string) (int) $a : "'" . $a . "'";
            // Replace the first placeholder (either %s or %d), left to right.
            $pos_s = strpos($q, '%s');
            $pos_d = strpos($q, '%d');
            if ($pos_s === false && $pos_d === false) { break; }
            if ($pos_d === false || ($pos_s !== false && $pos_s < $pos_d)) {
                $q = substr_replace($q, "'" . $a . "'", $pos_s, 2);
            } else {
                $q = substr_replace($q, (string) (int) $a, $pos_d, 2);
            }
        }
        return $q;
    }
    public function get_var($q) {
        $this->queries[] = $q;
        if (strpos($q, 'GET_LOCK') !== false) { return $this->get_lock_result; }
        return null;
    }
    public function query($q) {
        $this->queries[] = $q;
        if (strpos($q, 'RELEASE_LOCK') !== false) { $this->release_calls++; }
        return 1;
    }
}

require_once dirname(__DIR__) . '/includes/class-ves-billing.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// Reflection helpers to reach the private static lock primitives.
$ref = new ReflectionClass('VES_Usage_Billing');
$call = function ($method, ...$args) use ($ref) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
};
$held = function () use ($ref) {
    $p = $ref->getProperty('held_db_locks');
    $p->setAccessible(true);
    return $p->getValue();
};
$reset_held = function () use ($ref) {
    foreach (['held_db_locks', 'lock_depth'] as $prop) {
        if ($ref->hasProperty($prop)) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, []);
        }
    }
};

// ── 1. GET_LOCK '1' acquires and records the held lock ───────────────────────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->get_lock_result = '1';
$res = $call('acquire_credit_lock', 42, 3000);
$ok($res === true, '1: acquire returns true when GET_LOCK yields "1"');
$h = $held();
$ok(isset($h[42]) && is_string($h[42]) && $h[42] !== '', '1: acquired lock name is recorded for the user');
$ok(count($GLOBALS['wpdb']->queries) === 1 && strpos($GLOBALS['wpdb']->queries[0], 'GET_LOCK') !== false, '1: exactly one GET_LOCK query issued');

// ── 2. Lock name unique per user, within MySQL's 64-char cap ──────────────────
$reset_held();
$nameA = $call('db_lock_name', 42);
$nameB = $call('db_lock_name', 43);
$ok($nameA !== $nameB, '2: different users get different lock names');
$ok(strlen($nameA) <= 64, '2: lock name stays within MySQL 64-char limit');
$ok(strpos($nameA, 'vescl_') === 0, '2: lock name carries the vescl_ namespace prefix');

// Different install (db/prefix) -> different name for the same user.
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->dbname = 'other_db';
$nameOther = $call('db_lock_name', 42);
$ok($nameOther !== $nameA, '2: same user on a different install resolves to a different lock name');

// ── 3. GET_LOCK "0" (timeout) degrades to proceed, records nothing ───────────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->get_lock_result = '0';
$res = $call('acquire_credit_lock', 7, 1000);
$ok($res === true, '3: timeout ("0") degrades to proceed (true) by default');
$h = $held();
$ok(!isset($h[7]), '3: a timed-out lock is NOT recorded as held');

// ── 4. GET_LOCK NULL (error) degrades to proceed, records nothing ────────────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->get_lock_result = null;
$res = $call('acquire_credit_lock', 7, 1000);
$ok($res === true, '4: error (NULL) degrades to proceed (true) by default');
$ok(!isset($held()[7]), '4: an errored lock is NOT recorded as held');

// ── 5. Degrade escape hatch is filterable (can force hard-block) ─────────────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->get_lock_result = '0';
$GLOBALS['__filter_overrides']['ves_credit_lock_degrade_to_proceed'] = false;
$res = $call('acquire_credit_lock', 9, 500);
$ok($res === false, '5: ves_credit_lock_degrade_to_proceed=false makes a failed acquire hard-block');
unset($GLOBALS['__filter_overrides']['ves_credit_lock_degrade_to_proceed']);

// ── 6. release issues RELEASE_LOCK for the held name, then clears it ─────────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->get_lock_result = '1';
$call('acquire_credit_lock', 42, 3000);
$heldName = $held()[42];
$call('release_credit_lock', 42);
$ok($GLOBALS['wpdb']->release_calls === 1, '6: release issues exactly one RELEASE_LOCK');
$released_q = end($GLOBALS['wpdb']->queries);
$ok(strpos($released_q, $heldName) !== false, '6: RELEASE_LOCK targets the exact held lock name');
$ok(!isset($held()[42]), '6: held registry is cleared after release');

// ── 7. release is idempotent / safe with no held lock ────────────────────────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$call('release_credit_lock', 999); // nothing held
$ok($GLOBALS['wpdb']->release_calls === 0, '7: releasing an unheld user issues no RELEASE_LOCK');

// ── 8. user_id <= 0 is a no-op acquire (no query, returns true) ──────────────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$res = $call('acquire_credit_lock', 0, 3000);
$ok($res === true && count($GLOBALS['wpdb']->queries) === 0, '8: user_id<=0 acquires trivially without touching the DB');

// ── 9. No usable $wpdb -> degrade to proceed (tests/early boot) ──────────────
$reset_held();
$GLOBALS['wpdb'] = null;
$res = $call('acquire_credit_lock', 5, 3000);
$ok($res === true, '9: a missing $wpdb degrades to proceed rather than throwing');

// ── 10. with_credit_lock runs callback and releases (happy path) ─────────────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->get_lock_result = '1';
$ran = false;
$out = $call('with_credit_lock', 11, function () use (&$ran) { $ran = true; return 'OK'; });
$ok($ran === true && $out === 'OK', '10: with_credit_lock executes the callback and returns its value');
$ok($GLOBALS['wpdb']->release_calls === 1, '10: with_credit_lock releases the lock after the callback');
$ok(!isset($held()[11]), '10: no lock left held after with_credit_lock completes');

// ── 11. with_credit_lock always releases even when the callback throws ───────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->get_lock_result = '1';
$out = $call('with_credit_lock', 12, function () { throw new RuntimeException('kaboom'); });
$ok(is_object($out) && method_exists($out, 'get_error_code'), '11: a throwing callback is converted to WP_Error');
$ok($GLOBALS['wpdb']->release_calls === 1, '11: the lock is released even when the callback throws');
$ok(!isset($held()[12]), '11: held registry is clear after a throwing callback');

// ── 12. Reentrancy: nested acquire of the SAME user issues only one GET_LOCK ──
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->get_lock_result = '1';
$call('acquire_credit_lock', 50, 3000); // outer
$call('acquire_credit_lock', 50, 3000); // inner (reentrant)
$get_lock_queries = array_filter($GLOBALS['wpdb']->queries, static function ($q) { return strpos($q, 'GET_LOCK') !== false; });
$ok(count($get_lock_queries) === 1, '12: nested acquire of the same user issues only ONE GET_LOCK');
// Inner release must NOT free the lock the outer scope still holds.
$call('release_credit_lock', 50);
$ok($GLOBALS['wpdb']->release_calls === 0, '12: inner release keeps the lock held (no RELEASE_LOCK yet)');
$ok(isset($held()[50]), '12: the lock is still recorded as held after the inner release');
// Outer release frees it exactly once.
$call('release_credit_lock', 50);
$ok($GLOBALS['wpdb']->release_calls === 1, '12: outer release frees the lock with exactly one RELEASE_LOCK');
$ok(!isset($held()[50]), '12: held registry is clear after the outer release');

// ── 13. Nested with_credit_lock for one user does not deadlock or leak ───────
$reset_held();
$GLOBALS['wpdb'] = new _LockWPDB();
$GLOBALS['wpdb']->get_lock_result = '1';
$inner_ran = false;
$out = $call('with_credit_lock', 60, function () use ($call, &$inner_ran) {
    // Re-enter the lock for the same user from within the outer critical section.
    return $call('with_credit_lock', 60, function () use (&$inner_ran) { $inner_ran = true; return 'INNER_OK'; });
});
$ok($inner_ran === true && $out === 'INNER_OK', '13: nested with_credit_lock runs the inner closure and returns its value');
$only_one_getlock = count(array_filter($GLOBALS['wpdb']->queries, static function ($q) { return strpos($q, 'GET_LOCK') !== false; })) === 1;
$ok($only_one_getlock, '13: the whole nested scope took the DB lock only once');
$ok($GLOBALS['wpdb']->release_calls === 1, '13: the DB lock was released exactly once for the nested scope');
$ok(!isset($held()[60]), '13: no lock left held after a nested with_credit_lock');

echo "\nVES_Usage_Billing credit lock (GET_LOCK mutex): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }

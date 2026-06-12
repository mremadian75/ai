<?php
/**
 * Phase 9A.4 — DB-level idempotency for critical write paths.
 *
 * Proves with the REAL stores:
 *  1. the observation schema declares UNIQUE KEY ws_canonical (additive)
 *  2. a concurrent-like duplicate insert (insert fails on the unique key)
 *     resolves deterministically to the existing row — no double row, caller
 *     still gets an id, accumulation happens exactly once
 *  3. the read-only idempotency migration report detects duplicates/index
 *  4. memory candidate creation from the same source target deduplicates
 *  5. migrations are additive and re-run safe (version-gated)
 *
 * Run: php tests/test-ves-db-idempotency-9a.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
// Temp ABSPATH with an empty upgrade.php stub so create_table() require resolves.
$__abs = sys_get_temp_dir() . '/fiis_idem_9a_' . getmypid() . '/';
@mkdir($__abs . 'wp-admin/includes', 0777, true);
@file_put_contents($__abs . 'wp-admin/includes/upgrade.php', "<?php\n");
if (!defined('ABSPATH')) { define('ABSPATH', $__abs); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
function sanitize_text_field($s){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$s)));}
function sanitize_textarea_field($s){return trim(strip_tags((string)$s));}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function esc_url_raw($s){return trim((string)$s);}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
function current_time($t='mysql',$g=0){return '2026-06-14 10:00:00';}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function dbDelta($s){$GLOBALS['__ddl'][]=$s;return [];}
function get_current_user_id(){return 3;}
function wp_strip_all_tags($s){return strip_tags((string)$s);}
function absint($v){return abs((int)$v);}
function get_user_meta($u,$k,$single=false){return '';}
function update_user_meta($u,$k,$v){return true;}
function apply_filters($t,$v){return $v;}
$GLOBALS['__o']=[]; $GLOBALS['__ddl']=[];

/** wpdb that ENFORCES the ws_canonical unique constraint like MySQL would. */
class UniqueWpdb {
    public $prefix='wp_'; public $insert_id=0; private $auto=0; public $data=[];
    public $insert_attempts = 0;
    public function get_charset_collate(){return '';}
    public function query($s){return true;}
    public function db_version(){return '8.0-mock';}
    public function insert($t,$r,$f=null){
        $this->insert_attempts++;
        if (strpos($t, 'trend_observations') !== false) {
            foreach (($this->data[$t] ?? []) as $row) {
                if ((int)($row['workspace_id'] ?? 0) === (int)($r['workspace_id'] ?? 0)
                    && (string)($row['canonical_hash'] ?? '') === (string)($r['canonical_hash'] ?? '')) {
                    return false; // duplicate key violation
                }
            }
        }
        $this->auto++;$r['id']=$this->auto;$this->data[$t][$this->auto]=$r;$this->insert_id=$this->auto;return 1;
    }
    public function update($t,$r,$w,$f=null,$wf=null){$id=(int)($w['id']??0);if(isset($this->data[$t][$id])){$this->data[$t][$id]=array_merge($this->data[$t][$id],$r);return 1;}return 0;}
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$rep=is_int($a)?(string)$a:"'".addslashes((string)$a)."'";$sql=preg_replace('/%d|%s|%f/',$rep,$sql,1);}return $sql;}
    private function rows($sql){
        if(!preg_match('/FROM (\S+)/',$sql,$m))return [null,[]];
        $table=$m[1];$rows=$this->data[$table]??[];
        $where=substr($sql,strpos($sql,'WHERE')!==false?strpos($sql,'WHERE')+5:strlen($sql));
        $where=preg_split('/ORDER BY| LIMIT| GROUP BY/',$where)[0];
        preg_match_all("/(\w+)\s*=\s*'([^']*)'|(\w+)\s*=\s*(\d+)/",$where,$mm,PREG_SET_ORDER);
        $conds=[];foreach($mm as $p){ if(isset($p[3])&&$p[3]!==''){$conds[$p[3]]=$p[4];} else {$conds[$p[1]]=$p[2];} }
        $out=[];foreach($rows as $r){$okk=true;foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$okk=false;break;}}if($okk)$out[]=$r;}
        return [$table,$out];
    }
    public function get_var($sql){
        if(preg_match('/SELECT COUNT\(\*\) FROM \(SELECT/',$sql)){
            // duplicate-groups subquery for the migration report
            $groups=[];$dups=0;
            foreach(($this->data['wp_ves_trend_observations']??[]) as $r){
                if(($r['canonical_hash']??'')==='')continue;
                $k=$r['workspace_id'].'|'.$r['canonical_hash'];
                $groups[$k]=($groups[$k]??0)+1;
            }
            foreach($groups as $c){ if($c>1)$dups++; }
            return (string)$dups;
        }
        if(preg_match('/COUNT\(\*\) FROM/',$sql)){[$t,$rows]=$this->rows($sql);return (string)count($rows);}
        if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows($sql);return $rows?($rows[0][$sm[1]]??null):null;}
        return null;
    }
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){
        if(stripos($sql,'SHOW INDEX')!==false){
            return [['Key_name'=>'ws_canonical','Non_unique'=>0],['Key_name'=>'canonical_hash','Non_unique'=>1]];
        }
        [$t,$rows]=$this->rows($sql);return array_values($rows);
    }
}
$GLOBALS['wpdb']=new UniqueWpdb();

require_once dirname(__DIR__).'/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-observation-store.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

// ── 1. Schema declares the additive unique index ─────────────────────────────
$src = file_get_contents(dirname(__DIR__).'/includes/class-ves-trend-observation-store.php');
$ok(strpos($src, 'UNIQUE KEY ws_canonical (workspace_id,canonical_hash)') !== false, 'schema declares UNIQUE KEY ws_canonical');
$ok(strpos($src, 'DROP ') === false && strpos($src, 'DELETE FROM') === false, 'observation store contains no destructive DDL/DML');

// ── 2. Concurrent-like replay: duplicate insert resolves to existing row ─────
$obs = ['workspace_id'=>4,'observation_type'=>'keyword','term'=>'island pop-up','platform'=>'tiktok','provider'=>'apify','observed_at'=>'2026-06-01 12:00:00','value_number'=>3.0,'raw_count'=>3];
$id1 = VES_Trend_Observation_Store::create_or_get_observation($obs);
$ok(!is_wp_error($id1) && (int)$id1 > 0, 'first insert succeeds');

// Simulate the race: delete the SELECT-visible row index so the pre-check
// misses, forcing the INSERT path to hit the unique constraint.
$table='wp_ves_trend_observations';
$winner_row = $GLOBALS['wpdb']->data[$table][(int)$id1];
$hidden = $GLOBALS['wpdb']->data[$table];
// Wrap get_var SELECT id to miss once: emulate by temporarily renaming hash, insert, restore is too invasive.
// Instead: call the private insert path implicitly — second create with the SAME
// payload goes through the normal dedup (SELECT finds it). To exercise the RACE
// branch, remove the row from SELECT visibility but keep it for INSERT collision:
class RaceWpdb extends UniqueWpdb {
    public $hide_from_select = null;
    public function get_var($sql){
        if ($this->hide_from_select && preg_match('/SELECT id FROM (\S+) WHERE workspace_id/',$sql)) {
            if ($this->hide_from_select > 0) { $this->hide_from_select--; return null; } // first SELECT misses (race window)
        }
        return parent::get_var($sql);
    }
}
$race = new RaceWpdb();
$race->data = $GLOBALS['wpdb']->data;
$race->insert_id = (int)$id1;
$race->hide_from_select = 1; // pre-check misses once; the post-collision re-select works
$GLOBALS['wpdb'] = $race;
$id2 = VES_Trend_Observation_Store::create_or_get_observation($obs);
$ok(!is_wp_error($id2), 'racing duplicate insert does not error');
$ok((int)$id2 === (int)$id1, 'racing duplicate resolves to the EXISTING row id');
$rows_for_term = 0;
foreach (($GLOBALS['wpdb']->data[$table] ?? []) as $r) { if (($r['term'] ?? '') === 'island pop-up') { $rows_for_term++; } }
$ok($rows_for_term === 1, 'no duplicate row was created under the race');
$final = $GLOBALS['wpdb']->data[$table][(int)$id1];
$ok(abs((float)$final['value_number'] - 6.0) < 0.0001, 'race resolution accumulated the value exactly once (3+3)');

// ── 3. Migration report (read-only) ──────────────────────────────────────────
$report = VES_Trend_Observation_Store::idempotency_migration_report();
$ok(!empty($report['unique_index_present']), 'report sees the unique index');
$ok((int)$report['duplicate_groups'] === 0 && !empty($report['safe_to_index']), 'report finds no duplicate groups');
$ddl_before = count($GLOBALS['__ddl']);
VES_Trend_Observation_Store::idempotency_migration_report();
$ok(count($GLOBALS['__ddl']) === $ddl_before, 'report performs no DDL (dry-run/read-only)');

// ── 4. Memory candidate dedupe (same source target) ──────────────────────────
require_once dirname(__DIR__).'/includes/class-ves-memory-records.php';
$GLOBALS['wpdb']->data['wp_ves_memory_records'] = [];
// Phase 9A.4: brand-context candidates are created with dedupe=true (see
// VES_Brand_Context_Service::create_candidate) — prove the records layer
// collapses same-source-target saves, and that the service passes the flag.
$first = VES_Memory_Records::save_record([
    'workspace_id'=>1,'user_id'=>0,'memory_type'=>'preference','title'=>'Preference',
    'summary'=>'Prefers paper-texture visuals','source_type'=>'insight','source_id'=>'42','dedupe'=>true,
]);
$second = VES_Memory_Records::save_record([
    'workspace_id'=>1,'user_id'=>0,'memory_type'=>'preference','title'=>'Preference',
    'summary'=>'Prefers paper-texture visuals (updated)','source_type'=>'insight','source_id'=>'42','dedupe'=>true,
]);
$bc_src = file_get_contents(dirname(__DIR__).'/includes/class-ves-brand-context-service.php');
$ok(strpos($bc_src, "'dedupe' => true") !== false, 'create_candidate opts into records-layer dedupe');
$ok((int)$first > 0 && (int)$first === (int)$second, 'memory candidate from the same source target dedupes to one record');
$mem_rows = 0;
foreach (($GLOBALS['wpdb']->data['wp_ves_memory_records'] ?? []) as $r) { if (($r['source_id'] ?? '') === '42') { $mem_rows++; } }
$ok($mem_rows === 1, 'exactly one memory row exists for the source target');

// ── 5. Migrations: version-gated, additive, re-run safe ──────────────────────
$GLOBALS['__o']['ves_trend_observations_db_version'] = '';
VES_Trend_Observation_Store::create_table();
$ddl_first = count($GLOBALS['__ddl']);
VES_Trend_Observation_Store::create_table(); // version option now matches → no-op
$ok(count($GLOBALS['__ddl']) === $ddl_first && $ddl_first > 0, 'create_table is version-gated and re-run safe');
$ok(strpos(end($GLOBALS['__ddl']) ?: '', 'CREATE TABLE') === 0, 'migration uses dbDelta CREATE TABLE (additive)');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Phase 3B Part F — VES_Health_Check::verify_schema (read-only live schema check).
 * Self-contained; MockWpdb controls SHOW TABLES / SHOW COLUMNS. No real DB.
 *
 * Run: php tests/test-ves-schema-verification.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
function current_time($t='mysql',$g=0){return '2026-06-07 10:00:00';}

class SchemaWpdb {
    public $prefix='wp_';
    public $tables; public $columns;
    public function __construct($tables,$columns){$this->tables=$tables;$this->columns=$columns;}
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$sql=preg_replace('/%s|%d/',"'".addslashes((string)$a)."'",$sql,1);}return $sql;}
    public function get_var($sql){
        if(preg_match("/SHOW TABLES LIKE '([^']+)'/",$sql,$m)){return in_array($m[1],$this->tables,true)?$m[1]:null;}
        return null;
    }
    public function get_col($sql){
        if(preg_match('/SHOW COLUMNS FROM `?([^`\s]+)`?/',$sql,$m)){return $this->columns[$m[1]]??[];}
        return [];
    }
}

require_once dirname(__DIR__).'/includes/class-ves-health-check.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

// required_schema lists the 7 canonical tables.
$GLOBALS['wpdb']=new SchemaWpdb([],[]);
$req = VES_Health_Check::required_schema();
$ok(count($req)===7 && isset($req['wp_ves_ai_usage_events'],$req['wp_ves_intel_sources'],$req['wp_ves_intel_signals']), 'required_schema lists the 7 canonical tables');
$ok(in_array('pricing_source',$req['wp_ves_ai_usage_events'],true) && in_array('canonical_hash',$req['wp_ves_intel_sources'],true) && in_array('canonical_signal_hash',$req['wp_ves_intel_signals'],true), 'required columns include Phase 1/3 additions');

// All tables + columns present → OK.
$allTables = array_keys($req);
$allCols = [];
foreach ($req as $t=>$cols){$allCols[$t]=array_merge(['id','workspace_id','created_at'],$cols);}
$GLOBALS['wpdb']=new SchemaWpdb($allTables,$allCols);
$r = VES_Health_Check::verify_schema();
$ok($r['status']==='ok' && count($r['tables'])===7, 'verify_schema returns OK when all tables/columns exist');
$ok($r['tables'][0]['exists']===true && $r['tables'][0]['status']==='ok' && empty($r['tables'][0]['missing_columns']), 'per-table status is structured (exists/status/missing_columns)');

// A missing column → WARNING + missing list.
$colsMissing = $allCols;
$colsMissing['wp_ves_intel_sources'] = array_values(array_diff($colsMissing['wp_ves_intel_sources'], ['canonical_hash']));
$GLOBALS['wpdb']=new SchemaWpdb($allTables,$colsMissing);
$r2 = VES_Health_Check::verify_schema();
$ok($r2['status']==='warning', 'verify_schema returns WARNING when a column is missing');
$srcRow = null; foreach($r2['tables'] as $t){ if($t['table']==='wp_ves_intel_sources'){$srcRow=$t;} }
$ok($srcRow && in_array('canonical_hash',$srcRow['missing_columns'],true), 'verify_schema reports the exact missing column');

// A missing table → ERROR.
$someTables = array_values(array_diff($allTables, ['wp_ves_intel_drafts']));
$GLOBALS['wpdb']=new SchemaWpdb($someTables,$allCols);
$r3 = VES_Health_Check::verify_schema();
$ok($r3['status']==='error', 'verify_schema returns ERROR when a table is missing');
$draftRow=null; foreach($r3['tables'] as $t){ if($t['table']==='wp_ves_intel_drafts'){$draftRow=$t;} }
$ok($draftRow && $draftRow['exists']===false && $draftRow['status']==='error', 'missing table reported as exists=false/error');

// Read-only: the verify_schema method body uses SHOW only — no CREATE/ALTER/dbDelta() call.
$hc = file_get_contents(dirname(__DIR__).'/includes/class-ves-health-check.php');
preg_match('/function verify_schema\(\).*?\n    \}/s', $hc, $vm);
$body = $vm[0] ?? '';
$ok(strpos($body,'SHOW TABLES')!==false && strpos($body,'SHOW COLUMNS')!==false, 'verify_schema uses SHOW TABLES/COLUMNS');
$ok(strpos($body,'CREATE TABLE')===false && strpos($body,'ALTER ')===false && strpos($body,'dbDelta(')===false, 'verify_schema is read-only (no CREATE/ALTER/dbDelta call)');

echo "\nVES schema verification tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

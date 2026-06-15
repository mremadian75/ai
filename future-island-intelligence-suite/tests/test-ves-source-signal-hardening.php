<?php
/**
 * Phase 3 — Source/Signal normalization, provenance, dedup, and scoring.
 *
 * Self-contained: WP shims + a dedup-capable in-memory MockWpdb that resolves
 * find_source_by_hash / find_signal_by_hash. No DB, no API calls.
 *
 * Run: php tests/test-ves-source-signal-hardening.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
function sanitize_text_field($s){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$s)));}
function sanitize_textarea_field($s){return trim(strip_tags((string)$s));}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function esc_url_raw($s){return trim((string)$s);}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
function current_time($t='mysql',$g=0){return gmdate('Y-m-d H:i:s');}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function get_current_user_id(){return 0;}
function dbDelta($s){return [];}
$GLOBALS['__o']=[];

class HardeningWpdb {
    public $prefix='wp_'; public $insert_id=0; private $auto=0; public $data=[];
    public function get_charset_collate(){return '';}
    public function query($s){return true;}
    public function insert($t,$r,$f=null){$this->auto++;$r['id']=$this->auto;$this->data[$t][$this->auto]=$r;$this->insert_id=$this->auto;return 1;}
    public function update($t,$r,$w,$f=null,$wf=null){$id=(int)($w['id']??0);if(isset($this->data[$t][$id])){$this->data[$t][$id]=array_merge($this->data[$t][$id],$r);return 1;}return 0;}
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$rep=is_int($a)?(string)$a:"'".addslashes((string)$a)."'";$sql=preg_replace('/%d|%s|%f/',$rep,$sql,1);}return $sql;}
    private function rows_matching($sql){
        if(!preg_match('/FROM (\S+)/',$sql,$m))return [null,[]];
        $table=$m[1];$rows=$this->data[$table]??[];
        // Parse WHERE conditions col = value (value quoted or int)
        $where=substr($sql,strpos($sql,'WHERE')!==false?strpos($sql,'WHERE')+5:strlen($sql));
        $where=preg_split('/ORDER BY| LIMIT/',$where)[0];
        preg_match_all("/(\w+)\s*=\s*'?([^'\s]+)'?/",$where,$mm,PREG_SET_ORDER);
        $conds=[];foreach($mm as $p){ if(ctype_digit($p[1]))continue; $conds[$p[1]]=$p[2]; } // skip 1=1
        $out=[];foreach($rows as $r){$ok=true;foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$ok=false;break;}}if($ok)$out[]=$r;}
        return [$table,$out];
    }
    public function get_var($sql){
        if(preg_match('/COUNT\(\*\) FROM (\S+)/',$sql,$m)){[$t,$rows]=$this->rows_matching($sql);return (string)count($rows);}
        if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows_matching($sql);return $rows?($rows[0][$sm[1]]??null):null;}
        return null;
    }
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows_matching($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){
        [$t,$rows]=$this->rows_matching($sql);
        // Support "GROUP BY col" with COUNT(*) AS c, col AS k (admin breakdowns).
        if(preg_match('/GROUP BY (\w+)/',$sql,$gm) && stripos($sql,'COUNT(*)')!==false){
            $col=$gm[1];$agg=[];
            foreach($rows as $r){$k=(string)($r[$col]??'');$agg[$k]=($agg[$k]??0)+1;}
            $out=[];foreach($agg as $k=>$c){$out[]=['k'=>$k,'c'=>$c];}
            usort($out,function($a,$b){return $b['c']-$a['c'];});
            return $out;
        }
        return array_reverse($rows);
    }
}
$GLOBALS['wpdb']=new HardeningWpdb();

require_once dirname(__DIR__).'/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__).'/includes/class-ves-waterfall-sourcing.php';
require_once dirname(__DIR__).'/includes/class-ves-intelligence-store.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};
$approx=function($a,$b,$e=0.01){return abs((float)$a-(float)$b)<$e;};

// ── Source normalization + hashing ───────────────────────────────────────────
$n = VES_Intelligence_Store::normalize_source(['workspace_id'=>5,'source_type'=>'web','provider'=>'GPT','source_url'=>'https://EXAMPLE.com/a/?utm_source=x&id=9#frag']);
$ok($n['provider']==='openai', 'normalize_source normalizes provider alias (GPT->openai)');
$ok(!empty($n['canonical_hash']) && !empty($n['raw_hash']), 'normalize_source computes canonical_hash + raw_hash');

$cu = VES_Intelligence_Store::canonicalize_url('https://EXAMPLE.com/a/?utm_source=x&id=9#frag');
$ok($cu === 'https://example.com/a?id=9', 'canonicalize_url lowercases host, drops fragment + tracking params, keeps real params');

// Two URLs differing only by tracking params dedup to the same source.
$s1 = VES_Intelligence_Store::create_or_get_source(['workspace_id'=>5,'source_type'=>'web','provider'=>'google','source_url'=>'https://site.com/p?utm_campaign=a&q=1']);
$s2 = VES_Intelligence_Store::create_or_get_source(['workspace_id'=>5,'source_type'=>'web','provider'=>'google','source_url'=>'https://site.com/p?utm_campaign=DIFFERENT&q=1&fbclid=zz']);
$ok(is_int($s1) && $s1>0, 'create_or_get_source creates a source');
$ok($s1 === $s2, 'same canonical URL (tracking params stripped) dedups to ONE source');

// Different real content → different source.
$s3 = VES_Intelligence_Store::create_or_get_source(['workspace_id'=>5,'source_type'=>'web','provider'=>'google','source_url'=>'https://site.com/other?q=1']);
$ok($s3 !== $s1, 'different canonical URL creates a distinct source');

// External-id hashing when no URL.
$e1 = VES_Intelligence_Store::create_or_get_source(['workspace_id'=>5,'source_type'=>'social','provider'=>'reddit','external_id'=>'post_123']);
$e2 = VES_Intelligence_Store::create_or_get_source(['workspace_id'=>5,'source_type'=>'social','provider'=>'reddit','external_id'=>'post_123']);
$ok($e1>0 && $e1===$e2, 'missing URL but stable external_id still hashes + dedups');

// Cross-workspace isolation: same canonical hash, different workspace → different record.
$w2 = VES_Intelligence_Store::create_or_get_source(['workspace_id'=>6,'source_type'=>'web','provider'=>'google','source_url'=>'https://site.com/p?q=1']);
$ok($w2 !== $s1, 'same canonical URL in a different workspace is NOT deduped (tenant isolation)');

// Metadata scrubbing on source.
$sm = VES_Intelligence_Store::create_or_get_source(['workspace_id'=>5,'source_type'=>'api','provider'=>'openai','external_id'=>'k1','metadata'=>['api_key'=>'sk-LEAK123456789','raw_response'=>'big blob','note'=>'ok']]);
$row = $GLOBALS['wpdb']->data['wp_ves_intel_sources'][$sm] ?? [];
$ok(strpos((string)($row['metadata']??''),'sk-LEAK')===false && strpos((string)($row['metadata']??''),'big blob')===false, 'source metadata scrubs api_key/raw_response');

$ok(is_wp_error(VES_Intelligence_Store::create_or_get_source(['source_type'=>'web','provider'=>'google'])), 'source without workspace_id rejected');

// ── Signal normalization + scoring + dedup ───────────────────────────────────
$ok(is_wp_error(VES_Intelligence_Store::create_signal(['workspace_id'=>5,'signal_type'=>'bogus','title'=>'x'])), 'invalid signal_type rejected');

$scoreFresh = VES_Intelligence_Store::score_signal_quality(['signal_type'=>'trend','title'=>'t','occurred_at'=>gmdate('Y-m-d H:i:s'),'source_id'=>3,'value_number'=>42], ['provider'=>'google','source_url'=>'https://x.com']);
$ok($scoreFresh['freshness_score']>=95, 'score: fresh signal has high freshness');
$ok($scoreFresh['specificity_score']>=80, 'score: source_id+value_number+url => high specificity');
$ok($scoreFresh['confidence_score']>40 && $scoreFresh['confidence_score']<=100, 'score: trusted provider raises confidence (clamped 0-100)');
$scoreOld = VES_Intelligence_Store::score_signal_quality(['signal_type'=>'trend','title'=>'t','occurred_at'=>gmdate('Y-m-d H:i:s', time()-120*86400)]);
$ok($scoreOld['freshness_score']<=0.01, 'score: 120-day-old signal clamps freshness to 0');
$ok($scoreFresh['final_quality_score']>=0 && $scoreFresh['final_quality_score']<=100, 'score: final_quality_score clamped 0-100');

// Dedup: identical signal twice → same id + recurrence bumped.
$sourceId = $s1;
$payload = ['workspace_id'=>5,'source_id'=>$sourceId,'signal_type'=>'trend','title'=>'Rising topic A','value_number'=>10,'occurred_at'=>'2026-06-01 00:00:00'];
$g1 = VES_Intelligence_Store::create_or_get_signal($payload);
$g2 = VES_Intelligence_Store::create_or_get_signal($payload);
$ok(is_int($g1)&&$g1>0, 'create_or_get_signal creates a signal');
$ok($g1===$g2, 'identical signal dedups to the same id');
$sigRow = $GLOBALS['wpdb']->data['wp_ves_intel_signals'][$g1] ?? [];
$ok((int)($sigRow['recurrence_count']??1)===2, 'duplicate sighting increments recurrence_count');
$ok((int)($sigRow['source_id']??0)===$sourceId, 'source-linked signal keeps source_id');

// Different value → different signal.
$g3 = VES_Intelligence_Store::create_or_get_signal(array_merge($payload, ['value_number'=>99]));
$ok($g3!==$g1, 'different value creates a distinct signal');

$ok(is_wp_error(VES_Intelligence_Store::create_or_get_signal(['signal_type'=>'trend','title'=>'x'])), 'signal without workspace_id rejected');

// Signal metadata scrubbing.
$sg = VES_Intelligence_Store::create_signal(['workspace_id'=>5,'signal_type'=>'mention','title'=>'m','metadata'=>['token'=>'Bearer abc.def','prompt'=>'leak','extraction_method'=>'regex']]);
$sgRow = $GLOBALS['wpdb']->data['wp_ves_intel_signals'][$sg] ?? [];
$ok(strpos((string)($sgRow['metadata']??''),'Bearer abc')===false && strpos((string)($sgRow['metadata']??''),'leak')===false, 'signal metadata scrubs token/prompt');

// ── Part F: provenance linking through ingest_bundle ─────────────────────────
$b = VES_Intelligence_Store::ingest_bundle([
    'workspace_id'=>8,'user_id'=>2,
    'source'=>['source_type'=>'social','provider'=>'apify','external_id'=>'run_77','retrieval_method'=>'apify_scrape'],
    'signals'=>[['signal_type'=>'trend','title'=>'Spike','extraction_method'=>'deterministic_synthesis']],
    'evidence'=>[['evidence_type'=>'observation','text'=>'views up','observed_at'=>'2026-06-07 00:00:00']],
    'insight'=>['insight_type'=>'trend','title'=>'Rising','status'=>'draft'],
]);
$ok($b['source_id']>0 && count($b['signal_ids'])===1 && count($b['evidence_ids'])===1, 'ingest_bundle creates linked source/signal/evidence');
$srcRow = $GLOBALS['wpdb']->data['wp_ves_intel_sources'][$b['source_id']] ?? [];
$ok(!empty($srcRow['canonical_hash']) && strpos((string)$srcRow['metadata'],'apify_scrape')!==false, 'Part F: bundle source has canonical_hash + retrieval_method provenance');
$sigRow2 = $GLOBALS['wpdb']->data['wp_ves_intel_signals'][$b['signal_ids'][0]] ?? [];
$ok((int)($sigRow2['source_id']??0)===$b['source_id'] && strpos((string)$sigRow2['metadata'],'source_provider')!==false, 'Part F: bundle signal links source_id + carries source_provider');
$ok(!empty($sigRow2['canonical_signal_hash']), 'Part F: bundle signal has canonical_signal_hash');
$eviRow = $GLOBALS['wpdb']->data['wp_ves_intel_evidences'][$b['evidence_ids'][0]] ?? [];
$ok((int)($eviRow['source_id']??0)===$b['source_id'] && (int)($eviRow['signal_id']??0)===$b['signal_ids'][0], 'Part F: bundle evidence links source_id + signal_id');

// Provenance diagnostics structure.
$ph = VES_Intelligence_Store::provenance_health(0);
$ok(isset($ph['sources_missing_provider'],$ph['signals_missing_source'],$ph['insights_missing_evidence']), 'provenance_health returns structured counters');
$pb = VES_Intelligence_Store::provider_breakdown(0);
$ok(is_array($pb) && ($pb['apify'] ?? 0) >= 1, 'provider_breakdown counts sources by provider');

// ── Part D integration: source_with_waterfall (existing-first, then create) ──
$w1 = VES_Intelligence_Store::source_with_waterfall(['workspace_id'=>12,'source_type'=>'web','provider'=>'google','source_url'=>'https://wf.com/a?q=1','module'=>'deep_trend_finder']);
$ok($w1['source_id']>0 && $w1['provider']==='create', 'source_with_waterfall creates a new source via the cheap tier');
$w2 = VES_Intelligence_Store::source_with_waterfall(['workspace_id'=>12,'source_type'=>'web','provider'=>'google','source_url'=>'https://wf.com/a?q=1&utm_source=z','module'=>'deep_trend_finder']);
$ok($w2['source_id']===$w1['source_id'] && $w2['provider']==='existing_db', 'source_with_waterfall resolves an existing source via the FREE tier (no create)');
$ok(!empty($w2['attempts']) && $w2['attempts'][0]['provider']==='existing_db' && $w2['attempts'][0]['cost_tier']==='free', 'source_with_waterfall returns a provenance attempt trail');

// ── Phase 3B Part B: ingest_bundle threads usage_event_id into metadata ──────
$bk = VES_Intelligence_Store::ingest_bundle([
    'workspace_id'=>14,'user_id'=>1,'usage_event_id'=>9876,
    'source'=>['source_type'=>'social','provider'=>'apify','external_id'=>'bk_run'],
    'signals'=>[['signal_type'=>'trend','title'=>'BK signal']],
    'evidence'=>[['evidence_type'=>'observation','text'=>'bk evidence']],
]);
$bkSrc = $GLOBALS['wpdb']->data['wp_ves_intel_sources'][$bk['source_id']] ?? [];
$bkSig = $GLOBALS['wpdb']->data['wp_ves_intel_signals'][$bk['signal_ids'][0]] ?? [];
$bkEvi = $GLOBALS['wpdb']->data['wp_ves_intel_evidences'][$bk['evidence_ids'][0]] ?? [];
$ok(strpos((string)$bkSrc['metadata'],'9876')!==false && strpos((string)$bkSrc['metadata'],'usage_event_id')!==false, 'Part B: ingest_bundle stores usage_event_id in SOURCE metadata');
$ok(strpos((string)$bkSig['metadata'],'9876')!==false, 'Part B: ingest_bundle stores usage_event_id in SIGNAL metadata');
$ok(strpos((string)$bkEvi['metadata'],'9876')!==false, 'Part B: ingest_bundle stores usage_event_id in EVIDENCE metadata');
// No usage_event_id given → metadata must not contain a fake one.
$bk0 = VES_Intelligence_Store::ingest_bundle(['workspace_id'=>14,'source'=>['source_type'=>'web','provider'=>'google','external_id'=>'no_uid']]);
$bk0Src = $GLOBALS['wpdb']->data['wp_ves_intel_sources'][$bk0['source_id']] ?? [];
$ok(strpos((string)$bk0Src['metadata'],'usage_event_id')===false, 'Part B: no usage_event_id is faked when unknown');

// ── Part G: backlink_health structure ────────────────────────────────────────
$bh = VES_Intelligence_Store::backlink_health(0);
$ok(isset($bh['sources_with_usage_event'],$bh['signals_with_usage_event'],$bh['evidence_with_usage_event']), 'Part G: backlink_health returns structured counters');

echo "\nVES source/signal hardening tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);

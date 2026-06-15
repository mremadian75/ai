<?php
/** v0.4.1 canonical REST route registration and admin-gate contract. */
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
class WP_Error { private $c,$m,$d; function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} function get_error_data($c=''){return $this->d;} }
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s));} function sanitize_text_field($s){return trim(strip_tags((string)$s));} function sanitize_textarea_field($s){return trim(strip_tags((string)$s));} function is_user_logged_in(){return true;} function current_user_can($cap){return $cap==='manage_options';} function get_current_user_id(){return 55;} function rest_ensure_response($d){return $d;}
$GLOBALS['routes']=[]; function register_rest_route($ns,$route,$args){$GLOBALS['routes'][$ns.$route]=$args;return true;}
require_once dirname(__DIR__).'/includes/class-ves-canonical-rest-controller.php';
$checks=0;$fails=[];function ok($c,$m){global$checks,$fails;$checks++;if(!$c){$fails[]=$m;fwrite(STDERR,"FAIL: $m\n");}}
VES_Canonical_REST_Controller::register_routes();
$required=['fi/v1/workspaces/current','fi/v1/runs/(?P<id>\d+)','fi/v1/runs/(?P<id>\d+)/timeline','fi/v1/outcomes','fi/v1/decision-map/(?P<target_type>[a-zA-Z0-9_\-]+)/(?P<target_id>\d+)'];
foreach($required as$r){ok(isset($GLOBALS['routes'][$r]),"route registered: $r"); ok(is_callable($GLOBALS['routes'][$r][0]['permission_callback']??null),"route has permission callback: $r");}
ok(VES_Canonical_REST_Controller::can_access()===true,'admin-gated access allows manage_options user');
$ws=VES_Canonical_REST_Controller::current_workspace(); ok(($ws['workspace_id']??0)===55&&($ws['resolved_server_side']??false)===true,'current workspace resolves server-side fallback, not client-supplied workspace');
if($fails){fwrite(STDOUT,sprintf("v0.4.1 canonical REST checks: %d passed, %d failed\n",$checks-count($fails),count($fails)));exit(1);} fwrite(STDOUT,"v0.4.1 canonical REST checks passed: $checks / $checks\n");

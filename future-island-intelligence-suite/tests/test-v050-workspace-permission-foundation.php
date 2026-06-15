<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!class_exists('WP_Error')) { class WP_Error { private $c,$m,$d; function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} function get_error_data(){return $this->d;} } }
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s));}
function is_user_logged_in(){return !empty($GLOBALS['logged']);}
function current_user_can($cap){return !empty($GLOBALS['admin']) && $cap==='manage_options';}
function get_current_user_id(){return (int)($GLOBALS['uid']??0);} 
function apply_filters($tag,$value){return $value;}
require_once dirname(__DIR__) . '/includes/class-ves-workspace-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-permission-service.php';
$checks=0;$fails=[];function ok($c,$m){global$checks,$fails;$checks++;if(!$c){$fails[]=$m;fwrite(STDERR,"FAIL: $m\n");}}
$GLOBALS['logged']=true; $GLOBALS['admin']=true; $GLOBALS['uid']=123;
ok(VES_Workspace_Service::current_workspace_id()===123,'current workspace falls back to current user id');
$resolved=VES_Workspace_Service::resolve_for_request(['workspace_id'=>999]);
ok(($resolved['workspace_id']??0)===123 && !isset($resolved['client_workspace_trusted']),'client-supplied workspace id is ignored');
ok(VES_Permission_Service::can_view_workspace(123)===true,'admin can view resolved workspace');
ok(VES_Permission_Service::can_run_playbook(999)===true,'admin compatibility permits cross-workspace operator access');
$GLOBALS['admin']=false;
ok(VES_Permission_Service::can_run_playbook(123)===false,'non-admin compatibility wrapper fails closed without membership filter');
ok(VES_Permission_Service::can_view_workspace(999)===false,'non-admin invalid workspace blocked');
$GLOBALS['logged']=false;
ok(VES_Permission_Service::can_view_workspace(123)===false,'unauthorized user blocked');
if($fails){echo 'v0.5.0 workspace/permission foundation: '.($checks-count($fails))." passed, ".count($fails)." failed\n";exit(1);}echo "v0.5.0 workspace/permission foundation passed: $checks / $checks\n";

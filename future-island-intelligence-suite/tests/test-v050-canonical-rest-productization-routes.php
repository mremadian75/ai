<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!class_exists('WP_Error')) { class WP_Error { private $c,$m,$d; function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} function get_error_data($c=''){return $this->d;} } }
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s));} function sanitize_text_field($s){return trim(strip_tags((string)$s));} function sanitize_textarea_field($s){return trim(strip_tags((string)$s));}
function is_user_logged_in(){return true;} function current_user_can($cap){return $cap==='manage_options';} function get_current_user_id(){return 42;} function rest_ensure_response($d){return $d;}
$GLOBALS['routes']=[]; function register_rest_route($ns,$route,$args){$GLOBALS['routes'][$ns.$route]=$args;return true;}
require_once dirname(__DIR__).'/includes/class-ves-permission-service.php';
require_once dirname(__DIR__).'/includes/class-ves-workspace-service.php';
require_once dirname(__DIR__).'/includes/class-ves-canonical-rest-controller.php';
$checks=0;$fails=[];function ok($c,$m){global$checks,$fails;$checks++;if(!$c){$fails[]=$m;fwrite(STDERR,"FAIL: $m\n");}}
VES_Canonical_REST_Controller::register_routes();
$required=['fi/v1/playbooks/brand-market-xray','fi/v1/social/query','fi/v1/social/post-analysis','fi/v1/social/profile-analysis','fi/v1/insights/(?P<id>\d+)/review','fi/v1/insights/(?P<id>\d+)/brief','fi/v1/briefs/(?P<id>\d+)/drafts','fi/v1/review-queue','fi/v1/runs/(?P<id>\d+)/decision-report'];
foreach($required as$r){ok(isset($GLOBALS['routes'][$r]),"productization route registered: $r"); ok(is_callable($GLOBALS['routes'][$r][0]['permission_callback']??null),"route has permission callback: $r");}
ok(VES_Canonical_REST_Controller::can_access()===true,'workspace-safe admin access allowed');
$ws=VES_Canonical_REST_Controller::current_workspace(); ok(($ws['workspace_id']??0)===42 && !empty($ws['permissions']['can_run_playbook']),'current workspace includes permission map');
if($fails){fwrite(STDOUT,sprintf("v0.5.0 productization REST checks: %d passed, %d failed\n",$checks-count($fails),count($fails)));exit(1);} fwrite(STDOUT,"v0.5.0 productization REST checks passed: $checks / $checks\n");

<?php
/**
 * Admin render smoke test. Simulates a WordPress admin request with Fluent Boards ABSENT
 * (the exact scenario in the bug report) and renders every tab, asserting the page builds
 * without a fatal/exception. Run via tests/run.php.
 */

// Render-only WordPress shims, layered on top of bootstrap.php (all guarded).
if (!function_exists('esc_attr')) { function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('esc_url')) { function esc_url($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('esc_js')) { function esc_js($s){ return addslashes((string)$s); } }
if (!function_exists('esc_html__')) { function esc_html__($s,$d=null){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('esc_attr__')) { function esc_attr__($s,$d=null){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('esc_html_e')) { function esc_html_e($s,$d=null){ echo htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('esc_attr_e')) { function esc_attr_e($s,$d=null){ echo htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('_e')) { function _e($s,$d=null){ echo $s; } }
if (!function_exists('checked')) { function checked($a,$b=true,$e=true){ $r=((string)$a===(string)$b)?' checked="checked"':''; if($e)echo $r; return $r; } }
if (!function_exists('selected')) { function selected($a,$b=true,$e=true){ $r=((string)$a===(string)$b)?' selected="selected"':''; if($e)echo $r; return $r; } }
if (!function_exists('disabled')) { function disabled($a,$b=true,$e=true){ $r=((bool)$a===(bool)$b)?' disabled="disabled"':''; if($e)echo $r; return $r; } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a=-1,$n='_wpnonce',$ref=true,$e=true){ $s='<input type="hidden" name="'.esc_attr($n).'" value="nonce">'; if($e)echo $s; return $s; } }
if (!function_exists('admin_url')) { function admin_url($p=''){ return 'http://example.test/wp-admin/'.$p; } }
if (!function_exists('add_query_arg')) {
    function add_query_arg(...$args){
        if (is_array($args[0])) { $params=$args[0]; $url=$args[1]??''; }
        else { $params=[$args[0]=>$args[1]]; $url=$args[2]??''; }
        $sep = strpos($url,'?')!==false ? '&' : '?';
        return $url.$sep.http_build_query($params);
    }
}
if (!function_exists('current_user_can')) { function current_user_can($c){ return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id(){ return 1; } }
if (!function_exists('get_users')) { function get_users($a=[]){ return []; } }
if (!function_exists('get_userdata')) { function get_userdata($id){ return false; } }
if (!function_exists('post_type_exists')) { function post_type_exists($t){ return false; } }
if (!function_exists('wp_count_posts')) { function wp_count_posts($t){ return (object)['publish'=>0]; } }
if (!function_exists('get_posts')) { function get_posts($a=[]){ return []; } }
if (!function_exists('wp_next_scheduled')) { function wp_next_scheduled($h){ return false; } }
if (!function_exists('number_format_i18n')) { function number_format_i18n($n,$d=0){ return number_format((float)$n,$d); } }
if (!function_exists('get_locale')) { function get_locale(){ return 'en_US'; } }

if (!defined('FBAIA_URL')) { define('FBAIA_URL', 'http://example.test/wp-content/plugins/fluent-boards-ai-automation/'); }

// Load the remaining classes needed to render (helpers/adapter/etc. came from bootstrap).
$inc = dirname(__DIR__) . '/includes/';
foreach (['knowledge-library','suggestion-store','audit-trail','automation-playbooks','health-check','insights','recipes','admin'] as $c) {
    require_once $inc . 'class-fbaia-' . $c . '.php';
}

echo "Admin render (Fluent Boards absent)\n";

fbaia_reset_options();
$s = FBAIA_Helpers::defaults();
$s['company_profile'] = 'Acme';
$s['team_directory'] = 'Sara | s@a.com | Manager';
$s['api_key'] = 'sk-x';
update_option(FBAIA_OPTION, $s, false);

$admin = new FBAIA_Admin();
foreach (['dashboard', 'provider', 'intelligence', 'automations', 'memory', 'review', 'diagnostics'] as $tab) {
    $_GET = ['page' => 'fluent-boards-ai-automation', 'tab' => $tab];
    $threw = false;
    $html = '';
    ob_start();
    try {
        $admin->page();
    } catch (Throwable $e) {
        $threw = true;
        $html = 'THREW: ' . $e->getMessage();
    }
    $out = ob_get_clean();
    $ok = !$threw && strlen($out) > 500 && strpos($out, 'fbaia-wrap') !== false;
    fbaia_assert("tab '$tab' renders without fatal" . ($threw ? " ($html)" : ''), $ok);
}

// Health diagnostics must not throw when Fluent Boards is missing.
$threw = false;
try {
    $h = FBAIA_Health_Check::diagnostics();
} catch (Throwable $e) {
    $threw = true;
}
fbaia_assert('health diagnostics run without Fluent Boards', !$threw && !empty($h['checks']));

$_GET = [];

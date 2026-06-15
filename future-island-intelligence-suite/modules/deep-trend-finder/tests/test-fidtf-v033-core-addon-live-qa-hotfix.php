<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIDTF_PLUGIN_DIR')) { define('FIDTF_PLUGIN_DIR', dirname(__DIR__) . '/'); }
if (!defined('FIDTF_PLUGIN_URL')) { define('FIDTF_PLUGIN_URL', 'https://example.test/wp-content/plugins/future-island-deep-trend-finder-addon/'); }
if (!defined('FIDTF_VERSION')) { define('FIDTF_VERSION', '0.3.3-core-addon-live-qa-hotfix'); }
if (!defined('FI_DTF_ENABLE_DEEP_VIDEO')) { define('FI_DTF_ENABLE_DEEP_VIDEO', false); }

class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function __($str, $domain = null) { return $str; }
function add_action() { return true; }
function add_filter() { return true; }
function add_shortcode() { return true; }
function wp_register_style() { return true; }
function wp_register_script() { return true; }
function get_option($key, $default = false) { return $GLOBALS['__fidtf_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['__fidtf_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['__fidtf_options'][$key]); return true; }
function get_post($id) { return $GLOBALS['__fidtf_posts'][$id] ?? null; }
function get_page_by_path($slug) { foreach ($GLOBALS['__fidtf_posts'] as $post) { if (($post->post_name ?? '') === $slug && ($post->post_status ?? '') !== 'trash') { return $post; } } return null; }
function wp_update_post($row) { $id = (int) ($row['ID'] ?? 0); if ($id && isset($GLOBALS['__fidtf_posts'][$id])) { foreach ($row as $k => $v) { if ($k !== 'ID') { $GLOBALS['__fidtf_posts'][$id]->$k = $v; } } return $id; } return new WP_Error('not_found', 'not found'); }
function wp_insert_post($row, $wp_error = false) {
    if (!empty($GLOBALS['__fidtf_force_insert_error'])) { return new WP_Error('insert_failed', 'insert failed'); }
    $id = (int) ($GLOBALS['__fidtf_next_post_id'] ?? 101);
    $GLOBALS['__fidtf_next_post_id'] = $id + 1;
    $post = (object) array_merge(['ID' => $id], $row);
    $GLOBALS['__fidtf_posts'][$id] = $post;
    return $id;
}

$GLOBALS['__fidtf_options'] = [];
$GLOBALS['__fidtf_posts'] = [];
$GLOBALS['__fidtf_next_post_id'] = 101;
require_once dirname(__DIR__) . '/includes/class-fidtf-plugin.php';
$checks = 0;
function ok($condition, $message) { global $checks; $checks++; if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$GLOBALS['__fidtf_force_insert_error'] = true;
$id = FIDTF_Plugin::maybe_create_frontend_page();
ok($id === 0, 'page creation returns 0 when wp_insert_post returns WP_Error');
ok((int) get_option('fidtf_page_id', 0) === 0, 'failed insert does not store bogus fidtf_page_id');

$GLOBALS['__fidtf_force_insert_error'] = false;
$id = FIDTF_Plugin::maybe_create_frontend_page();
ok($id === 101, 'page creation returns inserted page id');
ok((int) get_option('fidtf_page_id', 0) === 101, 'successful insert stores fidtf_page_id');
ok(strpos($GLOBALS['__fidtf_posts'][101]->post_content, '[future_island_deep_trend_finder]') !== false, 'created page contains shortcode');

$GLOBALS['__fidtf_posts'][101]->post_content = 'User-edited content without shortcode';
$id2 = FIDTF_Plugin::maybe_create_frontend_page();
ok($id2 === 101, 'existing user-edited page is reused');
ok(get_option('fidtf_page_shortcode_missing', 0) === 1, 'missing shortcode is flagged');
ok($GLOBALS['__fidtf_posts'][101]->post_content === 'User-edited content without shortcode', 'existing user-edited content is not overwritten without force');
FIDTF_Plugin::recreate_frontend_page(true);
ok($GLOBALS['__fidtf_posts'][101]->post_content === '[future_island_deep_trend_finder]', 'force recreate restores shortcode');

fwrite(STDOUT, "FIDTF v0.3.3 core/add-on live QA hotfix checks passed: {$checks} / {$checks}\n");

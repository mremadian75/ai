<?php
/**
 * Minimal, dependency-free WordPress shim for running the plugin's unit checks on the
 * command line (`php tests/run.php`). It mocks only the WordPress functions the tested
 * code touches. This is intentionally NOT PHPUnit so it runs on any shared host with PHP.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!defined('FBAIA_OPTION')) {
    define('FBAIA_OPTION', 'fbaia_settings');
}
if (!defined('FBAIA_LOG_OPTION')) {
    define('FBAIA_LOG_OPTION', 'fbaia_logs');
}
if (!defined('FBAIA_VERSION')) {
    define('FBAIA_VERSION', 'test');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

$GLOBALS['__fbaia_options'] = [];

if (!function_exists('get_option')) {
    function get_option($key, $default = false)
    {
        return array_key_exists($key, $GLOBALS['__fbaia_options']) ? $GLOBALS['__fbaia_options'][$key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value, $autoload = null)
    {
        $GLOBALS['__fbaia_options'][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($key)
    {
        unset($GLOBALS['__fbaia_options'][$key]);
        return true;
    }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        if (is_object($args)) {
            $args = get_object_vars($args);
        } elseif (!is_array($args)) {
            $args = [];
        }
        return array_merge($defaults, $args);
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        $str = (string) $str;
        $str = strip_tags($str);
        $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
        return trim($str);
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str)
    {
        return trim(strip_tags((string) $str));
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email)
    {
        $email = trim((string) $email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return trim((string) $url);
    }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data)
    {
        return (string) $data;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false)
    {
        $string = strip_tags((string) $string);
        return trim($string);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('absint')) {
    function absint($n)
    {
        return abs((int) $n);
    }
}
if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0)
    {
        return gmdate('Y-m-d H:i:s');
    }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($key = '')
    {
        return 'Test Site';
    }
}
if (!function_exists('did_action')) {
    function did_action($hook)
    {
        return 0;
    }
}
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4()
    {
        return sprintf('%04x%04x-test-uuid', random_int(0, 0xffff), random_int(0, 0xffff));
    }
}
if (!function_exists('wp_timezone')) {
    function wp_timezone()
    {
        return new DateTimeZone('UTC');
    }
}
if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string()
    {
        return 'UTC';
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public $message;
        public $data;
        public function __construct($code = '', $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_message()
        {
            return $this->message;
        }
        public function get_error_code()
        {
            return $this->code;
        }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return 1;
    }
}

// Minimal WP_REST_Request / WP_REST_Response stand-ins for controller-level checks.
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $params;
        public function __construct($params = [])
        {
            $this->params = is_array($params) ? $params : [];
        }
        public function get_param($key)
        {
            return array_key_exists($key, $this->params) ? $this->params[$key] : null;
        }
        public function set_param($key, $value)
        {
            $this->params[$key] = $value;
        }
        public function get_params()
        {
            return $this->params;
        }
        public function get_json_params()
        {
            return $this->params;
        }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;
        public function __construct($data = null, $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

// Stub for FBAIA_Plugin::reschedule_events() so settings saves don't fatal in isolation.
if (!class_exists('FBAIA_Plugin')) {
    class FBAIA_Plugin
    {
        public static function reschedule_events($settings = null)
        {
        }
    }
}

// ---------------------------------------------------------------------------
// Tiny assertion framework
// ---------------------------------------------------------------------------
$GLOBALS['__fbaia_pass'] = 0;
$GLOBALS['__fbaia_fail'] = 0;

function fbaia_reset_options()
{
    $GLOBALS['__fbaia_options'] = [];
}

function fbaia_assert($label, $condition)
{
    if ($condition) {
        $GLOBALS['__fbaia_pass']++;
        echo "  PASS  $label\n";
    } else {
        $GLOBALS['__fbaia_fail']++;
        echo "  FAIL  $label\n";
    }
}

function fbaia_test_summary()
{
    $pass = $GLOBALS['__fbaia_pass'];
    $fail = $GLOBALS['__fbaia_fail'];
    echo "\n$pass passed, $fail failed\n";
    return $fail === 0 ? 0 : 1;
}

$plugin_dir = dirname(__DIR__);
require_once $plugin_dir . '/includes/class-fbaia-helpers.php';
require_once $plugin_dir . '/includes/class-fbaia-fluentboards-adapter.php';
require_once $plugin_dir . '/includes/class-fbaia-openai-client.php';
require_once $plugin_dir . '/includes/class-fbaia-context-builder.php';
require_once $plugin_dir . '/includes/class-fbaia-usage-tracker.php';
require_once $plugin_dir . '/includes/class-fbaia-logger.php';

<?php
/**
 * Test bootstrap: minimal WordPress stubs. The full WP test suite is heavy
 * and adds nothing here — the plugin's logic is testable against a small,
 * honest surface of WP functions that record their calls.
 */

error_reporting(E_ALL);

define('ABSPATH', sys_get_temp_dir() . '/');
define('KILDEN_WP_VERSION', 'test');
define('KILDEN_WP_DIR', dirname(__DIR__) . '/');

// --- state registry the stubs read/write -----------------------------------

$GLOBALS['kilden_test'] = array(
    'options'      => array(),
    'actions'      => array(),
    'filters'      => array(),
    'remote_posts' => array(),
    'remote_response' => null,
    'current_user' => null,
    'rest_routes'  => array(),
    'nocache_calls' => 0,
);

function kilden_test_reset(): void
{
    $GLOBALS['kilden_test'] = array(
        'options'      => array(),
        'actions'      => array(),
        'filters'      => array(),
        'remote_posts' => array(),
        'remote_response' => null,
        'current_user' => null,
        'rest_routes'  => array(),
        'nocache_calls' => 0,
    );
    Kilden_Settings::reset_cache();
    Kilden_Client_Factory::reset();
}

// --- WordPress function stubs ----------------------------------------------

function get_option($name, $default = false)
{
    return $GLOBALS['kilden_test']['options'][$name] ?? $default;
}

function update_option($name, $value)
{
    $GLOBALS['kilden_test']['options'][$name] = $value;

    return true;
}

function delete_option($name)
{
    unset($GLOBALS['kilden_test']['options'][$name]);

    return true;
}

function add_action($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['kilden_test']['actions'][$hook][] = $callback;
}

function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['kilden_test']['filters'][$hook][] = $callback;
}

function apply_filters($hook, $value, ...$args)
{
    foreach ($GLOBALS['kilden_test']['filters'][$hook] ?? array() as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}

function sanitize_text_field($str)
{
    return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $str)));
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function esc_url_raw($url)
{
    return filter_var((string) $url, FILTER_SANITIZE_URL);
}

function untrailingslashit($value)
{
    return rtrim((string) $value, '/');
}

function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options, $depth);
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function rest_url($path = '')
{
    return 'https://store.example/wp-json/' . ltrim((string) $path, '/');
}

function nocache_headers()
{
    $GLOBALS['kilden_test']['nocache_calls']++;
}

function register_rest_route($ns, $route, $args)
{
    $GLOBALS['kilden_test']['rest_routes'][$ns . $route] = $args;
}

function wp_get_current_user()
{
    return $GLOBALS['kilden_test']['current_user'];
}

function get_user_by($field, $value)
{
    return $GLOBALS['kilden_test']['current_user'];
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function wp_remote_post($url, $args = array())
{
    $GLOBALS['kilden_test']['remote_posts'][] = array('url' => $url, 'args' => $args);

    return $GLOBALS['kilden_test']['remote_response'] ?? array(
        'response' => array('code' => 200),
        'body'     => '{"status":"ok"}',
        'headers'  => array(),
    );
}

function wp_remote_retrieve_response_code($response)
{
    return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body($response)
{
    return $response['body'] ?? '';
}

function wp_remote_retrieve_headers($response)
{
    return $response['headers'] ?? array();
}

class WP_Error
{
    /** @var string */
    private $message;

    public function __construct(string $code = '', string $message = '')
    {
        $this->message = $message;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

class WP_REST_Response
{
    /** @var mixed */
    public $data;

    /** @var int */
    public $status;

    /** @var array<string, string> */
    public $headers = array();

    /** @param mixed $data */
    public function __construct($data = null, int $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function header(string $name, string $value = ''): void
    {
        $this->headers[$name] = $value;
    }
}

class WP_User
{
    /** @var int */
    public $ID;

    /** @var string */
    public $user_email = '';

    /** @var string */
    public $display_name = '';

    public function __construct(int $id, string $email = '', string $name = '')
    {
        $this->ID = $id;
        $this->user_email = $email;
        $this->display_name = $name;
    }
}

// --- plugin under test -------------------------------------------------------

require KILDEN_WP_DIR . 'includes/vendor-kilden/autoload.php';
require KILDEN_WP_DIR . 'includes/class-kilden-settings.php';
require KILDEN_WP_DIR . 'includes/class-kilden-wp-transport.php';
require KILDEN_WP_DIR . 'includes/class-kilden-client-factory.php';
require KILDEN_WP_DIR . 'includes/class-kilden-snippet.php';
require KILDEN_WP_DIR . 'includes/class-kilden-identity.php';
require KILDEN_WP_DIR . 'includes/class-kilden-woocommerce.php';

require __DIR__ . '/support/FakeOrder.php';
require __DIR__ . '/support/RecordingTransport.php';

// --- WooCommerce stubs -------------------------------------------------------

function wc_get_order($id)
{
    return $GLOBALS['kilden_test']['orders'][$id] ?? false;
}

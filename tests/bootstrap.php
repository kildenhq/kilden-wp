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
    'login_cookie_user' => null,
    'http_origin'  => null,
    'rest_routes'  => array(),
    'nocache_calls' => 0,
    'wc'           => null,
    'wc_load_cart_calls' => 0,
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
        'login_cookie_user' => null,
        'http_origin'  => null,
        'rest_routes'  => array(),
        'nocache_calls' => 0,
        'wc'           => new Kilden_Fake_WC(),
        'wc_load_cart_calls' => 0,
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

function home_url($path = '')
{
    return 'https://store.example' . (string) $path;
}

/** The Origin header, as WordPress reads it. Null for a same-origin GET. */
function get_http_origin()
{
    return $GLOBALS['kilden_test']['http_origin'];
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function nocache_headers()
{
    $GLOBALS['kilden_test']['nocache_calls']++;
}

function register_rest_route($ns, $route, $args)
{
    $GLOBALS['kilden_test']['rest_routes'][$ns . $route] = $args;
}

/**
 * In a REST request WordPress deliberately ignores the login cookie unless an
 * X-WP-Nonce accompanies it (rest_cookie_check_errors calls
 * wp_set_current_user(0)), so this returns nothing for the identity endpoint's
 * context. That is not pedantry: the endpoint used to call this and answered
 * 204 to every logged-in visitor for it, in silence.
 */
function wp_get_current_user()
{
    return $GLOBALS['kilden_test']['current_user'];
}

/**
 * The login cookie itself, which stays readable and verifiable in any context.
 * Returns the user id, or false for a missing, expired or forged cookie.
 *
 * @return int|false
 */
function wp_validate_auth_cookie($cookie = '', $scheme = '')
{
    $user = $GLOBALS['kilden_test']['login_cookie_user'];

    return $user ? (int) $user->ID : false;
}

function get_user_by($field, $value)
{
    $cookie_user = $GLOBALS['kilden_test']['login_cookie_user'];
    if ($cookie_user && (int) $cookie_user->ID === (int) $value) {
        return $cookie_user;
    }

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

/**
 * `session` is null until something boots it — which is the interesting part,
 * not an implementation detail: WooCommerce leaves it null on custom REST
 * routes, and that is what silently broke the blocks bridge.
 */
class Kilden_Fake_WC
{
    /** @var Kilden_Fake_WC_Session|null */
    public $session = null;
}

class Kilden_Fake_WC_Session
{
    /** @var array<string, mixed> */
    private $data = array();

    /** @return mixed */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /** @param mixed $value */
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }
}

function WC()
{
    return $GLOBALS['kilden_test']['wc'];
}

function wc_load_cart()
{
    // The real one boots the session; the fake records that it was asked.
    $GLOBALS['kilden_test']['wc_load_cart_calls']++;
    if ($GLOBALS['kilden_test']['wc']->session === null) {
        $GLOBALS['kilden_test']['wc']->session = new Kilden_Fake_WC_Session();
    }
}

class Kilden_Fake_Request
{
    /** @var array<string, mixed> */
    private $params;

    /** @param array<string, mixed> $params */
    public function __construct(array $params = array())
    {
        $this->params = $params;
    }

    /** @return mixed */
    public function get_param(string $key)
    {
        return $this->params[$key] ?? null;
    }
}

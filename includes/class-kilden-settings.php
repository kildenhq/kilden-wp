<?php
/**
 * Option storage and the Settings → Kilden admin page.
 *
 * The secret key and the identity secret can (and should, on hardened
 * setups) live in wp-config.php as constants instead of the database:
 * KILDEN_SECRET_KEY and KILDEN_IDENTITY_SECRET always win over the saved
 * option, and the admin field locks itself when the constant exists.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kilden_Settings
{
    const OPTION = 'kilden_settings';

    /** @var array<string, mixed>|null */
    private static $cache = null;

    /** @return array<string, mixed> */
    public static function defaults()
    {
        return array(
            'public_key'         => '',
            'secret_key'         => '',
            'identity_secret'    => '',
            'identity_kid'       => '',
            'host'               => 'https://ingest.kilden.io',
            'enable_snippet'     => true,
            'enable_identity'    => true,
            'enable_woocommerce' => true,
        );
    }

    /** @return array<string, mixed> */
    public static function all()
    {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, array());
            self::$cache = array_merge(self::defaults(), is_array($saved) ? $saved : array());
        }

        return self::$cache;
    }

    /** @return mixed */
    public static function get(string $key)
    {
        $all = self::all();

        return isset($all[$key]) ? $all[$key] : null;
    }

    public static function public_key(): string
    {
        return (string) self::get('public_key');
    }

    /** The wp-config.php constant beats the database (see class docblock). */
    public static function secret_key(): string
    {
        if (defined('KILDEN_SECRET_KEY') && constant('KILDEN_SECRET_KEY') !== '') {
            return (string) constant('KILDEN_SECRET_KEY');
        }

        return (string) self::get('secret_key');
    }

    public static function identity_secret(): string
    {
        if (defined('KILDEN_IDENTITY_SECRET') && constant('KILDEN_IDENTITY_SECRET') !== '') {
            return (string) constant('KILDEN_IDENTITY_SECRET');
        }

        return (string) self::get('identity_secret');
    }

    public static function identity_kid(): string
    {
        return (string) self::get('identity_kid');
    }

    public static function host(): string
    {
        $host = rtrim((string) self::get('host'), '/');

        return $host !== '' ? $host : 'https://ingest.kilden.io';
    }

    public static function enabled(string $feature): bool
    {
        return (bool) self::get('enable_' . $feature);
    }

    public static function reset_cache(): void
    {
        self::$cache = null;
    }

    // --- admin page ---

    public static function register(): void
    {
        add_action('admin_menu', array(__CLASS__, 'add_page'));
        add_action('admin_init', array(__CLASS__, 'register_fields'));
    }

    public static function add_page(): void
    {
        add_options_page(
            __('Kilden', 'kilden'),
            __('Kilden', 'kilden'),
            'manage_options',
            'kilden',
            array(__CLASS__, 'render_page')
        );
    }

    public static function register_fields(): void
    {
        register_setting('kilden', self::OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
        ));
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function sanitize($input)
    {
        $input = is_array($input) ? $input : array();
        $clean = self::all();

        foreach (array('public_key', 'secret_key', 'identity_secret', 'identity_kid') as $key) {
            if (array_key_exists($key, $input)) {
                $clean[$key] = trim(sanitize_text_field((string) $input[$key]));
            }
        }
        if (array_key_exists('host', $input)) {
            $host = esc_url_raw(trim((string) $input['host']));
            $clean['host'] = $host !== '' ? untrailingslashit($host) : 'https://ingest.kilden.io';
        }
        foreach (array('enable_snippet', 'enable_identity', 'enable_woocommerce') as $key) {
            $clean[$key] = !empty($input[$key]);
        }

        self::$cache = null;

        return $clean;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = self::all();
        $secret_locked = defined('KILDEN_SECRET_KEY') && constant('KILDEN_SECRET_KEY') !== '';
        $identity_locked = defined('KILDEN_IDENTITY_SECRET') && constant('KILDEN_IDENTITY_SECRET') !== '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Kilden', 'kilden'); ?></h1>
            <p>
                <?php esc_html_e('Connect your store to Kilden. Find both keys and the identity secret in your Kilden project settings.', 'kilden'); ?>
                <a href="https://kilden.io/docs" target="_blank" rel="noreferrer">kilden.io/docs</a>
            </p>
            <form method="post" action="options.php">
                <?php settings_fields('kilden'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="kilden_public_key"><?php esc_html_e('Public write key', 'kilden'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[public_key]" id="kilden_public_key" type="text" class="regular-text code" value="<?php echo esc_attr((string) $s['public_key']); ?>" placeholder="wk_...">
                            <p class="description"><?php esc_html_e('Used by the visitor-side snippet. Safe to expose in HTML.', 'kilden'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kilden_secret_key"><?php esc_html_e('Secret write key', 'kilden'); ?></label></th>
                        <td>
                            <?php if ($secret_locked) : ?>
                                <input id="kilden_secret_key" type="text" class="regular-text code" value="<?php esc_attr_e('Defined as KILDEN_SECRET_KEY in wp-config.php', 'kilden'); ?>" disabled>
                            <?php else : ?>
                                <input name="<?php echo esc_attr(self::OPTION); ?>[secret_key]" id="kilden_secret_key" type="password" class="regular-text code" value="<?php echo esc_attr((string) $s['secret_key']); ?>" placeholder="sk_..." autocomplete="off">
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Used for server-side order tracking. Stored in the WordPress database; for stricter setups define KILDEN_SECRET_KEY in wp-config.php instead.', 'kilden'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kilden_identity_secret"><?php esc_html_e('Identity secret', 'kilden'); ?></label></th>
                        <td>
                            <?php if ($identity_locked) : ?>
                                <input id="kilden_identity_secret" type="text" class="regular-text code" value="<?php esc_attr_e('Defined as KILDEN_IDENTITY_SECRET in wp-config.php', 'kilden'); ?>" disabled>
                            <?php else : ?>
                                <input name="<?php echo esc_attr(self::OPTION); ?>[identity_secret]" id="kilden_identity_secret" type="password" class="regular-text code" value="<?php echo esc_attr((string) $s['identity_secret']); ?>" autocomplete="off">
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Signs identity tokens for logged-in visitors so Kilden can trust who they are.', 'kilden'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kilden_identity_kid"><?php esc_html_e('Identity key id (kid)', 'kilden'); ?></label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION); ?>[identity_kid]" id="kilden_identity_kid" type="text" class="regular-text code" value="<?php echo esc_attr((string) $s['identity_kid']); ?>" placeholder="k1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kilden_host"><?php esc_html_e('Kilden host', 'kilden'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[host]" id="kilden_host" type="url" class="regular-text code" value="<?php echo esc_attr((string) $s['host']); ?>">
                            <p class="description"><?php esc_html_e('Leave the default unless you self-host Kilden.', 'kilden'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Features', 'kilden'); ?></th>
                        <td>
                            <fieldset>
                                <label><input name="<?php echo esc_attr(self::OPTION); ?>[enable_snippet]" type="checkbox" value="1" <?php checked((bool) $s['enable_snippet']); ?>> <?php esc_html_e('Track page visits (adds the Kilden snippet to your site)', 'kilden'); ?></label><br>
                                <label><input name="<?php echo esc_attr(self::OPTION); ?>[enable_identity]" type="checkbox" value="1" <?php checked((bool) $s['enable_identity']); ?>> <?php esc_html_e('Identify logged-in visitors (verified identity)', 'kilden'); ?></label><br>
                                <label><input name="<?php echo esc_attr(self::OPTION); ?>[enable_woocommerce]" type="checkbox" value="1" <?php checked((bool) $s['enable_woocommerce']); ?>> <?php esc_html_e('Track WooCommerce orders server-side', 'kilden'); ?></label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

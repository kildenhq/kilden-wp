<?php
/**
 * GET /wp-json/kilden/v1/identity — the cache-safe identity channel.
 *
 * Page HTML must stay identical for every visitor (full-page caches), so
 * identity is fetched by the browser from this endpoint: logged-in users
 * get { distinct_id, token, traits } (token signed with the project's
 * identity secret), anonymous visitors get an empty 204. The endpoint
 * doubles as the web SDK's token refresher (docs/27 §4: one piece, two
 * problems).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kilden_Identity
{
    public static function active(): bool
    {
        return Kilden_Settings::enabled('identity')
            && Kilden_Settings::identity_secret() !== ''
            && Kilden_Settings::identity_kid() !== '';
    }

    public static function register(): void
    {
        if (!self::active()) {
            return;
        }
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes(): void
    {
        register_rest_route('kilden/v1', '/identity', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle'),
            // Public on purpose: it answers 204 unless there is a WP login
            // session, and the token it mints only vouches for that session's
            // own user. Auth is the WP cookie itself.
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * @param mixed $request
     * @return WP_REST_Response
     */
    public static function handle($request)
    {
        nocache_headers();

        // Read the login cookie ourselves rather than asking for the current
        // user. On a REST request WordPress ignores that cookie unless an
        // X-WP-Nonce comes with it (rest_cookie_check_errors calls
        // wp_set_current_user(0)), so wp_get_current_user() is nobody here
        // even for a signed-in visitor — this endpoint answered 204 to every
        // one of them, so no token was ever minted and no browser event was
        // ever verified.
        //
        // Sending a nonce is not an option: it is per-user and per-session,
        // and this whole endpoint exists because the page HTML is cached and
        // shared. wp_validate_auth_cookie checks the cookie's own HMAC, so a
        // missing, expired or forged one resolves to nobody. What is given up
        // is nonce CSRF protection, which this read does not need: a
        // cross-origin caller can make the browser send the cookie but cannot
        // read the response, and the token only ever vouches for the caller's
        // own session.
        $user_id = (int) wp_validate_auth_cookie('', 'logged_in');
        $user = $user_id > 0 ? get_user_by('id', $user_id) : null;
        if (!$user || 0 === (int) $user->ID) {
            $response = new WP_REST_Response(null, 204);
        } else {
            $payload = self::payload_for($user);
            $response = $payload === null
                ? new WP_REST_Response(null, 204)
                : new WP_REST_Response($payload, 200);
        }

        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }

    /**
     * @param WP_User $user
     * @return array<string, mixed>|null
     */
    public static function payload_for($user)
    {
        $signer = Kilden_Client_Factory::signer();
        if ($signer === null) {
            return null;
        }

        /**
         * The distinct_id logged-in visitors are identified as. Default:
         * the numeric WP user id as a string. Must stay consistent with
         * the id used by server-side order events.
         *
         * @param string  $distinct_id
         * @param WP_User $user
         */
        $distinct_id = (string) apply_filters('kilden_distinct_id_for_user', (string) $user->ID, $user);

        /**
         * Signed traits for the identity token (they override unsigned
         * traits during enrichment).
         *
         * @param array<string, mixed> $traits
         * @param WP_User              $user
         */
        $traits = (array) apply_filters('kilden_identity_traits', array(
            'email' => (string) $user->user_email,
            'name'  => (string) $user->display_name,
        ), $user);

        try {
            $token = $signer->sign($distinct_id, array('traits' => $traits));
        } catch (\Exception $e) {
            error_log('kilden: identity token signing failed: ' . $e->getMessage());

            return null;
        }

        return array(
            'distinct_id' => $distinct_id,
            'token'       => $token,
            'traits'      => $traits,
        );
    }
}

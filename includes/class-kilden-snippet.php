<?php
/**
 * Prints the Kilden web SDK loader in wp_head.
 *
 * Two hard rules, both learned from every analytics plugin that got them
 * wrong (docs/27 §5):
 *
 * 1. Nothing visitor-specific ever goes in the HTML. Full-page caches
 *    (WP Rocket, Varnish, host-level) would serve user A's identity to
 *    user B. Identity travels through the REST endpoint instead.
 * 2. Consent is respected via the WP Consent API when a consent plugin is
 *    active: the loader only boots once the `statistics` category is
 *    granted, and listens for consent changes to boot late.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kilden_Snippet
{
    public static function register(): void
    {
        if (!Kilden_Settings::enabled('snippet') || Kilden_Settings::public_key() === '') {
            return;
        }
        add_action('wp_head', array(__CLASS__, 'print_snippet'), 1);
    }

    public static function print_snippet(): void
    {
        // The loader is identical to the official Kilden snippet (stub list
        // frozen by kilden-sdk-js); values come from settings.
        echo "<!-- Kilden (kilden-wp " . esc_html(KILDEN_WP_VERSION) . ") -->\n";
        echo '<script>' . self::snippet_js() . "</script>\n";
    }

    public static function snippet_js(): string
    {
        $write_key = Kilden_Settings::public_key();
        $host = Kilden_Settings::host();
        $init_options = array();
        if ($host !== 'https://ingest.kilden.io') {
            $init_options['apiHost'] = $host;
        }

        $boot = self::boot_js($write_key, $init_options);

        // Without a consent plugin the loader boots immediately. With one
        // (wp_has_consent exists), booting is deferred to the JS consent API
        // so cached pages behave identically for every visitor.
        if (function_exists('wp_has_consent')) {
            return self::consent_gated_js($boot);
        }

        return $boot . "\nkildenBoot();";
    }

    /**
     * @param array<string, mixed> $init_options
     */
    private static function boot_js(string $write_key, array $init_options): string
    {
        $loader = <<<'JS'
!(function (w, d) {
    if (w.kilden) return;
    function stub(o, names) {
      o._q = [];
      names.split(' ').forEach(function (m) {
        o[m] = function () { o._q.push([m].concat([].slice.call(arguments))); };
      });
      return o;
    }
    var k = (w.kilden = stub({}, 'init track identify setPersonProperties reset register unregister getDistinctId getSessionId optOut optIn hasOptedOut setIdentityToken flush use removePlugin startSessionRecording stopSessionRecording getReplayId group isFeatureEnabled getFeatureFlag onFeatureFlags'));
    k.flags = stub({}, 'isFeatureEnabled getFeatureFlag getAllFlags onFeatureFlags reload override');
    k.messenger = stub({}, 'open close show hide toggle showNewMessage on off update');
    var s = d.createElement('script');
    s.async = true;
    s.src = 'https://cdn.kilden.io/kilden.iife.js';
    d.head.appendChild(s);
  })(window, document);
JS;

        $identity = Kilden_Identity::active() ? self::identity_js($init_options) : '';
        if ($identity === '') {
            $init = sprintf(
                'kilden.init(%s%s);',
                wp_json_encode($write_key),
                $init_options === array() ? '' : ', ' . wp_json_encode($init_options, JSON_UNESCAPED_SLASHES)
            );

            return "function kildenBoot() {\n" . $loader . "\n  " . $init . "\n}";
        }

        return "function kildenBoot() {\n" . $loader . "\n" . $identity . "\n}";
    }

    /**
     * Identity bootstrap: init with getIdentityToken pointing at the
     * cache-safe REST endpoint, then identify once if the visitor turns out
     * to be logged in. The endpoint answers 204 for anonymous visitors, so
     * cached pages stay byte-identical for everyone.
     *
     * @param array<string, mixed> $init_options
     */
    private static function identity_js(array $init_options): string
    {
        $write_key = wp_json_encode(Kilden_Settings::public_key());
        $endpoint = wp_json_encode(esc_url_raw(rest_url('kilden/v1/identity')), JSON_UNESCAPED_SLASHES);
        // An empty PHP array would encode as a JS Array; init options must
        // always be a plain object.
        $options = $init_options === array() ? '{}' : wp_json_encode($init_options, JSON_UNESCAPED_SLASHES);

        return <<<JS
  var kildenIdentityUrl = {$endpoint};
  function kildenFetchIdentity() {
    return fetch(kildenIdentityUrl, { credentials: 'same-origin' })
      .then(function (r) { return r.status === 200 ? r.json() : null; })
      .catch(function () { return null; });
  }
  var kildenOptions = {$options};
  kildenOptions.getIdentityToken = function () {
    return kildenFetchIdentity().then(function (id) { return id && id.token ? id.token : null; });
  };
  kilden.init({$write_key}, kildenOptions);
  kildenFetchIdentity().then(function (id) {
    if (id && id.distinct_id && id.token) {
      kilden.identify(id.distinct_id, id.traits || {}, { token: id.token });
    }
  });
JS;
    }

    /**
     * WP Consent API (Complianz, CookieYes, ...): boot when `statistics` is
     * already granted, otherwise wait for the documented consent event.
     */
    private static function consent_gated_js(string $boot): string
    {
        return $boot . "\n" . <<<'JS'
  (function () {
    var booted = false;
    function bootOnce() { if (!booted) { booted = true; kildenBoot(); } }
    function granted() {
      return typeof wp_has_consent === 'function' && wp_has_consent('statistics');
    }
    function check() { if (granted()) bootOnce(); }

    // Asking once, here, is not enough: this snippet is the first script on
    // the page and the consent API's own script comes dozens of scripts
    // later, so wp_has_consent does not exist yet and every visitor looks
    // unconsented. Waiting for a change from there never helps a visitor who
    // had already consented, because nothing ever changes — Kilden just never
    // loaded, silently, wherever a consent plugin was installed.
    //
    // So ask again once that script has run. It is parser-blocking, so
    // DOMContentLoaded is late enough; wp_consent_type_defined covers a
    // consent manager that announces its type later still.
    check();
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', check);
    }
    document.addEventListener('wp_consent_type_defined', check);
    document.addEventListener('wp_listen_for_consent_change', function (e) {
      var changed = e.detail || {};
      if (changed.statistics === 'allow') bootOnce();
    });
  })();
JS;
    }
}

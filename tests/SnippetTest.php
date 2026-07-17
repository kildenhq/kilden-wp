<?php

use PHPUnit\Framework\TestCase;

final class SnippetTest extends TestCase
{
    protected function setUp(): void
    {
        kilden_test_reset();
        update_option(Kilden_Settings::OPTION, array('public_key' => 'wk_test_public'));
        Kilden_Settings::reset_cache();
    }

    public function testLoaderCarriesPublicKeyAndCdn(): void
    {
        $js = Kilden_Snippet::snippet_js();

        self::assertStringContainsString('cdn.kilden.io/kilden.iife.js', $js);
        self::assertStringContainsString('"wk_test_public"', $js);
        self::assertStringContainsString('kildenBoot();', $js);
        // Default host: no apiHost baked in (SPEC default handles it).
        self::assertStringNotContainsString('apiHost', $js);
    }

    public function testCustomHostIsBakedIn(): void
    {
        update_option(Kilden_Settings::OPTION, array('public_key' => 'wk_test_public', 'host' => 'https://ingest.example'));
        Kilden_Settings::reset_cache();

        self::assertStringContainsString('"apiHost":"https://ingest.example"', Kilden_Snippet::snippet_js());
    }

    public function testIdentityBootstrapWhenIdentityActive(): void
    {
        update_option(Kilden_Settings::OPTION, array(
            'public_key'      => 'wk_test_public',
            'identity_secret' => 's',
            'identity_kid'    => 'k1',
        ));
        Kilden_Settings::reset_cache();

        $js = Kilden_Snippet::snippet_js();

        self::assertStringContainsString('kilden/v1/identity', $js);
        self::assertStringContainsString('getIdentityToken', $js);
        self::assertStringContainsString('kilden.identify(id.distinct_id', $js);
    }

    public function testNothingVisitorSpecificInTheSnippet(): void
    {
        // The cache-safety rule: even with a logged-in user, the printed
        // HTML must not contain their identity.
        update_option(Kilden_Settings::OPTION, array(
            'public_key'      => 'wk_test_public',
            'identity_secret' => 's',
            'identity_kid'    => 'k1',
        ));
        Kilden_Settings::reset_cache();
        $GLOBALS['kilden_test']['current_user'] = new WP_User(42, 'user@example.com', 'Test User');

        $js = Kilden_Snippet::snippet_js();

        self::assertStringNotContainsString('user@example.com', $js);
        self::assertStringNotContainsString('"42"', $js);
        self::assertStringNotContainsString('eyJhbGciOi', $js);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConsentGateWhenConsentPluginActive(): void
    {
        // Simulate an active WP Consent API plugin.
        function wp_has_consent($category)
        {
            return false;
        }

        update_option(Kilden_Settings::OPTION, array('public_key' => 'wk_test_public'));
        Kilden_Settings::reset_cache();

        $js = Kilden_Snippet::snippet_js();

        self::assertStringContainsString('wp_listen_for_consent_change', $js);
        self::assertStringContainsString("wp_has_consent('statistics')", $js);
        // No unconditional boot when consent is managed.
        self::assertStringNotContainsString("\nkildenBoot();", $js);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConsentGateReChecksOnceTheConsentApiHasLoaded(): void
    {
        // The bug this shipped with. This snippet is the first script on the
        // page; the consent API's own script is dozens of scripts later, so
        // wp_has_consent does not exist yet when the gate first runs. Asking
        // once and then waiting for a *change* meant that a visitor who had
        // already consented never triggered anything — the change never comes
        // — and Kilden simply never loaded, silently, on every site with a
        // consent plugin.
        //
        // The API's script is parser-blocking, so it has run by
        // DOMContentLoaded. wp_consent_type_defined covers a consent manager
        // that announces itself later.
        function wp_has_consent($category)
        {
            return false;
        }

        update_option(Kilden_Settings::OPTION, array('public_key' => 'wk_test_public'));
        Kilden_Settings::reset_cache();

        $js = Kilden_Snippet::snippet_js();

        // Assert the listeners, not the words: both names also appear in the
        // comment above them, so matching the bare identifiers would pass with
        // the addEventListener calls deleted.
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded', check)", $js);
        self::assertStringContainsString("document.addEventListener('wp_consent_type_defined', check)", $js);
    }
}

<?php

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        kilden_test_reset();
    }

    public function testDefaults(): void
    {
        self::assertSame('https://ingest.kilden.io', Kilden_Settings::host());
        self::assertSame('', Kilden_Settings::public_key());
        self::assertTrue(Kilden_Settings::enabled('snippet'));
        self::assertTrue(Kilden_Settings::enabled('woocommerce'));
    }

    public function testStoredOptionsMergeOverDefaults(): void
    {
        update_option(Kilden_Settings::OPTION, array(
            'public_key' => 'wk_store',
            'secret_key' => 'sk_store',
            'host'       => 'https://ingest.example',
        ));
        Kilden_Settings::reset_cache();

        self::assertSame('wk_store', Kilden_Settings::public_key());
        self::assertSame('sk_store', Kilden_Settings::secret_key());
        self::assertSame('https://ingest.example', Kilden_Settings::host());
        self::assertTrue(Kilden_Settings::enabled('identity'));
    }

    public function testSanitizeTrimsAndNormalizes(): void
    {
        $clean = Kilden_Settings::sanitize(array(
            'public_key'      => "  wk_x \n",
            'host'            => 'https://ingest.example/',
            'enable_snippet'  => '1',
        ));

        self::assertSame('wk_x', $clean['public_key']);
        self::assertSame('https://ingest.example', $clean['host']);
        self::assertTrue($clean['enable_snippet']);
        self::assertFalse($clean['enable_identity']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWpConfigConstantBeatsStoredSecret(): void
    {
        define('KILDEN_SECRET_KEY', 'sk_from_wp_config');
        update_option(Kilden_Settings::OPTION, array('secret_key' => 'sk_from_db'));
        Kilden_Settings::reset_cache();

        self::assertSame('sk_from_wp_config', Kilden_Settings::secret_key());
    }
}

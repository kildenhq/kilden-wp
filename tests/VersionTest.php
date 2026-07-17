<?php

use PHPUnit\Framework\TestCase;

/**
 * The plugin spells its version in three places and they have to agree.
 *
 * They are not decoration. `Version:` is what WordPress shows and compares
 * for updates, `KILDEN_WP_VERSION` is what the plugin reports to Kilden
 * (spec §4.1 puts it in the User-Agent), and `Stable tag` is what
 * wordpress.org serves to users — so a drifting Stable tag ships one version
 * while the release says another.
 *
 * The release workflow checks these against the git tag; this checks them
 * against each other, at PR time, where the fix is cheap.
 */
final class VersionTest extends TestCase
{
    private function pluginHeaderVersion(): string
    {
        $header = (string) file_get_contents(dirname(__DIR__) . '/kilden.php');
        preg_match('/^ \* Version:\s*(.+)$/m', $header, $m);

        return isset($m[1]) ? trim($m[1]) : '';
    }

    private function constantVersion(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/kilden.php');
        preg_match("/define\('KILDEN_WP_VERSION', '([^']+)'\)/", $source, $m);

        return isset($m[1]) ? $m[1] : '';
    }

    private function readmeStableTag(): string
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/readme.txt');
        preg_match('/^Stable tag:\s*(.+)$/m', $readme, $m);

        return isset($m[1]) ? trim($m[1]) : '';
    }

    public function testEveryVersionSurfaceIsReadable(): void
    {
        $this->assertNotSame('', $this->pluginHeaderVersion(), 'no Version: header in kilden.php');
        $this->assertNotSame('', $this->constantVersion(), 'no KILDEN_WP_VERSION in kilden.php');
        $this->assertNotSame('', $this->readmeStableTag(), 'no Stable tag: in readme.txt');
    }

    public function testTheVersionSurfacesAgree(): void
    {
        $header = $this->pluginHeaderVersion();

        $this->assertSame($header, $this->constantVersion(), 'KILDEN_WP_VERSION does not match the Version: header');
        $this->assertSame($header, $this->readmeStableTag(), 'readme.txt Stable tag does not match the Version: header');
    }
}

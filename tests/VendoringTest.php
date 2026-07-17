<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bin/vendor-leftovers.php';

/**
 * The vendored core must be prefixed all the way down: what ships to a
 * WordPress site is includes/vendor-kilden plus nothing else, so any symbol
 * still spelled `Kilden\…` is a fatal the moment that line runs.
 *
 * This asserts on the source text rather than by calling the code, and that
 * is the whole point. This suite runs under Composer, which autoloads the
 * unprefixed dev copy of kilden/kilden-php — so a missed reference resolves
 * happily here and blows up only in a real install. A runtime test would
 * stay green through exactly the bug it is meant to catch.
 *
 * The rule for what counts as a leftover lives in bin/vendor-leftovers.php,
 * shared with build-vendor: this guard exists because such a rule was wrong
 * in one place, so there is only one place.
 */
class VendoringTest extends TestCase
{
    private function vendorDir(): string
    {
        return dirname(__DIR__) . '/includes/vendor-kilden';
    }

    public function testThereIsAVendoredCoreToCheck(): void
    {
        $this->assertFileExists($this->vendorDir() . '/autoload.php', 'vendored core is missing — run composer vendor-core');
        $this->assertFileExists($this->vendorDir() . '/src/Client.php');
    }

    public function testNoUnprefixedReferencesSurviveTheRewrite(): void
    {
        $offenders = kilden_wp_unprefixed_leftovers($this->vendorDir());

        $this->assertSame(
            array(),
            $offenders,
            "vendored core still references the unprefixed namespace:\n  " . implode("\n  ", $offenders)
        );
    }
}

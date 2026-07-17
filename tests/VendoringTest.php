<?php

use PHPUnit\Framework\TestCase;

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
 */
class VendoringTest extends TestCase
{
    /** @return string[] */
    private function vendored_files(): array
    {
        $dir = dirname(__DIR__) . '/includes/vendor-kilden';
        $files = array();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function test_there_are_vendored_files_to_check(): void
    {
        $this->assertNotEmpty($this->vendored_files(), 'vendored core is missing — run composer vendor-core');
    }

    public function test_no_unprefixed_references_survive_the_rewrite(): void
    {
        $offenders = array();

        foreach ($this->vendored_files() as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            foreach ($lines as $i => $line) {
                // Collapse escaped backslashes first, so a class name written
                // as a PHP string ('KildenWP\\Vendor\\Kilden\\Client', as the
                // generated classmap does) reads the same as one written as
                // code — otherwise the lookbehind below never sees `Vendor`.
                $normalized = str_replace('\\\\', '\\', $line);

                $bad = preg_match('/^namespace Kilden[;\\\\]/', $normalized)
                    || preg_match('/^use Kilden\\\\/', $normalized)
                    // A fully-qualified `\Kilden\…` that is not the tail of
                    // `\KildenWP\Vendor\Kilden\…`.
                    || preg_match('/(?<!Vendor)\\\\Kilden\\\\/', $normalized);

                if ($bad) {
                    $offenders[] = basename($file) . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame(
            array(),
            $offenders,
            "vendored core still references the unprefixed namespace:\n  " . implode("\n  ", $offenders)
        );
    }
}

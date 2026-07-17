<?php
/**
 * One definition of "this reference was never rewritten".
 *
 * Shared by bin/build-vendor.php, which fails its own build, and
 * tests/VendoringTest.php, which fails CI on the committed tree. Two checks
 * are wanted — the build one only runs when someone re-vendors, the test runs
 * on every PR — but two *rules* are not: this whole guard exists because a
 * detection rule was subtly wrong in one place and nothing else noticed.
 */

/**
 * Every line under $dir that still names the unprefixed namespace.
 *
 * @return string[] "file:line  source" for each offender, empty when clean
 */
function kilden_wp_unprefixed_leftovers(string $dir): array
{
    $offenders = array();

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $i => $line) {
            // Collapse escaped backslashes first, so a class name written as a
            // PHP string ('KildenWP\\Vendor\\Kilden\\Client', as the generated
            // classmap does) reads the same as one written as code —
            // otherwise the lookbehind below never sees `Vendor`.
            $normalized = str_replace('\\\\', '\\', $line);

            $bad = preg_match('/^namespace Kilden[;\\\\]/', $normalized)
                || preg_match('/^use Kilden\\\\/', $normalized)
                // A fully-qualified `\Kilden\…` that is not the tail of
                // `\KildenWP\Vendor\Kilden\…`.
                || preg_match('/(?<!Vendor)\\\\Kilden\\\\/', $normalized);

            if ($bad) {
                $offenders[] = substr($file->getPathname(), strlen($dir) + 1) . ':' . ($i + 1) . '  ' . trim($line);
            }
        }
    }

    sort($offenders);

    return $offenders;
}

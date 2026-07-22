#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerates the built-in dictionary manifest.
 *
 * Scans the bundled dictionaries tree for each language's canonical
 * "<xx>.multi" entry point (the two-letter code before ".multi" is the
 * language code) and writes the discovered {code, label, path} table to a JSON
 * file. public/index.php restores its $DICTIONARIES array from that JSON via
 * Speller::loadDictionaryManifest().
 *
 * Run it after adding or removing a dictionary:
 *
 *     php bin/build-dictionaries.php
 *     php bin/build-dictionaries.php <dict-root> <output.json>
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Aspell\Engine\Speller;

$root   = $argv[1] ?? Speller::defaultDictionaryRoot();
$output = $argv[2] ?? dirname(__DIR__) . '/dictionaries/dictionaries.json';

try {
    $manifest = Speller::saveDictionaryManifest($output, $root);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

printf("Wrote %d dictionaries to %s%s", count($manifest), $output, PHP_EOL);
foreach ($manifest as $code => $entry) {
    printf("  %-3s %s%s", $code, $entry['label'], PHP_EOL);
}

<?php

declare(strict_types=1);

namespace Aspell\Tests\Engine;

use Aspell\Engine\Speller;
use PHPUnit\Framework\TestCase;

/**
 * Covers Speller's built-in dictionary manifest helpers: discovery from a
 * dictionaries tree, JSON round-trip, and restoration of the $DICTIONARIES
 * table.
 */
class DictionaryManifestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        // Build a throwaway dictionary tree that mirrors the real layout:
        // each language lives in its own package folder with a canonical
        // "<xx>.multi" alongside regional/variant multis that must be ignored.
        $this->root = sys_get_temp_dir() . '/aspell_manifest_' . uniqid();
        $this->makeTree([
            'aspell6-en-2026/en.multi',
            'aspell6-en-2026/en_US.multi',        // regional — ignored
            'aspell6-en-2026/en-variant_0.multi', // variant  — ignored
            'aspell-fr-0.50/fr.multi',
            'aspell-fr-0.50/fr_FR.multi',          // regional — ignored
            'aspell6-xx-1.0/xx.multi',             // unknown code — falls back to label "XX"
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testDiscoverKeepsOnlyTwoLetterMultisOrderedByCode(): void
    {
        $manifest = Speller::discoverDictionaries($this->root);

        // en, fr, xx — the regional/variant multis are not separate entries.
        $this->assertSame(['en', 'fr', 'xx'], array_keys($manifest));

        $this->assertSame('English', $manifest['en']['label']);
        $this->assertSame('French — français', $manifest['fr']['label']);
        $this->assertSame('XX', $manifest['xx']['label']); // unknown -> code
        $this->assertStringEndsWith('/en.multi', $manifest['en']['path']);
        $this->assertFileExists($manifest['en']['path']);
    }

    public function testSaveAndLoadRoundTripRestoresTheTable(): void
    {
        $jsonPath = $this->root . '/dictionaries.json';
        $saved = Speller::saveDictionaryManifest($jsonPath, $this->root);

        // Paths in the JSON are stored relative to the root, not absolute.
        $json = (string) file_get_contents($jsonPath);
        $this->assertStringContainsString('"path": "aspell6-en-2026/en.multi"', $json);
        $this->assertStringNotContainsString($this->root, $json);

        // Loading from the file resolves them back to the absolute paths.
        $loaded = Speller::loadDictionaryManifest($jsonPath, $this->root);
        $this->assertSame($saved, $loaded);
        $this->assertFileExists($loaded['fr']['path']);
    }

    public function testLoadAcceptsAJsonStringAndABareMap(): void
    {
        // Relative paths are resolved against the canonicalised root.
        $expected = realpath($this->root) . '/aspell-fr-0.50/fr.multi';
        $wrapped = '{"dictionaries":{"fr":{"label":"Français","path":"aspell-fr-0.50/fr.multi"}}}';
        $bare    = '{"fr":{"label":"Français","path":"aspell-fr-0.50/fr.multi"}}';

        foreach ([$wrapped, $bare] as $json) {
            $loaded = Speller::loadDictionaryManifest($json, $this->root);
            $this->assertSame(['fr'], array_keys($loaded));
            $this->assertSame('Français', $loaded['fr']['label']);
            $this->assertSame($expected, $loaded['fr']['path']);
        }
    }

    public function testLoadLeavesAbsolutePathsUntouched(): void
    {
        $abs = $this->root . '/aspell-fr-0.50/fr.multi';
        $loaded = Speller::loadDictionaryManifest(
            '{"fr":{"label":"Français","path":' . json_encode($abs) . '}}',
            $this->root
        );

        $this->assertSame($abs, $loaded['fr']['path']);
    }

    public function testDiscoverThrowsOnMissingRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Speller::discoverDictionaries($this->root . '/does-not-exist');
    }

    /** @param list<string> $relPaths */
    private function makeTree(array $relPaths): void
    {
        foreach ($relPaths as $rel) {
            $full = $this->root . '/' . $rel;
            @mkdir(dirname($full), 0777, true);
            file_put_contents($full, "personal_ws-1.1\n");
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

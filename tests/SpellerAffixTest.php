<?php

declare(strict_types=1);

namespace Aspell\Tests\Engine;

use Aspell\Config\AspellConfig;
use Aspell\Engine\Speller;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end check that affix-compressed dictionaries recognise inflected forms
 * via lazy affix stripping. Uses the bundled German dictionary (ISO-8859-1,
 * affix-compress true, prefix+suffix cross-product), and skips if it is absent.
 */
class SpellerAffixTest extends TestCase
{
    private Speller $speller;

    protected function setUp(): void
    {
        $path = Speller::defaultDictionaryRoot() . '/aspell6-de-20161207-7-0/de.multi';
        if (!is_file($path)) {
            $this->markTestSkipped('Bundled German dictionary not available.');
        }
        $this->speller = new Speller(new AspellConfig());
        $this->speller->loadDictionary($path);
    }

    /**
     * Base stems and their inflections (suffix, umlaut plural, and the
     * cross-product prefix+suffix form "unschön") are all accepted.
     */
    public function testGermanInflectionsAreAccepted(): void
    {
        foreach (['schön', 'schöner', 'schöne', 'schönes', 'das', 'Haus', 'Häuser', 'guter', 'Frauen', 'unschön'] as $word) {
            $this->assertTrue($this->speller->check($word), "expected '{$word}' to be accepted");
        }
    }

    public function testGenuineMisspellingsAreStillRejected(): void
    {
        foreach (['Strasse', 'Hasu', 'schöer', 'xyzzy', 'Diser'] as $word) {
            $this->assertFalse($this->speller->check($word), "expected '{$word}' to be rejected");
        }
    }

    /**
     * The stem list stays at the compressed size (no full expansion in memory);
     * inflections are recognised on demand, not materialised.
     */
    public function testDictionaryIsNotFullyExpandedInMemory(): void
    {
        $stemCount = count($this->speller->getLoadedWords());
        $this->assertGreaterThan(50_000, $stemCount);
        $this->assertLessThan(150_000, $stemCount);
    }
}

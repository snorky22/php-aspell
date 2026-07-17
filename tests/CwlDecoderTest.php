<?php

declare(strict_types=1);

namespace Aspell\Tests\Dictionary;

use Aspell\Dictionary\AspellBinaryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the prezip (.cwl) decompressor directly with hand-built streams,
 * plus a guarded end-to-end check against the shipped French dictionary.
 *
 * These are the tests that were missing before: the previous decoder produced
 * garbage on real dictionaries while every unit test stayed green because none
 * of them ever decoded a word list.
 */
class CwlDecoderTest extends TestCase
{
    private function parse(string $bytes): AspellBinaryParser
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cwl');
        file_put_contents($tmp, $bytes);
        try {
            return new AspellBinaryParser($tmp, [], 'utf-8');
        } finally {
            @unlink($tmp);
        }
    }

    /** Legacy format (leading 0x01): prefix-delta, keep byte = keep length + 1. */
    public function testDecodeV1(): void
    {
        // cat / cats / dog
        // 0x01 "cat" | 0x04(keep 3) "s" | 0x01(keep 0) "dog"
        $stream = "\x01cat\x04s\x01dog";
        $words = $this->parse($stream)->getWords();
        sort($words);

        $this->assertSame(['cat', 'cats', 'dog'], $words);
    }

    /** Long shared prefixes still decode correctly in v1. */
    public function testDecodeV1SharedPrefix(): void
    {
        // optimal / optimale / optimisation
        $stream = "\x01optimal"      // keep 0
            . "\x08e"                 // keep 7 -> "optimal" + "e" = "optimale"
            . "\x06isation";          // keep 5 -> "optim" + "isation" = "optimisation"
        $words = $this->parse($stream)->getWords();
        sort($words);

        $this->assertSame(['optimal', 'optimale', 'optimisation'], $words);
    }

    /** Current format (leading 0x02): escapes and the 0x1F 0xFF terminator. */
    public function testDecodeV2(): void
    {
        // ab / abc, terminated by 0x1F 0xFF
        // 0x02 | prefix 0x00 "ab" | prefix 0x02 "c" | 0x1F 0xFF
        $stream = "\x02\x00ab\x02c\x1f\xff";
        $words = $this->parse($stream)->getWords();
        sort($words);

        $this->assertSame(['ab', 'abc'], $words);
    }

    public function testUnknownVersionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parse("\x09garbage");
    }

    /** Affix-compressed entries ("stem/flags", "*forbidden") are reduced to the stem. */
    public function testAffixFlagsAreStripped(): void
    {
        // v2: "work/ADGS" then "worked" (keep 4 -> "work" + "ed")
        $stream = "\x02\x00work/ADGS\x04ed\x1f\xff";
        $words = $this->parse($stream)->getWords();
        sort($words);

        $this->assertSame(['work', 'worked'], $words);
    }

    /**
     * The v2 decoder + charset handling across the shipped non-Latin
     * dictionaries. Each case is skipped cleanly if the file is absent.
     */
    #[DataProvider('realDictionaryProvider')]
    public function testRealMultiLingualDictionaries(
        string $relPath,
        string $charset,
        int $minWords,
        array $present,
        string $absent
    ): void {
        $path = __DIR__ . '/../dictionaries/' . $relPath;
        if (!is_file($path)) {
            $this->markTestSkipped("Dictionary not available: $relPath");
        }

        $parser = new AspellBinaryParser($path, [], $charset);

        $this->assertGreaterThan($minWords, count($parser->getWords()));
        foreach ($present as $w) {
            $this->assertTrue($parser->lookup($w), "expected '$w' to be present");
        }
        $this->assertFalse($parser->lookup($absent), "expected '$absent' to be absent");

        // Decoded words must be valid UTF-8 with no control-character artifacts.
        foreach (array_slice($parser->getWords(), 0, 100) as $w) {
            $this->assertTrue(mb_check_encoding($w, 'UTF-8'));
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f]/', $w);
        }
    }

    public static function realDictionaryProvider(): array
    {
        return [
            'English (v2, iso8859-1)' => [
                'aspell6-en-2026.02.25-0/en-common.cwl', 'iso-8859-1', 50000,
                ['computer', 'knowledge', 'grampus'], 'xqzwfk',
            ],
            'Russian (v2, koi8-r)' => [
                'aspell6-ru-0.99f7-1/ru-ye.cwl', 'koi8-r', 50000,
                ['покупка', 'человек'], 'йцукенг',
            ],
            'Arabic (v2, utf-8)' => [
                'aspell6-ar-1.2-0/ar.cwl', 'utf-8', 100000,
                ['كتاب', 'سلام', 'بيت'], 'زكسمنبل',
            ],
        ];
    }

    /**
     * End-to-end against the real French word list (ISO-8859-1). Skips cleanly
     * when the dictionary is not present in the checkout.
     */
    public function testRealFrenchDictionary(): void
    {
        $cwl = __DIR__ . '/../dictionaries/aspell-fr-0.50-3/fr-40-only.cwl';
        if (!is_file($cwl)) {
            $this->markTestSkipped('French dictionary not available.');
        }

        $parser = new AspellBinaryParser($cwl, [], 'iso-8859-1');

        // Real, correctly spelled French words must be found...
        $this->assertTrue($parser->lookup('optimisation'));
        $this->assertTrue($parser->lookup('fonction'));
        // ...including accented ones round-tripped from ISO-8859-1.
        $this->assertTrue($parser->lookup('été'));
        $this->assertTrue($parser->lookup('amérique'));

        // ...and obvious non-words must not.
        $this->assertFalse($parser->lookup('xqzwff'));

        // Decoded words must be clean (no leading-byte artifacts).
        $words = $parser->getWords();
        $this->assertGreaterThan(100000, count($words));
        foreach (array_slice($words, 0, 50) as $w) {
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f]/', $w);
        }
    }
}

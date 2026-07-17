<?php

declare(strict_types=1);

namespace Aspell\Tests\Engine;

use Aspell\Config\AspellConfig;
use Aspell\Engine\Speller;
use PHPUnit\Framework\TestCase;

class SpellerTest extends TestCase
{
    /**
     * Injects an in-memory dictionary stub so Speller behaviour can be tested
     * without loading a real word list.
     */
    private function spellerWithWords(array $words): Speller
    {
        $speller = new Speller(new AspellConfig());

        $dict = new class ($words) {
            public function __construct(private array $words) {}
            public function lookup(string $w): bool
            {
                return in_array(mb_strtolower($w, 'UTF-8'), $this->words, true);
            }
            public function soundslikeLookup(string $s): array
            {
                return [];
            }
            public function getWords(): array
            {
                return $this->words;
            }
        };

        $ref = new \ReflectionProperty(Speller::class, 'dictionaries');
        $ref->setValue($speller, [$dict]);

        return $speller;
    }

    /** Regression: suggest() previously called an undefined method and fatally errored. */
    public function testSuggestDoesNotFatalAndRanks(): void
    {
        $speller = $this->spellerWithWords(['night', 'knight', 'fight', 'right', 'nightly']);

        $suggestions = $speller->suggest('nite');

        $this->assertIsArray($suggestions);
        $this->assertContains('night', $suggestions);
    }

    public function testCheckIsCaseInsensitive(): void
    {
        $speller = $this->spellerWithWords(['optimisation', 'fonction']);

        $this->assertTrue($speller->check('optimisation'));
        $this->assertTrue($speller->check('Optimisation'));
        $this->assertFalse($speller->check('xqzwff'));
    }

    /** checkDocument honours the `ignore` option (default 1): single chars are skipped. */
    public function testCheckDocumentIgnoresSingleCharacters(): void
    {
        $speller = $this->spellerWithWords(['la', 'de']);

        // "x" and "k" are single-char (math variables) -> ignored;
        // "zzz" is a real unknown word -> flagged.
        $result = $speller->checkDocument('x + k = zzz de la', 'text');
        $flagged = array_column($result, 'word');

        $this->assertNotContains('x', $flagged);
        $this->assertNotContains('k', $flagged);
        $this->assertContains('zzz', $flagged);
    }
}

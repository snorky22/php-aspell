<?php

declare(strict_types=1);

namespace Aspell\Tests\Engine;

use Aspell\Engine\SuggestionEngine;
use Aspell\Engine\EditDistanceWeights;
use PHPUnit\Framework\TestCase;

class SuggestionEngineTest extends TestCase
{
    private SuggestionEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new SuggestionEngine();
    }

    public function testBasicLevenshtein(): void
    {
        $weights = new EditDistanceWeights(del1: 1, del2: 1, swap: 10, sub: 1);
        
        $this->assertEquals(0, $this->engine->editDistance('word', 'word', $weights));
        $this->assertEquals(1, $this->engine->editDistance('word', 'words', $weights));
        $this->assertEquals(1, $this->engine->editDistance('word', 'ord', $weights));
        $this->assertEquals(1, $this->engine->editDistance('word', 'work', $weights));
    }

    public function testDamerauLevenshteinSwap(): void
    {
        $weights = new EditDistanceWeights(del1: 1, del2: 1, swap: 1, sub: 1);
        
        // Swap 'ai' to 'ia' should cost 1
        $this->assertEquals(1, $this->engine->editDistance('nait', 'niat', $weights));
        
        // Without swap support (large weight), it would cost 2 (substitute a->i and i->a)
        $noSwapWeights = new EditDistanceWeights(del1: 1, del2: 1, swap: 10, sub: 1);
        $this->assertEquals(2, $this->engine->editDistance('nait', 'niat', $noSwapWeights));
    }

    public function testComplexDistance(): void
    {
        $weights = new EditDistanceWeights(del1: 1, del2: 1, swap: 1, sub: 1);
        
        // nait -> night
        // n a i t
        // n i g h t
        // Options:
        // 1. sub a->i, sub i->g, ins h = 1+1+1 = 3
        // Actually:
        // n a i t
        // n i i t (sub a->i, cost 1)
        // n i g t (sub i->g, cost 1)
        // n i g h t (ins h, cost 1)
        // Total cost 3.
        
        $this->assertEquals(3, $this->engine->editDistance('nait', 'night', $weights));
    }
}

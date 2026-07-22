<?php

declare(strict_types=1);

namespace Aspell\Tests\Dictionary;

use Aspell\Dictionary\AffixRules;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the affix-file parser and its reverse (affix-stripping)
 * matching, exercised with small hand-written rule sets so the behaviour is
 * verifiable independent of any real dictionary.
 */
class AffixRulesTest extends TestCase
{
    /** A tiny English-ish rule set: SFX for plural/verb endings, PFX "un". */
    private function sampleRules(): AffixRules
    {
        return new AffixRules([
            'SET UTF-8',
            'PFX A Y 1',
            'PFX A   0     un         .',      // un + word
            'SFX B Y 2',
            'SFX B   0     s          [^s]',   // cat -> cats
            'SFX B   y     ies        y',      // party -> parties (strip y, add ies)
        ]);
    }

    public function testStripSuffixRecoversStemAndFlag(): void
    {
        $rules = $this->sampleRules();

        // "cats" -> strip "s" -> "cat" under flag B (condition [^s] on "cat").
        $cands = $rules->stripSuffix('cats');
        $this->assertContains(
            ['stem' => 'cat', 'flag' => 'B', 'cross' => true],
            $cands
        );
    }

    public function testStripSuffixReappliesStrippedCharacters(): void
    {
        $rules = $this->sampleRules();

        // "parties" -> remove "ies", restore "y" -> "party" (condition "y").
        $cands = $rules->stripSuffix('parties');
        $stems = array_column($cands, 'stem');
        $this->assertContains('party', $stems);
    }

    public function testSuffixConditionIsEnforced(): void
    {
        $rules = $this->sampleRules();

        // "buss" ends in "s", so the "[^s]" condition on the recovered stem
        // "bus" fails — no candidate from that rule.
        $stems = array_column($rules->stripSuffix('buss'), 'stem');
        $this->assertNotContains('bus', $stems);
    }

    public function testStripPrefixRecoversStemAndFlag(): void
    {
        $rules = $this->sampleRules();

        $cands = $rules->stripPrefix('unhappy');
        $this->assertContains(
            ['stem' => 'happy', 'flag' => 'A', 'cross' => true],
            $cands
        );
    }

    public function testEmptyForFileWithoutRules(): void
    {
        $rules = new AffixRules(['SET UTF-8', '# just a comment', 'TRY esiano']);
        $this->assertTrue($rules->isEmpty());
        $this->assertSame([], $rules->stripPrefix('anything'));
        $this->assertSame([], $rules->stripSuffix('anything'));
    }

    public function testFromFileReturnsNullOnMissingFile(): void
    {
        $this->assertNull(AffixRules::fromFile('/no/such/affix.dat'));
    }

    public function testNativeCharsetRulesAreConvertedToUtf8(): void
    {
        // A latin-1 suffix rule mapping a stem ending in "e" to one ending in
        // "é" (0xE9): strip "e", add "é", condition "e" (e.g. cafe -> café).
        $rules = new AffixRules([
            'SET ISO8859-1',
            'SFX C Y 1',
            'SFX C   e     ' . "\xE9" . '     e',
        ], 'ISO-8859-1');

        // The query is UTF-8 "caf" + "é"; reversing recovers the stem "cafe",
        // proving the native-charset rule was converted to UTF-8.
        $cands = $rules->stripSuffix("caf\u{00E9}");
        $stems = array_column($cands, 'stem');
        $this->assertContains('cafe', $stems);
    }
}

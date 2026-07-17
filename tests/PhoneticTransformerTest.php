<?php

declare(strict_types=1);

namespace Aspell\Tests\Engine;

use Aspell\Engine\PhoneticTransformer;
use PHPUnit\Framework\TestCase;

class PhoneticTransformerTest extends TestCase
{
    public function testBasicTransformation(): void
    {
        // Simple rules: replace PH with F, SH with S
        $rules = [
            ['PH', 'F'],
            ['SH', 'S'],
        ];
        
        // Simple uppercase map
        $toClean = [];
        for ($i = 0; $i < 256; $i++) {
            $toClean[$i] = strtoupper(chr($i));
        }

        $transformer = new PhoneticTransformer($rules, ['version' => '1.0'], $toClean);

        $this->assertEquals('FOTOGRAF', $transformer->transform('photograph'));
        $this->assertEquals('SIP', $transformer->transform('ship'));
    }

    public function testFollowupRule(): void
    {
        // Rule 1: CI -> SI
        // Rule 2: I -> E (followup)
        $rules = [
            ['CI', 'SI'],
            ['I', 'E'],
        ];

        $toClean = [];
        for ($i = 0; $i < 256; $i++) {
            $toClean[$i] = strtoupper(chr($i));
        }

        $transformer = new PhoneticTransformer($rules, ['version' => '1.0', 'followup' => true], $toClean);

        // 'CITY' -> 'C' matches 'CI' -> 'SI'. 
        // Followup check at pos 0+2-1 = 1 (char 'I'):
        // 'I' matches 'I' -> 'E'. 
        // Since 'I' rule has length 1, and 'CI' rule has length 2, 
        // Aspell logic is a bit complex here. 
        // If followup is true, it might skip the first rule if a better rule starts at the end of the match.
        
        $result = $transformer->transform('city');
        // If CI matches, it outputs S then continues with I.
        // Actually phonet.cpp:358: if (p0 >= p && parms.rules[n0][0] == c0) { n += 2; continue; }
        // p0 is followup priority (default 5), p is current rule priority (default 5).
        // Since 5 >= 5, it should skip 'CI' and use 'C' (default) then 'I' -> 'E'.
        
        $this->assertEquals('SITY', $result);
    }
    
    public function testCollapseResult(): void
    {
        $rules = [];
        $toClean = [];
        for ($i = 0; $i < 256; $i++) {
            $toClean[$i] = strtoupper(chr($i));
        }

        $transformer = new PhoneticTransformer($rules, ['version' => '1.0', 'collapse_result' => true], $toClean);
        $this->assertEquals('APLE', $transformer->transform('apple'));
        
        $transformerNotCollapsed = new PhoneticTransformer($rules, ['version' => '1.0', 'collapse_result' => false], $toClean);
        $this->assertEquals('APPLE', $transformerNotCollapsed->transform('apple'));
    }
}

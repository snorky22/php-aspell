<?php

declare(strict_types=1);

namespace Aspell\Engine;

/**
 * Port of the phonetic transformation logic from GNU Aspell.
 * This class implements the "Phonet" algorithm.
 */
class PhoneticTransformer
{
    private bool $followup = true;
    private bool $collapseResult = false;
    private bool $removeAccents = true;
    private string $version = '';

    /** @var array<int, int> Rules hash by first character ASCII code */
    private array $hash = [];

    /** @var array<int, array{0: string, 1: string}> Array of rules [search, replacement] */
    private array $rules = [];

    /** @var array<int, string> Translation table for character cleaning */
    private array $toClean = [];

    public function __construct(
        array $rules,
        array $options = [],
        /** @var array<int, string> $toClean Map of ASCII -> cleaned character (e.g. accented to non-accented upper) */
        array $toClean = []
    ) {
        $this->followup = (bool)($options['followup'] ?? true);
        $this->collapseResult = (bool)($options['collapse_result'] ?? false);
        $this->removeAccents = (bool)($options['remove_accents'] ?? true);
        $this->version = (string)($options['version'] ?? '');
        $this->toClean = $toClean;

        $this->initRules($rules);
    }

    private function initRules(array $rules): void
    {
        $this->rules = $rules;
        $this->hash = array_fill(0, 256, -1);

        foreach ($this->rules as $i => $rule) {
            $search = $rule[0];
            if ($search === '') {
                continue;
            }

            $firstChar = ord($search[0]);
            if ($this->hash[$firstChar] === -1) {
                $this->hash[$firstChar] = $i;
            }
        }
    }

    /**
     * Transforms a word into its phonetic representation.
     */
    public function transform(string $inWord): string
    {
        $word = $this->prepareWord($inWord);
        if ($word === '') {
            return '';
        }

        $target = '';
        $wordLen = strlen($word);
        $i = 0; // index in $word
        $z = 0; // flag for '<' rule used

        while ($i < $wordLen) {
            $c = $word[$i];
            $n = $this->hash[ord($c)] ?? -1;
            $z0 = 0; // flag for rule matched at this position

            if ($n >= 0) {
                for ($currN = $n; $currN < count($this->rules) && $this->rules[$currN][0][0] === $c; $currN++) {
                    $rule = $this->rules[$currN];
                    $search = $rule[0];
                    $replace = $rule[1];

                    $match = $this->matchRule($word, $i, $search, $k, $p);

                    if ($match) {
                        // Check followup rules
                        if ($this->shouldSkipDueToFollowup($word, $i, $k, $p, $search)) {
                            continue;
                        }

                        // Replace/Transform
                        $isLessRule = (str_contains($search, '<'));

                        if ($isLessRule && $z === 0) {
                            // rule with '<' used
                            if ($target !== '' && $replace !== '' && (substr($target, -1) === $c || substr($target, -1) === $replace[0])) {
                                $target = substr($target, 0, -1);
                            }
                            $z0 = 1;
                            $z = 1;
                            
                            // Replace in word itself for '<' rules
                            $word = substr($word, 0, $i) . $replace . substr($word, $i + $k);
                            $wordLen = strlen($word);
                            $c = $word[$i];
                        } else {
                            $i += $k - 1;
                            $z = 0;
                            $replaceLen = strlen($replace);
                            for ($ri = 0; $ri < $replaceLen - 1; $ri++) {
                                if ($target === '' || substr($target, -1) !== $replace[$ri]) {
                                    $target .= $replace[$ri];
                                }
                            }
                            $c = $replace[$replaceLen - 1] ?? '';
                            
                            if (str_contains($search, '^^')) {
                                if ($c !== '') {
                                    $target .= $c;
                                }
                                $word = substr($word, $i + 1);
                                $wordLen = strlen($word);
                                $i = 0;
                                $z0 = 1;
                            }
                        }
                        
                        if ($z0 === 1 || $z === 0) {
                           break; // Rule applied
                        }
                    }
                }
            }

            if ($z0 === 0) {
                if ($c !== '' && (!$this->collapseResult || $target === '' || substr($target, -1) !== $c)) {
                    $target .= $c;
                }
                $i++;
                $z = 0;
            }
        }

        return $target;
    }

    private function prepareWord(string $inWord): string
    {
        $word = '';
        $len = strlen($inWord);
        for ($i = 0; $i < $len; $i++) {
            $char = $inWord[$i];
            $cleaned = $this->toClean[ord($char)] ?? null;
            if ($cleaned !== null && $cleaned !== "\0" && $cleaned !== 0) {
                $word .= (is_int($cleaned) ? chr($cleaned) : $cleaned);
            }
        }
        return $word;
    }

    private function matchRule(string $word, int $pos, string $search, &$k, &$p): bool
    {
        $wordLen = strlen($word);
        $sIdx = 1; // skip first char as it's already matched
        $k = 1;
        $p = 5; // default priority

        while ($sIdx < strlen($search) && 
               $pos + $k < $wordLen && 
               $word[$pos + $k] === $search[$sIdx] && 
               !ctype_digit($search[$sIdx]) && 
               !str_contains('(-<^$', $search[$sIdx])) {
            $k++;
            $sIdx++;
        }

        if ($sIdx < strlen($search) && $search[$sIdx] === '(') {
            $sIdx++;
            $options = '';
            while ($sIdx < strlen($search) && $search[$sIdx] !== ')') {
                $options .= $search[$sIdx];
                $sIdx++;
            }
            if ($sIdx < strlen($search) && $search[$sIdx] === ')') {
                $sIdx++;
            }
            
            if ($pos + $k < $wordLen && str_contains($options, $word[$pos + $k])) {
                $k++;
            } else {
                return false;
            }
        }

        $p0 = $search[$sIdx] ?? null;
        $k0 = $k;

        while ($sIdx < strlen($search) && $search[$sIdx] === '-' && $k > 1) {
            $k--;
            $sIdx++;
        }

        if ($sIdx < strlen($search) && $search[$sIdx] === '<') {
            $sIdx++;
        }

        if ($sIdx < strlen($search) && ctype_digit($search[$sIdx])) {
            $p = (int)$search[$sIdx];
            $sIdx++;
        }

        if ($sIdx < strlen($search) && $search[$sIdx] === '^' && ($sIdx + 1) < strlen($search) && $search[$sIdx + 1] === '^') {
            $sIdx++;
        }

        $remainingSearch = substr($search, $sIdx);
        if ($remainingSearch === '' || $remainingSearch === "\0") {
            return true;
        }

        if ($remainingSearch[0] === '^') {
            $isStart = ($pos === 0); // Simplified: Aspell checks if word[pos-1] is non-alpha
            $isEndToo = (isset($remainingSearch[1]) && $remainingSearch[1] === '$');
            if ($isStart) {
                if (!$isEndToo || ($pos + $k0 >= $wordLen)) {
                    return true;
                }
            }
            return false;
        }

        if ($remainingSearch[0] === '$') {
            if ($pos > 0 && ($pos + $k0 >= $wordLen)) {
                return true;
            }
            return false;
        }

        return false;
    }

    private function shouldSkipDueToFollowup(string $word, int $pos, int $k, int $p, string $search): bool
    {
        if (!$this->followup || $k <= 1 || str_contains($search, '-') || $pos + $k >= strlen($word)) {
            return false;
        }

        $c0 = $word[$pos + $k - 1];
        $n0 = $this->hash[ord($c0)] ?? -1;

        if ($n0 < 0) {
            return false;
        }

        for ($currN0 = $n0; $currN0 < count($this->rules) && $this->rules[$currN0][0][0] === $c0; $currN0++) {
            $fRule = $this->rules[$currN0][0];
            $fk = 1;
            $fp = 5;
            
            if ($this->matchRule($word, $pos + $k - 1, $fRule, $fk, $fp)) {
                if ($fk === 1) {
                    continue;
                }
                if ($fp < $p) {
                    continue;
                }
                return true; // Found a better followup rule
            }
        }

        return false;
    }
}

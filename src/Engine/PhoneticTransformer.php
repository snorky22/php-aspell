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

    /** @var array<int, int> */
    private array $multiByteRules = [];

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

        if (empty($this->toClean)) {
            for ($i = 0; $i < 256; $i++) {
                $this->toClean[$i] = strtoupper(chr($i));
            }
        }

        $this->initRules($rules);
    }

    public static function fromFile(string $filename): self
    {
        $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Could not read phonetic file: $filename");
        }

        $rules = [];
        $options = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(\w+)\s+(.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = $matches[2];
                if ($key === 'version') {
                    $options['version'] = $value;
                } elseif ($key === 'followup') {
                    $options['followup'] = ($value === 'true');
                } elseif ($key === 'collapse_result') {
                    $options['collapse_result'] = ($value === 'true');
                } else {
                    // It's a rule
                    $rules[] = [$key, $value === '_' ? '' : $value];
                }
            }
        }

        return new self($rules, $options);
    }

    private function initRules(array $rules): void
    {
        $this->rules = $rules;
        $this->hash = array_fill(0, 256, -1);
        $this->multiByteRules = [];

        foreach ($this->rules as $i => $rule) {
            $search = $rule[0];
            if ($search === '') {
                continue;
            }

            $firstChar = mb_substr($search, 0, 1, 'UTF-8');
            $cOrd = mb_ord($firstChar, 'UTF-8');
            if ($cOrd < 256) {
                if ($this->hash[$cOrd] === -1) {
                    $this->hash[$cOrd] = $i;
                }
            } else {
                $this->multiByteRules[] = $i;
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
        $wordLen = mb_strlen($word, 'UTF-8');
        $i = 0; // index in $word (character index)
        $z = 0; // flag for '<' rule used

        while ($i < $wordLen) {
            $c = mb_substr($word, $i, 1, 'UTF-8');
            // We still use ASCII hash for the first byte if it's ASCII, 
            // but for multi-byte we might need a better hash or just a full scan.
            // GNU Aspell's Phonet is generally designed for 8-bit.
            // For now, we'll try to support it by using the first character.
            $cOrd = mb_ord($c, 'UTF-8');
            $n = ($cOrd < 256) ? ($this->hash[$cOrd] ?? -1) : -1;
            $z0 = 0; // flag for rule matched at this position

            if ($n >= 0) {
                for ($currN = $n; $currN < count($this->rules) && mb_substr($this->rules[$currN][0], 0, 1, 'UTF-8') === $c; $currN++) {
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
                            if ($target !== '' && $replace !== '' && (mb_substr($target, -1, 1, 'UTF-8') === $c || mb_substr($target, -1, 1, 'UTF-8') === mb_substr($replace, 0, 1, 'UTF-8'))) {
                                $target = mb_substr($target, 0, -1, 'UTF-8');
                            }
                            $z0 = 1;
                            $z = 1;
                            
                            // Replace in word itself for '<' rules
                            $word = mb_substr($word, 0, $i, 'UTF-8') . $replace . mb_substr($word, $i + $k, null, 'UTF-8');
                            $wordLen = mb_strlen($word, 'UTF-8');
                            $c = mb_substr($word, $i, 1, 'UTF-8');
                        } else {
                            $i += $k - 1;
                            $z = 0;
                            $replaceLen = mb_strlen($replace, 'UTF-8');
                            for ($ri = 0; $ri < $replaceLen - 1; $ri++) {
                                if ($target === '' || mb_substr($target, -1, 1, 'UTF-8') !== mb_substr($replace, $ri, 1, 'UTF-8')) {
                                    $target .= mb_substr($replace, $ri, 1, 'UTF-8');
                                }
                            }
                            $c = mb_substr($replace, $replaceLen - 1, 1, 'UTF-8');
                            
                            if (str_contains($search, '^^')) {
                                if ($c !== '') {
                                    $target .= $c;
                                }
                                $word = mb_substr($word, $i + 1, null, 'UTF-8');
                                $wordLen = mb_strlen($word, 'UTF-8');
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
                if ($c !== '' && (!$this->collapseResult || $target === '' || mb_substr($target, -1, 1, 'UTF-8') !== $c)) {
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
        $len = mb_strlen($inWord, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($inWord, $i, 1, 'UTF-8');
            $cOrd = mb_ord($char, 'UTF-8');
            if ($cOrd < 256) {
                $cleaned = $this->toClean[$cOrd] ?? null;
                if ($cleaned !== null && $cleaned !== "\0" && $cleaned !== 0) {
                    $word .= (is_int($cleaned) ? chr($cleaned) : $cleaned);
                }
            } else {
                // Keep multi-byte as is for now
                $word .= $char;
            }
        }
        return $word;
    }

    private function matchRule(string $word, int $pos, string $search, &$k, &$p): bool
    {
        $wordLen = mb_strlen($word, 'UTF-8');
        $sIdx = 1; // skip first char as it's already matched
        $k = 1;
        $p = 5; // default priority

        while ($sIdx < strlen($search) && 
               $pos + $k < $wordLen && 
               mb_substr($word, $pos + $k, 1, 'UTF-8') === $search[$sIdx] && 
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
            
            if ($pos + $k < $wordLen && str_contains($options, mb_substr($word, $pos + $k, 1, 'UTF-8'))) {
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
        if (!$this->followup || $k <= 1 || str_contains($search, '-') || $pos + $k >= mb_strlen($word, 'UTF-8')) {
            return false;
        }

        $c0 = mb_substr($word, $pos + $k - 1, 1, 'UTF-8');
        $c0Ord = mb_ord($c0, 'UTF-8');
        $n0 = ($c0Ord < 256) ? ($this->hash[$c0Ord] ?? -1) : -1;

        if ($n0 < 0) {
            return false;
        }

        for ($currN0 = $n0; $currN0 < count($this->rules) && mb_substr($this->rules[$currN0][0], 0, 1, 'UTF-8') === $c0; $currN0++) {
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

<?php

declare(strict_types=1);

namespace Aspell\Engine;

/**
 * Weights for the edit distance algorithm.
 */
readonly class EditDistanceWeights
{
    public int $min;
    public int $max;

    public function __construct(
        public int $del1 = 1,
        public int $del2 = 1,
        public int $swap = 1,
        public int $sub = 1,
        public int $similar = 0,
    ) {
        $this->min = min($this->del1, $this->del2, $this->swap, $this->sub);
        $this->max = max($this->del1, $this->del2, $this->swap, $this->sub);
    }
}

/**
 * Port of the weighted edit distance algorithm from GNU Aspell.
 * Used to calculate the distance between a misspelled word and a suggestion.
 */
class SuggestionEngine
{
    /**
     * Calculates the weighted edit distance between two strings.
     * This is an implementation of the Damerau-Levenshtein distance with custom weights.
     */
    public function editDistance(string $a, string $b, EditDistanceWeights $w = new EditDistanceWeights()): int
    {
        $aSize = strlen($a);
        $bSize = strlen($b);

        if ($aSize === 0) {
            return $bSize * $w->del1;
        }
        if ($bSize === 0) {
            return $aSize * $w->del2;
        }

        // Matrix for DP
        $e = [];
        for ($i = 0; $i <= $aSize; $i++) {
            $e[$i] = array_fill(0, $bSize + 1, 0);
        }

        $e[0][0] = 0;
        for ($j = 1; $j <= $bSize; $j++) {
            $e[0][$j] = $e[0][$j - 1] + $w->del1;
        }

        for ($i = 1; $i <= $aSize; $i++) {
            $e[$i][0] = $e[$i - 1][0] + $w->del2;
            for ($j = 1; $j <= $bSize; $j++) {
                if ($a[$i - 1] === $b[$j - 1]) {
                    $e[$i][$j] = $e[$i - 1][$j - 1];
                } else {
                    $e[$i][$j] = $w->sub + $e[$i - 1][$j - 1];

                    if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                        $te = $w->swap + $e[$i - 2][$j - 2];
                        if ($te < $e[$i][$j]) {
                            $e[$i][$j] = $te;
                        }
                    }

                    $te = $w->del1 + $e[$i - 1][$j];
                    if ($te < $e[$i][$j]) {
                        $e[$i][$j] = $te;
                    }

                    $te = $w->del2 + $e[$i][$j - 1];
                    if ($te < $e[$i][$j]) {
                        $e[$i][$j] = $te;
                    }
                }
            }
        }

        return $e[$aSize][$bSize];
    }
}

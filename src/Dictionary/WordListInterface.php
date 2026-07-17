<?php

declare(strict_types=1);

namespace Aspell\Dictionary;

/**
 * Metadata for a word entry in the dictionary.
 */
readonly class WordEntry
{
    public function __construct(
        public string $word,
        public int $wordInfo = 0,
        public ?string $affix = null,
        public ?string $soundslike = null,
    ) {}
}

/**
 * Interface for word list providers.
 */
interface WordListInterface
{
    /**
     * Checks if a word exists in the word list.
     */
    public function lookup(string $word): bool;

    /**
     * Retrieves suggestions for a word (handled by SuggestionEngine, but dictionary might provide base data).
     */
    public function soundslikeLookup(string $soundslike): array;
}

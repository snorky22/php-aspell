<?php

declare(strict_types=1);

namespace Aspell\Engine;

use Aspell\Config\AspellConfig;
use Aspell\Dictionary\AspellBinaryParser;

/**
 * High-level Speller class that orchestrates the spell checking process.
 */
class Speller
{
    private ?AspellBinaryParser $dictionary = null;
    private PhoneticTransformer $phoneticTransformer;
    private SuggestionEngine $suggestionEngine;

    public function __construct(
        private readonly AspellConfig $config
    ) {
        $this->suggestionEngine = new SuggestionEngine();
        // Default phonetic transformer (empty rules for now, would be loaded from .dat files)
        $toClean = [];
        for ($i = 0; $i < 256; $i++) {
            $toClean[$i] = strtoupper(chr($i));
        }
        $this->phoneticTransformer = new PhoneticTransformer([], [], $toClean);
    }

    public function loadDictionary(string $path): void
    {
        $toClean = [];
        for ($i = 0; $i < 256; $i++) {
            $toClean[$i] = strtoupper(chr($i));
        }
        $this->dictionary = new AspellBinaryParser($path, $toClean);
    }

    public function check(string $word): bool
    {
        if ($this->dictionary === null) {
            return false;
        }
        return $this->dictionary->lookup($word);
    }

    /**
     * @return string[]
     */
    public function suggest(string $word): array
    {
        // In a real Aspell port, we would:
        // 1. Get phonetic version of $word
        // 2. Look up near-misses in dictionary using phonetic jump tables
        // 3. Rank results using SuggestionEngine (EditDistance)
        // For now, this is a skeleton.
        return [];
    }

    /**
     * Checks a whole document, skipping LaTeX commands if requested.
     */
    public function checkDocument(string $text, string $mode = 'text'): array
    {
        if ($mode === 'tex' || $mode === 'latex') {
            $text = $this->filterLaTeX($text);
        }

        $misspelled = [];
        // Simple tokenization
        preg_match_all('/\b[a-zA-Z]+\b/', $text, $matches);

        foreach ($matches[0] as $word) {
            if (!$this->check($word)) {
                $misspelled[$word] = $this->suggest($word);
            }
        }

        return $misspelled;
    }

    private function filterLaTeX(string $text): string
    {
        // Remove comments
        $text = preg_replace('/(?<!\\\\)%.*/', '', $text);
        
        // Replace commands like \command{...} with just spaces for the command name
        // but keep the content in brackets for checking (depending on command)
        // For simplicity, we just strip commands starting with \
        $text = preg_replace('/\\\\[a-zA-Z]+(\[[^\]]*\])?(\{([^\}]*)\})?/', ' ', $text);
        
        // Strip remaining special LaTeX characters
        $text = str_replace(['{', '}', '$', '&', '_', '^'], ' ', $text);
        
        return $text;
    }
}

<?php

declare(strict_types=1);

namespace Aspell\Engine;

use Aspell\Config\AspellConfig;
use Aspell\Dictionary\AspellBinaryParser;
use Aspell\Dictionary\CustomDictionary;

/**
 * High-level Speller class that orchestrates the spell checking process.
 */
class Speller
{
    /** @var array<AspellBinaryParser|CustomDictionary> */
    private array $dictionaries = [];

    /** Writable dictionary that runtime-added words are stored in. */
    private ?CustomDictionary $customDictionary = null;

    private PhoneticTransformer $phoneticTransformer;
    private SuggestionEngine $suggestionEngine;

    private string $currentCharset = 'utf-8';

    /** @var string[]|null Memoised merge of every dictionary's word list. */
    private ?array $loadedWords = null;

    /** @var array<string, string[]>|null First-character bucket index. */
    private ?array $wordIndex = null;
    public static array $citeCommands = [
        '\cite',
        '\citep',
        '\citet',
        '\citep*',
        '\citet*',
        '\citealt',
        '\citealt*',
        '\citealp',
        '\citealp*',
        '\citeauthor',
        '\citeauthor*',
        '\citenum',
        '\citetext'
    ];
    public static array $refCommands = [
        '\ref',
        '\ref*',
        '\autoref',
        '\subref',
        '\eqref',
    ];

    public function __construct(
        private readonly AspellConfig $config
    ) {
        $this->suggestionEngine = new SuggestionEngine();
        $this->initPhoneticTransformer();
    }

    public function setCharset(string $charset): void
    {
        $this->currentCharset = strtolower($charset);
    }

    private function initPhoneticTransformer(): void
    {
        $toClean = [];
        for ($i = 0; $i < 256; $i++) {
            $char = chr($i);
            $toClean[$i] = strtoupper($char);
        }
        $this->phoneticTransformer = new PhoneticTransformer([], [], $toClean);
    }

    public function loadDictionary(string $path): void
    {
        // A new dictionary invalidates the memoised word list / index.
        $this->loadedWords = null;
        $this->wordIndex = null;

        $dir = dirname($path);
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $datFile = $dir . DIRECTORY_SEPARATOR . $stem . '.dat';
        $charset = $this->currentCharset;

        if (file_exists($datFile)) {
            $lines = file($datFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $encodingFound = false;
            foreach ($lines as $line) {
                if (preg_match('/^data-encoding\s+(.+)$/', $line, $matches)) {
                    $charset = trim($matches[1]);
                    $encodingFound = true;
                }
                if (!$encodingFound && preg_match('/^charset\s+(.+)$/', $line, $matches)) {
                    $charset = trim($matches[1]);
                }
            }
        }

        // Mapping for Aspell specific aliases to PHP mb_convert_encoding names
        $charsetMap = [
            'iso8859-1' => 'ISO-8859-1',
            'iso8859-15' => 'ISO-8859-15',
            'koi8-r' => 'KOI8-R',
            'l-ar' => 'CP1256', // Approximation for Arabic if it's not actually UTF-8
        ];
        
        if (isset($charsetMap[strtolower($charset)])) {
            $charset = $charsetMap[strtolower($charset)];
        }

        $toClean = [];
        for ($i = 0; $i < 256; $i++) {
            $char = chr($i);
            // In French, ISO-8859-1 capitalization of accented characters:
            // 0xE0 (à) -> 0xC0 (À)
            // But we'll use mb_strtoupper for better coverage if we assume the input to clean is ISO-8859-1
            // Actually, toClean is used during hashing on the raw bytes of the word.
            // If the dictionary is ISO-8859-1, then toClean should map ISO-8859-1 lower to upper.
            try {
                $encoded = mb_convert_encoding($char, 'UTF-8', $charset);
                $upper = mb_strtoupper($encoded, 'UTF-8');
                $back = mb_convert_encoding($upper, $charset, 'UTF-8');
                $toClean[$i] = $back;
            } catch (\Exception $e) {
                $toClean[$i] = strtoupper($char);
            }
        }

        if (str_ends_with($path, '.multi')) {
            $this->loadMultiDictionary($path, $toClean, $charset);
        } else {
            $this->dictionaries[] = new AspellBinaryParser($path, $toClean, $charset);
        }

        // Try to load phonetic rules automatically
        $this->autoLoadPhoneticRules($dir, $stem);
    }

    /**
     * Loads (or creates) a writable custom dictionary that is checked in
     * addition to the language dictionaries. Words added via {@see addWord()}
     * are stored here and persisted to $path in Aspell's personal word list
     * format. Only one custom dictionary is active at a time; calling this again
     * replaces the previous one.
     */
    public function loadCustomDictionary(string $path): void
    {
        $lang = (string) ($this->config->retrieve('lang') ?? 'en');
        $this->setCustomDictionary(new CustomDictionary($path, $lang));
    }

    /**
     * Registers a custom dictionary instance, replacing any previous one. Useful
     * for supplying an in-memory (non-persistent) dictionary directly.
     */
    public function setCustomDictionary(CustomDictionary $dictionary): void
    {
        // Drop the previous custom dictionary from the active list, if any.
        if ($this->customDictionary !== null) {
            $this->dictionaries = array_values(array_filter(
                $this->dictionaries,
                fn ($dict) => $dict !== $this->customDictionary
            ));
        }

        $this->customDictionary = $dictionary;
        $this->dictionaries[] = $dictionary;

        // A new set of words invalidates the memoised word list / index.
        $this->loadedWords = null;
        $this->wordIndex = null;
    }

    /**
     * Replaces the active custom dictionary with one rebuilt from a JSON string
     * (as produced by {@see getCustomDictionaryAsJson()}). The resulting
     * dictionary is in-memory only — nothing is written to disk — which is the
     * intended shape when the persistent copy lives in a database.
     *
     * @see CustomDictionary::fromJson() for the accepted JSON shapes.
     */
    public function setCustomDictionaryFromJson(string $json): void
    {
        $lang = (string) ($this->config->retrieve('lang') ?? 'en');
        $this->setCustomDictionary(CustomDictionary::fromJson($json, null, $lang));
    }

    /**
     * Serializes the active custom dictionary to a JSON string, ready to be
     * stored (e.g. in a database column). When no custom dictionary is
     * configured, an empty word list is returned so callers always get valid
     * JSON.
     */
    public function getCustomDictionaryAsJson(): string
    {
        if ($this->customDictionary === null) {
            $lang = (string) ($this->config->retrieve('lang') ?? 'en');
            return CustomDictionary::fromJson('[]', null, $lang)->toJson();
        }

        return $this->customDictionary->toJson();
    }

    /**
     * Adds a word to the custom dictionary so it is treated as correctly spelled
     * from now on (and persisted, if the custom dictionary is backed by a file).
     *
     * If no custom dictionary has been configured, an in-memory one is created
     * automatically; call {@see loadCustomDictionary()} first for persistence.
     *
     * @return bool false if the word was already known to the custom dictionary.
     */
    public function addWord(string $word): bool
    {
        if ($this->customDictionary === null) {
            $this->setCustomDictionary(new CustomDictionary());
        }

        $added = $this->customDictionary->addWord($word);

        if ($added) {
            // Keep the suggestion caches in sync with the new word.
            $this->loadedWords = null;
            $this->wordIndex = null;
        }

        return $added;
    }

    private function autoLoadPhoneticRules(string $dir, string $stem): void
    {
        // Prefer the dictionary's own <stem>_phonet.dat, then common fallbacks.
        $phonetFiles = [
            $dir . DIRECTORY_SEPARATOR . $stem . '_phonet.dat',
            $dir . DIRECTORY_SEPARATOR . 'en_phonet.dat',
            $dir . DIRECTORY_SEPARATOR . 'phonet.dat',
        ];

        foreach ($phonetFiles as $file) {
            if (file_exists($file)) {
                try {
                    $this->phoneticTransformer = PhoneticTransformer::fromFile($file);
                    break;
                } catch (\Exception $e) {
                    // Ignore and try next
                }
            }
        }
    }

    private function loadMultiDictionary(string $path, array $toClean, string $charset): void
    {
        $dir = dirname($path);
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(add|include)\s+(.+)$/', $line, $matches)) {
                $file = $matches[2];
                if (!str_starts_with($file, '/')) {
                    $file = $dir . DIRECTORY_SEPARATOR . $file;
                }

                // If the file mentioned in .multi is missing, try common Aspell alternatives
                if (!file_exists($file)) {
                    $altFile = $file;
                    if (str_ends_with($file, '.rws')) {
                        $altFile = substr($file, 0, -4) . '.cwl';
                    } elseif (str_ends_with($file, '.cwl')) {
                        $altFile = substr($file, 0, -4) . '.rws';
                    }
                    if (file_exists($altFile)) {
                        $file = $altFile;
                    }
                }

                if (str_ends_with($file, '.multi')) {
                    $this->loadMultiDictionary($file, $toClean, $charset);
                } else {
                    $this->dictionaries[] = new AspellBinaryParser($file, $toClean, $charset);
                }
            }
        }
    }

    public function check(string $word): bool
    {
        if (empty($this->dictionaries)) {
            return false;
        }

        // Try exact match
        foreach ($this->dictionaries as $dict) {
            if ($dict->lookup($word)) {
                return true;
            }
        }

        // Try lowercase match
        $lower = mb_strtolower($word, 'UTF-8');
        if ($lower !== $word) {
            foreach ($this->dictionaries as $dict) {
                if ($dict->lookup($lower)) {
                    return true;
                }
            }
        }

        // Try title case match (if first char was upper and word was upper/title)
        // Aspell typically allows Title Case if lowercase is in dict.
        // We already checked lowercase.
        
        return false;
    }

    /**
     * @return string[]
     */
    public function suggest(string $word): array
    {
        if (empty($this->dictionaries)) {
            return [];
        }

        $phonetic = $this->phoneticTransformer->transform($word);
        $candidates = [];

        // Preferred path: phonetic ("soundslike") index, when the dictionary
        // provides one.
        foreach ($this->dictionaries as $dict) {
            foreach ($dict->soundslikeLookup($phonetic) as $match) {
                $candidates[$match] = $this->suggestionEngine->editDistance($word, $match);
            }
        }

        // Fallback for compiled word lists (.cwl) that carry no soundslike
        // tables: a bounded edit-distance scan over a first-character bucket,
        // further filtered by length, so it stays tractable on large
        // dictionaries.
        if (empty($candidates)) {
            $lower = mb_strtolower($word, 'UTF-8');
            $wordLen = mb_strlen($lower, 'UTF-8');
            $first = mb_substr($lower, 0, 1, 'UTF-8');

            foreach ($this->wordIndex()[$first] ?? [] as $cand) {
                $candLen = mb_strlen($cand, 'UTF-8');
                if (abs($candLen - $wordLen) > 2) {
                    continue;
                }
                $candidates[$cand] = $this->suggestionEngine->editDistance($lower, $cand);
            }
        }

        asort($candidates);
        return array_keys(array_slice($candidates, 0, 10, true));
    }

    /**
     * Lazily built index mapping each first character to the list of loaded
     * words starting with it. Speeds up the suggestion fallback dramatically
     * (one bucket instead of a full-dictionary scan per call).
     *
     * @return array<string, string[]>
     */
    private function wordIndex(): array
    {
        if ($this->wordIndex === null) {
            $this->wordIndex = [];
            foreach ($this->getLoadedWords() as $cand) {
                // Key on the lowercased first character so mixed-case entries
                // (e.g. custom-dictionary words like "Symfony") are reachable
                // from the lowercased query used in suggest().
                $first = mb_strtolower(mb_substr($cand, 0, 1, 'UTF-8'), 'UTF-8');
                $this->wordIndex[$first][] = $cand;
            }
        }
        return $this->wordIndex;
    }

    /**
     * Builds a whole-word alternation regex that locates every given word in a
     * body of text. Output-format agnostic: callers reuse it both to apply
     * corrections to plain text and to highlight matches in HTML. Apostrophes
     * are treated as word-internal (matching {@see checkDocument()}'s tokenizer)
     * so contractions such as "don't" are matched whole.
     *
     * @param list<string> $words words to match (e.g. unique misspellings)
     * @return string PCRE pattern, or '' when $words is empty
     */
    public function misspellingRegex(array $words): string
    {
        if ($words === []) {
            return '';
        }

        $alt = implode('|', array_map(static fn ($w) => preg_quote($w, '/'), $words));
        // Apostrophes are word-internal; match on whole words only.
        return '/(?<![\p{L}\'])(' . $alt . ')(?![\p{L}\'])/iu';
    }

    /**
     * TexFilter rules that ignore the {…} argument of each given command. Used
     * for citation and cross-reference commands (\cite…, \ref…), whose
     * arguments are keys/labels rather than real words — the same treatment
     * TexFilter already gives \label.
     *
     * @param list<string> $commands command names, with or without a leading
     *                                backslash and an optional trailing star
     * @return array<string, string> bare command name => 'p' (ignore its {…})
     */
    private function commandArgRules(array $commands): array
    {
        $rules = [];
        foreach ($commands as $command) {
            // TexFilter keys on the bare command name and handles a trailing
            // star ("\citep*", "\ref*") itself, so strip the backslash and star.
            $name = rtrim(ltrim($command, '\\'), '*');
            if ($name !== '') {
                $rules[$name] = 'p';
            }
        }
        return $rules;
    }

    /**
     * Blanks out the entire thebibliography environment. The reference list is
     * auto-generated bibliographic metadata (author surnames, journal names,
     * cite keys such as "halloran1997study") rather than prose, so checking it
     * only produces noise. The matched span is replaced with an equal-length
     * run of spaces so byte offsets into $text stay valid for callers that use
     * them.
     */
    private function stripBibliography(string $text): string
    {
        return (string) preg_replace_callback(
            '/\\\\begin\s*\{thebibliography\}.*?\\\\end\s*\{thebibliography\}/su',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $text
        );
    }

    /**
     * Checks a whole document, skipping LaTeX commands if requested.
     */
    public function checkDocument(string $text, string $mode = 'text'): array
    {
        if ($mode === 'tex' || $mode === 'latex') {
            // The reference list is machine-generated metadata (author names,
            // journal titles, cite keys), not prose — drop the whole
            // thebibliography environment before checking anything.
            $text = $this->stripBibliography($text);

            // Citation and cross-reference commands (\cite, \citep, \ref,
            // \autoref, …) carry keys/labels, not prose, so their arguments
            // must not be spell-checked.
            $rules = $this->commandArgRules(
                array_merge(self::$citeCommands, self::$refCommands)
            );
            $filter = new TexFilter($rules);
            $text = $filter->filter($text);
        }

        $results = [];
        // Words with this many characters or fewer are skipped, matching
        // Aspell's `ignore` option (default 1, i.e. skip single characters
        // such as isolated math variables).
        $ignoreLen = (int) ($this->config->retrieve('ignore') ?? 1);

        // Tokenize including multi-byte characters
        preg_match_all('/[\p{L}\']+/u', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $word = $match[0];
            $offset = $match[1];
            // Apostrophes are only word-internal (cf. Aspell's `special ' -*-`),
            // so strip any at the edges. This drops artefacts such as LaTeX
            // quotes ('' ) and math derivatives (x', f').
            $trimmed = trim($word, "'");
            if ($trimmed !== $word) {
                $offset += strpos($word, $trimmed);
                $word = $trimmed;
            }
            if (mb_strlen($word, 'UTF-8') <= $ignoreLen) {
                continue;
            }
            if (!$this->check($word)) {
                $results[] = [
                    'word' => $word,
                    'offset' => $offset,
                    // Suggestions are slow, skip them for document check by default or make optional
                ];
            }
        }

        return $results;
    }
    /**
     * @return string[] Every unique word across all loaded dictionaries.
     *                  Memoised, since it is reused by the suggestion index.
     */
    public function getLoadedWords(): array
    {
        if ($this->loadedWords === null) {
            $all = [];
            foreach ($this->dictionaries as $dict) {
                if (method_exists($dict, 'getWords')) {
                    $all[] = $dict->getWords();
                }
            }
            $this->loadedWords = $all === [] ? [] : array_merge(...$all);
        }
        return $this->loadedWords;
    }
}

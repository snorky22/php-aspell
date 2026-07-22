<?php

declare(strict_types=1);

namespace Aspell\Engine;

use Aspell\Config\AspellConfig;
use Aspell\Dictionary\AffixRules;
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
        '\pageref',
        '\nameref',
        // cleveref
        '\cref',
        '\Cref',
        '\cpageref',
        '\Cpageref',
        '\labelcref',
        '\namecref',
        '\nameCref',
        '\namecrefs',
        '\nameCrefs',
        // varioref
        '\vref',
        '\Vref',
        '\vpageref',
        '\Vpageref',
    ];

    /**
     * Cross-reference commands taking two mandatory label-key arguments
     * (e.g. \crefrange{start}{end}). Both {…} must be ignored, so these get a
     * 'pp' rule rather than the single 'p' used for {@see $refCommands}.
     */
    public static array $refRangeCommands = [
        '\crefrange',
        '\Crefrange',
        '\cpagerefrange',
        '\vrefrange',
        '\vpagerefrange',
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

        $affixName = '';
        $affixCompress = false;

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
                if (preg_match('/^affix\s+(\S+)/', $line, $matches)) {
                    $affixName = trim($matches[1]);
                }
                if (preg_match('/^affix-compress\s+(\S+)/', $line, $matches)) {
                    $affixCompress = strtolower(trim($matches[1])) === 'true';
                }
            }
        }

        // Mapping for Aspell specific aliases to PHP mb_convert_encoding names
        $charsetMap = [
            'iso8859-1' => 'ISO-8859-1',
            'iso8859-15' => 'ISO-8859-15',
            'iso-8859-8-nl' => 'ISO-8859-8', // Hebrew (drop the directionality suffix)
            'koi8-r' => 'KOI8-R',
            'l-ar' => 'CP1256', // Approximation for Arabic if it's not actually UTF-8
        ];

        if (isset($charsetMap[strtolower($charset)])) {
            $charset = $charsetMap[strtolower($charset)];
        }

        // Affix-compressed dictionaries (affix-compress true) store stems tagged
        // with affix flags; load the matching affix file so those stems can be
        // expanded into every inflected form they license.
        $affix = null;
        if ($affixCompress) {
            $affix = $this->loadAffixRules($dir, $affixName !== '' ? $affixName : $stem, $charset);
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
            $this->loadMultiDictionary($path, $toClean, $charset, $affix);
        } else {
            $this->dictionaries[] = new AspellBinaryParser($path, $toClean, $charset, $affix);
        }

        // Try to load phonetic rules automatically
        $this->autoLoadPhoneticRules($dir, $stem);
    }

    /**
     * Loads the affix rules for an affix-compressed dictionary. The file is
     * "<affixName>_affix.dat" in the dictionary directory (falling back to the
     * dictionary stem). Returns null when no usable rules are found, so loading
     * degrades gracefully to the un-expanded stem list.
     */
    private function loadAffixRules(string $dir, string $affixName, string $charset): ?AffixRules
    {
        $file = $dir . DIRECTORY_SEPARATOR . $affixName . '_affix.dat';
        if (!is_file($file)) {
            return null;
        }

        $rules = AffixRules::fromFile($file, $charset);
        return ($rules !== null && !$rules->isEmpty()) ? $rules : null;
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

    private function loadMultiDictionary(string $path, array $toClean, string $charset, ?AffixRules $affix = null): void
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
                    $this->loadMultiDictionary($file, $toClean, $charset, $affix);
                } else {
                    $this->dictionaries[] = new AspellBinaryParser($file, $toClean, $charset, $affix);
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
     * @param string $spec TexFilter rule per command; 'p' ignores one {…}
     *                     argument, 'pp' ignores two (for range commands).
     * @return array<string, string> bare command name => $spec
     */
    private function commandArgRules(array $commands, string $spec = 'p'): array
    {
        $rules = [];
        foreach ($commands as $command) {
            // TexFilter keys on the bare command name and handles a trailing
            // star ("\citep*", "\ref*") itself, so strip the backslash and star.
            $name = rtrim(ltrim($command, '\\'), '*');
            if ($name !== '') {
                $rules[$name] = $spec;
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
            // \autoref, \cref, …) carry keys/labels, not prose, so their
            // arguments must not be spell-checked. Range commands
            // (\crefrange, …) take two label-key arguments, hence 'pp'.
            $rules = array_merge(
                $this->commandArgRules(
                    array_merge(self::$citeCommands, self::$refCommands)
                ),
                $this->commandArgRules(self::$refRangeCommands, 'pp'),
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

    // -----------------------------------------------------------------------
    // Built-in dictionary manifest
    //
    // The package ships a tree of Aspell dictionaries under /dictionaries. The
    // web UI needs a small table mapping each language to a label and the path
    // of its dictionary — the $DICTIONARIES array in public/index.php. Rather
    // than hand-maintain that table, these helpers discover the dictionaries on
    // disk, serialize the result to a JSON manifest at build time, and restore
    // the same table from that JSON at runtime.
    // -----------------------------------------------------------------------

    /**
     * Default location of the bundled dictionaries: the /dictionaries directory
     * at the package root (this file lives in src/Engine/).
     */
    public static function defaultDictionaryRoot(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'dictionaries';
    }

    /**
     * Scans $root recursively and returns a manifest of the built-in
     * dictionaries, keyed by language code, in the exact shape consumed by the
     * web UI:
     *
     *   ['en' => ['label' => 'English', 'path' => '/…/en.multi'], …]
     *
     * Only files named exactly "<xx>.multi" — a bare two-letter language code —
     * are treated as a language's entry point. The regional and variant multis
     * (en_US.multi, en-variant_0.multi, fr_FR.multi, …) are the alternatives
     * that the canonical "<xx>.multi" already `add`s internally, so listing them
     * would only duplicate the same language. As instructed, the two letters
     * before ".multi" are trusted as the language code.
     *
     * Paths are returned absolute (ready to hand to {@see loadDictionary()});
     * entries are ordered by code. Discovery order on disk is not stable across
     * filesystems, so a duplicate code (same "<xx>.multi" in two trees) keeps
     * the shortest path for a deterministic result.
     *
     * @return array<string, array{label:string, path:string}>
     */
    public static function discoverDictionaries(?string $root = null): array
    {
        $root = $root ?? self::defaultDictionaryRoot();
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new \InvalidArgumentException("Dictionary root not found: {$root}");
        }

        /** @var array<string, string> $found code => absolute path */
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            // Trust the two-letter code before ".multi" as the language code.
            if (!preg_match('/^([a-z]{2})\.multi$/i', $file->getFilename(), $m)) {
                continue;
            }
            $code = strtolower($m[1]);
            $path = $file->getPathname();

            if (!isset($found[$code]) || strlen($path) < strlen($found[$code])) {
                $found[$code] = $path;
            }
        }

        ksort($found);

        $manifest = [];
        foreach ($found as $code => $path) {
            $manifest[$code] = [
                'label' => self::languageLabel($code),
                'path'  => $path,
            ];
        }

        return $manifest;
    }

    /**
     * Discovers the bundled dictionaries under $root and writes the manifest to
     * $jsonPath as JSON. Paths are stored relative to $root so the manifest
     * stays valid wherever the package is installed; {@see loadDictionaryManifest()}
     * resolves them back to absolute paths at runtime.
     *
     * @return array<string, array{label:string, path:string}> the discovered
     *         manifest (with absolute paths), for convenience.
     * @throws \RuntimeException if the file cannot be written.
     */
    public static function saveDictionaryManifest(string $jsonPath, ?string $root = null): array
    {
        $root = $root ?? self::defaultDictionaryRoot();
        $manifest = self::discoverDictionaries($root);

        if (file_put_contents($jsonPath, self::manifestToJson($manifest, $root), LOCK_EX) === false) {
            throw new \RuntimeException("Could not write dictionary manifest: {$jsonPath}");
        }

        return $manifest;
    }

    /**
     * Restores the $DICTIONARIES table from a manifest produced by
     * {@see saveDictionaryManifest()}. $source may be a path to the JSON file or
     * the JSON string itself. Relative paths are resolved against $root (the
     * dictionary root the paths were stored relative to); absolute paths are
     * kept as-is.
     *
     * @return array<string, array{label:string, path:string}> keyed by language
     *         code, with absolute paths — drop-in for the web UI.
     * @throws \JsonException            if the JSON is malformed.
     * @throws \InvalidArgumentException if the decoded shape is not a manifest.
     */
    public static function loadDictionaryManifest(string $source, ?string $root = null): array
    {
        $root = $root ?? self::defaultDictionaryRoot();

        // Accept either a path to a JSON file or a raw JSON string.
        $json = is_file($source) ? (string) file_get_contents($source) : $source;

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Dictionary manifest must decode to an object.');
        }

        // Tolerate both the wrapped form ({"dictionaries": {...}}) and a bare
        // {code: {...}} map.
        $entries = (isset($data['dictionaries']) && is_array($data['dictionaries']))
            ? $data['dictionaries']
            : $data;

        $realRoot = realpath($root) ?: $root;

        $dictionaries = [];
        foreach ($entries as $code => $entry) {
            if (!is_array($entry) || !isset($entry['path'])) {
                continue;
            }
            $code = (string) $code;
            $path = (string) $entry['path'];
            if (!self::isAbsolutePath($path)) {
                $path = $realRoot . DIRECTORY_SEPARATOR . $path;
            }
            $dictionaries[$code] = [
                'label' => (string) ($entry['label'] ?? self::languageLabel($code)),
                'path'  => $path,
            ];
        }

        return $dictionaries;
    }

    /**
     * Serializes a manifest to the JSON stored on disk, rewriting absolute paths
     * as paths relative to $root for portability.
     *
     * @param array<string, array{label:string, path:string}> $manifest
     */
    private static function manifestToJson(array $manifest, string $root): string
    {
        $realRoot = realpath($root) ?: $root;

        $out = [];
        foreach ($manifest as $code => $entry) {
            $out[$code] = [
                'label' => $entry['label'],
                'path'  => self::relativePath($realRoot, $entry['path']),
            ];
        }

        return (string) json_encode(
            ['dictionaries' => $out],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );
    }

    /** Human-readable label for a language code (native name where useful). */
    private static function languageLabel(string $code): string
    {
        static $labels = [
            'en' => 'English',
            'fr' => 'French — français',
            'ru' => 'Russian — русский',
            'ar' => 'Arabic — العربية',
            'de' => 'German — Deutsch',
            'es' => 'Spanish — español',
            'it' => 'Italian — italiano',
            'pt' => 'Portuguese — português',
            'nl' => 'Dutch — Nederlands',
            'pl' => 'Polish — polski',
            'sv' => 'Swedish — svenska',
            'nb' => 'Norwegian Bokmål — norsk',
            'da' => 'Danish — dansk',
            'fi' => 'Finnish — suomi',
            'cs' => 'Czech — čeština',
            'el' => 'Greek — Ελληνικά',
            'he' => 'Hebrew — עברית',
            'tr' => 'Turkish — Türkçe',
            'uk' => 'Ukrainian — українська',
            'ca' => 'Catalan — català',
            'ro' => 'Romanian — română',
            'hu' => 'Hungarian — magyar',
        ];

        return $labels[strtolower($code)] ?? strtoupper($code);
    }

    /** Returns $path relative to $root, or unchanged if it lies outside $root. */
    private static function relativePath(string $root, string $path): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /** True for POSIX ("/…") and Windows ("C:\…" / "C:/…") absolute paths. */
    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}

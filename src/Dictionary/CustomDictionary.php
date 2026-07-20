<?php

declare(strict_types=1);

namespace Aspell\Dictionary;

/**
 * A writable, in-memory word list loaded in addition to the compiled language
 * dictionaries. Words added at runtime are checked exactly like any other
 * dictionary entry and, when a path is bound, persisted to disk in GNU Aspell's
 * plain-text personal word list format:
 *
 *   personal_ws-1.1 <lang> <count> <encoding>
 *   word1
 *   word2
 *   ...
 *
 * Matching is case-insensitive, mirroring {@see AspellBinaryParser}: entries are
 * keyed by their lowercase form while the original spelling is preserved for
 * persistence and suggestions.
 */
class CustomDictionary implements WordListInterface
{
    /** @var array<string, string> lowercase key => original spelling */
    private array $words = [];

    /**
     * @param string|null $path Backing file. When set, it is read on
     *                          construction (if it exists) and rewritten on
     *                          every {@see addWord()} call.
     * @param string      $lang Language token written to the file header.
     */
    public function __construct(
        private readonly ?string $path = null,
        private readonly string $lang = 'en',
    ) {
        if ($this->path !== null && is_file($this->path)) {
            $this->loadFile($this->path);
        }
    }

    /**
     * Rebuilds a dictionary from a JSON string produced by {@see toJson()}. Two
     * shapes are accepted:
     *
     *   {"lang":"fr","words":["Symfony","café"]}   full form (preserves lang)
     *   ["Symfony","café"]                          bare word list ($lang used)
     *
     * When $path is given the resulting dictionary is bound to that file (and,
     * if the file already exists, its words are merged in first), so subsequent
     * {@see addWord()} calls persist. Pass null for a purely in-memory instance
     * (e.g. when the source of truth is a database column).
     *
     * A null, empty, or whitespace-only $json (and JSON `null`) yields an empty
     * dictionary, so an uninitialised database column round-trips cleanly.
     *
     * @throws \JsonException           if $json is not valid JSON.
     * @throws \InvalidArgumentException if the decoded value is not a list of
     *                                   words or a {lang, words} object.
     */
    public static function fromJson(
        ?string $json,
        ?string $path = null,
        string $lang = 'en',
    ): self {
        if ($json === null || trim($json) === '') {
            return new self($path, $lang);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if ($data === null) {
            return new self($path, $lang);
        }

        if (is_array($data) && array_is_list($data)) {
            $words = $data;
        } elseif (is_array($data)) {
            $words = $data['words'] ?? [];
            $lang = (string) ($data['lang'] ?? $lang);
        } else {
            throw new \InvalidArgumentException(
                'Custom dictionary JSON must be a word list or a {lang, words} object.'
            );
        }

        if (!is_array($words)) {
            throw new \InvalidArgumentException('Custom dictionary "words" must be an array.');
        }

        $dict = new self($path, $lang);
        foreach ($words as $word) {
            if (is_string($word)) {
                $dict->addWord($word);
            }
        }

        return $dict;
    }

    private function loadFile(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $i => $line) {
            // The first line is the "personal_ws-..." header, not a word.
            if ($i === 0 && str_starts_with($line, 'personal_ws')) {
                continue;
            }
            $word = trim($line);
            if ($word === '' || str_starts_with($word, '#')) {
                continue;
            }
            $this->words[mb_strtolower($word, 'UTF-8')] = $word;
        }
    }

    /**
     * Adds a word to the dictionary and, if a path is bound, persists the whole
     * list back to disk. Returns false when the word was already present.
     *
     * @throws \RuntimeException if the backing file cannot be written.
     */
    public function addWord(string $word): bool
    {
        $word = trim($word);
        if ($word === '') {
            return false;
        }

        $key = mb_strtolower($word, 'UTF-8');
        if (isset($this->words[$key])) {
            return false;
        }

        $this->words[$key] = $word;

        if ($this->path !== null) {
            $this->save();
        }

        return true;
    }

    /**
     * Rewrites the backing file with the current word list. The word count in
     * the header is kept accurate, matching Aspell's own save behaviour.
     */
    private function save(): void
    {
        if ($this->path === null) {
            return;
        }

        $words = array_values($this->words);
        $header = sprintf('personal_ws-1.1 %s %d utf-8', $this->lang, count($words));
        $contents = $header . "\n" . implode("\n", $words) . ($words === [] ? '' : "\n");

        if (file_put_contents($this->path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write custom dictionary: {$this->path}");
        }
    }

    public function lookup(string $word): bool
    {
        return isset($this->words[mb_strtolower($word, 'UTF-8')]);
    }

    public function soundslikeLookup(string $soundslike): array
    {
        // No phonetic index; suggestions fall back to the edit-distance scan
        // over getWords(), same as compiled .cwl word lists.
        return [];
    }

    /**
     * @return string[] Original spellings of every stored word.
     */
    public function getWords(): array
    {
        return array_values($this->words);
    }

    /**
     * Serializes the dictionary to a JSON string suitable for a database column
     * or any other text store. The language token is retained so
     * {@see fromJson()} can restore an identical instance. Multibyte characters
     * are emitted as-is (UTF-8), not \uXXXX escapes.
     */
    public function toJson(): string
    {
        return json_encode(
            ['lang' => $this->lang, 'words' => array_values($this->words)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}

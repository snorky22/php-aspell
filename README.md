# PHP-Aspell

A pure PHP 8.4 port of GNU Aspell.

## Goal
To provide a faceless, high-performance spelling engine that can be easily integrated into PHP applications (e.g., Symfony controllers) without external binary dependencies.

## Features
- **Pure PHP implementation** (Targeting PHP 8.4+).
- **Multi-byte Support**: Full UTF-8 support for diverse languages including Arabic, Russian, German, Hebrew and French. Handles legacy encodings (ISO-8859-1, ISO-8859-8, KOI8-R, CP1256, etc.) by converting to UTF-8 internally.
- **Phonetic Engine**: Full implementation of the Aspell "Phonet" algorithm for soundslike transformations, now multi-byte aware.
- **Dictionary Support**:
    - **Binary Parser**: Supports standard `.aspell` and `.rws` binary files.
    - **Compressed Support**: Built-in decompression for `.cwl` (prezip) format.
    - **Multi-file Support**: Recursively parses `.multi` files for combining multiple word lists.
    - **Affix Compression**: Recognizes inflected forms of affix-compressed dictionaries (German, Hebrew, Russian, Arabic) by applying the prefix/suffix rules in `<lang>_affix.dat` at lookup time — so `schöner`, `Häuser` or Hebrew clitic-prefixed forms are accepted without expanding the whole language into memory.
    - **Auto-discovery & Manifest**: Discovers the bundled dictionaries by their canonical `<xx>.multi` entry point and persists the resulting language → label → path table to a JSON manifest that is restored at runtime.
    - **Phonetic Rules**: Automatically loads language-specific phonetic rules from `_phonet.dat` files.
    - **Custom Dictionaries**: Writable personal word lists, persistable to GNU Aspell's `personal_ws-1.1` file format or serialized to/from a JSON string for database storage.
- **Suggestion Engine**: Integrated a weighted Damerau-Levenshtein edit distance algorithm for ranking spelling suggestions.
- **LaTeX Filtering**: Advanced state-machine-based filter for LaTeX documents (ported from GNU Aspell's `tex.cpp`).
- **Modern PHP 8.4 Features**: Utilizes property hooks, readonly classes, and asymmetric visibility for performance and safety.

## Installation
```bash
composer require php-aspell/php-aspell
```

## Usage

### Speller Engine (High-Level API)
The `Speller` class is the primary entry point for spell checking documents. It supports specialized modes like `latex`, powered by a robust state-machine port of the official GNU Aspell LaTeX filter.

#### Example: Setting up the Speller with the English Dictionary

```php
use Aspell\Config\AspellConfig;
use Aspell\Engine\Speller;

$config = new AspellConfig();
$speller = new Speller($config);

// Load a dictionary (can be .multi, .rws, or .cwl)
// It will automatically look for phonetic rules (en_phonet.dat) in the same directory.
$speller->loadDictionary('path/to/dictionaries/en.multi');

// Check a single word
if (!$speller->check('nait')) {
    $suggestions = $speller->suggest('nait');
    // Result: ['night', 'knight', ...]
}

// Check a LaTeX document
$latexContent = file_get_contents('paper.tex');
$misspelled = $speller->checkDocument($latexContent, 'latex');
```

#### Example: Discovering bundled dictionaries and persisting a manifest
Instead of hand-maintaining a table of which dictionaries ship with your
application, the `Speller` can **auto-discover** them and **persist** the result
as a small JSON manifest. Discovery scans a directory tree for each language's
canonical entry point — a file named exactly `<xx>.multi`, where the two letters
before `.multi` are trusted as the language code. Regional and variant multis
(`en_US.multi`, `en-variant_0.multi`, `fr_FR.multi`, …) are the alternatives that
the canonical `en.multi` / `fr.multi` already `add`s internally, so they are not
listed as separate languages.

```php
use Aspell\Engine\Speller;

// Discover the dictionaries under a root (defaults to the bundled ./dictionaries).
$dictionaries = Speller::discoverDictionaries('path/to/dictionaries');
// => [
//   'de' => ['label' => 'German — Deutsch',      'path' => '/abs/…/de.multi'],
//   'en' => ['label' => 'English',               'path' => '/abs/…/en.multi'],
//   'he' => ['label' => 'Hebrew — עברית',        'path' => '/abs/…/he.multi'],
//   … ordered by code, with absolute paths ready for loadDictionary()
// ]
```

Rather than run discovery on every request, generate the manifest once (e.g. at
build/deploy time) and load it at runtime:

```php
// --- Build time: write the manifest --------------------------------------
// Paths are stored RELATIVE to the root, so the file stays valid wherever the
// package is installed. Returns the discovered manifest for convenience.
Speller::saveDictionaryManifest('path/to/dictionaries/dictionaries.json', 'path/to/dictionaries');

// --- Runtime: restore the $DICTIONARIES table ----------------------------
// The source may be a path to the JSON file OR the JSON string itself; relative
// paths are resolved back to absolute against the given root.
$dictionaries = Speller::loadDictionaryManifest(
    'path/to/dictionaries/dictionaries.json',
    'path/to/dictionaries'
);

$speller->loadDictionary($dictionaries['de']['path']);
```

Both `discoverDictionaries()` and `loadDictionaryManifest()` return the same
shape — `['<code>' => ['label' => string, 'path' => string], …]` — so callers
can treat them interchangeably (discover live when no manifest exists, load the
manifest otherwise). `Speller::defaultDictionaryRoot()` returns the bundled
`dictionaries/` directory, which is the default root when the argument is
omitted. Labels come from a built-in table of common languages (falling back to
the uppercased code for unknown ones).

A ready-made CLI script regenerates the manifest — run it after adding or
removing a dictionary:

```bash
php bin/build-dictionaries.php
# or with an explicit root / output path:
php bin/build-dictionaries.php path/to/dictionaries path/to/out.json
```

```json
{
    "dictionaries": {
        "de": { "label": "German — Deutsch",      "path": "aspell6-de-20161207-7-0/de.multi" },
        "en": { "label": "English",               "path": "aspell6-en-2026.02.25-0/en.multi" },
        "he": { "label": "Hebrew — עברית",         "path": "aspell6-he-1.0-0/he.multi" }
    }
}
```

`public/index.php` uses exactly this flow: it restores `$DICTIONARIES` from
`dictionaries/dictionaries.json` when present, and falls back to live discovery
otherwise — so the web UI's language selector is populated automatically.

#### Example: Custom (personal) dictionary
You can load a writable custom dictionary that is checked *in addition to* the
language dictionary, and add words to it at runtime. When backed by a file, the
words are persisted in GNU Aspell's plain-text personal word list format
(`personal_ws-1.1 <lang> <count> utf-8`, one word per line) so they survive
across requests.

```php
$speller->loadDictionary('path/to/dictionaries/en.multi');

// Bind a persistent custom dictionary (created if it does not yet exist).
$speller->loadCustomDictionary('path/to/user.pws');

$speller->check('Kubernetes');   // false
$speller->addWord('Kubernetes'); // true (added + written to user.pws)
$speller->check('Kubernetes');   // true — now accepted alongside the language dict

// addWord() also works without a bound file (in-memory only, not persisted):
$speller->addWord('Symfony');
```

`addWord()` returns `false` if the word was already known to the custom
dictionary. Matching is case-insensitive, and added words also feed the
suggestion engine.

#### Example: Serializing a custom dictionary to a string (e.g. a database)
When you would rather store the personal word list in a database (or any other
text store) than in a `.pws` file, the custom dictionary can be serialized to and
from a JSON string. The JSON is UTF-8 and human-readable — multibyte words such
as `café` are kept literal, not `\uXXXX`-escaped — so it drops straight into a
`TEXT`/`LONGTEXT` (`utf8mb4`) column:

```php
// --- Restore before a spelling session -------------------------------------
$json = $row['dictionary']; // JSON string read from your DB (may be empty)
if ($json !== '') {
    $speller->setCustomDictionaryFromJson($json);
}

// ... run the session; addWord() etc. work exactly as above ...
$speller->addWord('Symfony');
$speller->addWord('café');

// --- Persist after the session ---------------------------------------------
$row['dictionary'] = $speller->getCustomDictionaryAsJson();
// e.g. {"lang":"fr","words":["Symfony","café"]}
// UPDATE ... SET dictionary = :dictionary
```

A dictionary set from JSON is **in-memory only** — nothing is written to disk, so
the database stays the single source of truth (and there is no per-`addWord()`
file rewrite during the session). `getCustomDictionaryAsJson()` always returns
valid JSON, even when no custom dictionary is configured (an empty `words` list).

The same round-trip is available on the dictionary itself via
`CustomDictionary::toJson()` and `CustomDictionary::fromJson()`. `fromJson()`
accepts either the full `{"lang":…, "words":[…]}` object or a bare
`["word", …]` array, and takes an optional path to bind the result to a file:

```php
use Aspell\Dictionary\CustomDictionary;

$dict = CustomDictionary::fromJson($json);          // in-memory
$dict = CustomDictionary::fromJson($json, $path);   // also persisted to $path
$json = $dict->toJson();
```

#### Example: Finding and correcting misspellings with `misspellingRegex()`
`checkDocument()` tells you *which* words are misspelled; `misspellingRegex()`
turns that word list into a single compiled PCRE pattern that matches every
whole-word occurrence of those words in the text. With it you can count
occurrences, highlight them, or replace them — the same pattern drives
plain-text correction and HTML highlighting alike.

The example below builds a corrector in clearly labelled steps: identify the
misspelled words, build the pattern, choose a suggestion per word, then replace
every occurrence while preserving its capitalisation. Because matching is
whole-word, correction never needs character offsets — the identical approach
works in PHP and in client-side JavaScript.

First, a small helper that transfers the capitalisation of the word found in
the text onto the (lower-cased) suggestion, so `Teh` becomes `The` and `TEH`
becomes `THE`:

```php
/**
 * Transfer the capitalisation of $model (the word as it appeared in the text)
 * onto $replacement: ALL CAPS, Titlecase, or left as-is.
 */
function matchCase(string $model, string $replacement): string
{
    // "WORD" -> replacement entirely in upper case.
    if (mb_strtoupper($model, 'UTF-8') === $model && mb_strtolower($model, 'UTF-8') !== $model) {
        return mb_strtoupper($replacement, 'UTF-8');
    }

    // "Word" -> capitalise only the first letter of the replacement.
    $firstChar = mb_substr($model, 0, 1, 'UTF-8');
    if (mb_strtoupper($firstChar, 'UTF-8') === $firstChar && mb_strtolower($firstChar, 'UTF-8') !== $firstChar) {
        $head = mb_strtoupper(mb_substr($replacement, 0, 1, 'UTF-8'), 'UTF-8');
        $tail = mb_substr($replacement, 1, null, 'UTF-8');
        return $head . $tail;
    }

    // "word" -> leave the replacement untouched.
    return $replacement;
}
```

Now the corrector itself:

```php
use Aspell\Engine\Speller;

/**
 * Replace every misspelled word in $text with its top suggestion, preserving
 * each occurrence's capitalisation. Returns the corrected text.
 */
function correctText(Speller $speller, string $text): string
{
    // --- Step 1: identify the unique misspelled words --------------------
    // checkDocument() returns one entry per occurrence; reduce to the set.
    $found = $speller->checkDocument($text);
    $words = array_values(array_unique(array_column($found, 'word')));

    if ($words === []) {
        return $text; // nothing to correct
    }

    // --- Step 2: build one whole-word pattern for all those words ---------
    $rx = $speller->misspellingRegex($words);

    // --- Step 3: choose the best suggestion for each word ----------------
    // Key by the lower-cased word so any capitalisation ("sentance",
    // "Sentance", "SENTANCE") resolves to the same suggestion in Step 4.
    $best = [];
    foreach ($words as $word) {
        $suggestions = $speller->suggest($word);
        if ($suggestions !== []) {
            $best[mb_strtolower($word, 'UTF-8')] = $suggestions[0];
        }
    }

    // --- Step 4: replace every occurrence, preserving its capitalisation -
    $callback = function (array $match) use ($best): string {
        $original = $match[1];
        $key      = mb_strtolower($original, 'UTF-8');

        // No suggestion for this word: leave it untouched.
        if (!isset($best[$key])) {
            return $original;
        }

        // Transfer the original word's capitalisation onto the suggestion.
        return matchCase($original, $best[$key]);
    };

    return preg_replace_callback($rx, $callback, $text) ?? $text;
}

// Correct the whole document — the case of each word is preserved:
echo correctText($speller, 'Sentance has a fwe MISSPELED words.');
// => 'Sentence has a few MISSPELLED words.'
```

Counting is just as direct — `preg_match_all()` returns the number of matches,
so you never need the match positions:

```php
$rx    = $speller->misspellingRegex($words);
$total = preg_match_all($rx, $text); // total misspelled-word occurrences
```

The pattern matches **whole words only** and treats apostrophes as
word-internal — consistent with `checkDocument()`'s tokenizer — so contractions
such as `don't` are matched as a unit rather than as `don`. It is built with the
case-insensitive (`i`) and Unicode (`u`) flags, so matches are found regardless
of capitalisation and across scripts. Each candidate word is passed through
`preg_quote()`, so words containing regex metacharacters are matched literally.
`public/index.php` uses this method to build both its corrected-text output and
its highlighted HTML preview from a single pattern.

Because the match is whole-word, replacing a suggestion needs no character
positions, so the same approach runs unchanged in the browser — the web UI
applies a suggestion to every occurrence client-side with an equivalent regex:

```js
const rx = new RegExp("(?<![\\p{L}'])" + escapeRegex(word) + "(?![\\p{L}'])", 'giu');
text = text.replace(rx, (match) => matchCase(match, suggestion));
```

If you genuinely need match *positions* (for navigation or per-occurrence
handling), note that PCRE reports **byte** offsets even under the `/u` flag:
`preg_match_all(..., PREG_OFFSET_CAPTURE)` yields byte offsets, and so does
mbstring's `mb_ereg_search_pos()`. Convert to character offsets with
`mb_strlen(substr($text, 0, $byteOffset), 'UTF-8')`.

#### Verification on PINN_FINAL.tex
The library has been verified against large scientific LaTeX documents (e.g., `PINN_FINAL.tex`). The `TexFilter` correctly:
- Skips LaTeX commands and their ignored parameters (e.g., `\cite{...}`, `\usepackage{...}`).
- Checks parameters of text-heavy commands (e.g., `\section{...}`, `\textcolor{red}{...}`).
- Handles escaped characters (e.g., `\%`) and nested environments.
- Ignores LaTeX comments starting with `%`.

### Phonetic Transformation
The `PhoneticTransformer` converts words into their phonetic representation based on language rules.

```php
use Aspell\Engine\PhoneticTransformer;

$rules = [
    ['PH', 'F'],
    ['SH', 'S'],
];

// Map of ASCII codes to cleaned characters (e.g. for normalization)
$toClean = []; 
for ($i = 0; $i < 256; $i++) { $toClean[$i] = strtoupper(chr($i)); }

$transformer = new PhoneticTransformer($rules, ['followup' => true], $toClean);
echo $transformer->transform('photograph'); // FOTOGRAF
```

### Binary Dictionary Access
The `AspellBinaryParser` allows direct lookup in compiled `.aspell` files.

```php
use Aspell\Dictionary\AspellBinaryParser;

$parser = new AspellBinaryParser('path/to/english.aspell', $toClean);
if ($parser->lookup('hello')) {
    echo "Word found!";
}
```

### Suggestion Engine
The `SuggestionEngine` calculates weighted edit distance to rank spelling suggestions.

```php
use Aspell\Engine\SuggestionEngine;
use Aspell\Engine\EditDistanceWeights;

$engine = new SuggestionEngine();
$weights = new EditDistanceWeights(del1: 1, del2: 1, swap: 1, sub: 1);
$distance = $engine->editDistance('nait', 'night', $weights);
```

### Configuration
The `AspellConfig` class handles all speller settings.

```php
use Aspell\Config\AspellConfig;

$config = new AspellConfig();
$config->replace('lang', 'fr');
echo $config->retrieve('lang'); // fr
```

## Web demo
A minimal browser UI for trying the checker interactively lives in `public/index.php`.
It provides a text area for LaTeX/plain input, a dictionary selector populated
automatically from the discovered dictionaries (currently English, French,
German, Hebrew, Russian and Arabic), a corrected-text box with a copy button, a
highlighted preview, and per-word suggestions (click a suggestion to apply it).

### Running the server
Start PHP's built-in web server from the project root (the `php-aspell` directory),
then open the printed URL in your browser:

```bash
cd php-aspell
php -S 127.0.0.1:8080 public/index.php
# then open http://127.0.0.1:8080/
```

Notes:
- **Keep the terminal open** — the server runs in the foreground. Press `Ctrl+C` to stop it.
- **Run it from the `php-aspell` directory** so it can find `vendor/` and `dictionaries/`
  (both must be present; run `composer install` first if `vendor/` is missing).
- **Port already in use?** Pick another one, e.g. `php -S 127.0.0.1:8137 public/index.php`.
- **First check load time**: the chosen dictionary is loaded into memory on each request.
  French/English/German/Russian are fast (~0.2–0.8 s); Hebrew is quick too despite its
  size because inflected forms are matched on demand rather than expanded; Arabic
  (≈ 1M words) takes a few seconds.

The page renders on `GET`; submitting posts the text as JSON and runs the checker
server-side.

## Roadmap
- [x] Phase I: Core Models & Configuration
- [x] Phase II: Phonetic Engine
- [x] Phase III: Dictionary Parser (Multi-file & Lookups)
- [x] Phase IV: Suggestion Engine (Weighted Levenshtein)
- [x] Phase V: Packagist Release

## Testing
To run the test suite:
```bash
vendor/bin/phpunit tests/
```

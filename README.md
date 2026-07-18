# PHP-Aspell

A pure PHP 8.4 port of GNU Aspell.

## Goal
To provide a faceless, high-performance spelling engine that can be easily integrated into PHP applications (e.g., Symfony controllers) without external binary dependencies.

## Features
- **Pure PHP implementation** (Targeting PHP 8.4+).
- **Multi-byte Support**: Full UTF-8 support for diverse languages including Arabic, Russian, and French. Handles legacy encodings (ISO-8859-1, KOI8-R, CP1256, etc.) by converting to UTF-8 internally.
- **Phonetic Engine**: Full implementation of the Aspell "Phonet" algorithm for soundslike transformations, now multi-byte aware.
- **Dictionary Support**:
    - **Binary Parser**: Supports standard `.aspell` and `.rws` binary files.
    - **Compressed Support**: Built-in decompression for `.cwl` (prezip) format.
    - **Multi-file Support**: Recursively parses `.multi` files for combining multiple word lists.
    - **Phonetic Rules**: Automatically loads language-specific phonetic rules from `_phonet.dat` files.
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
It provides a text area for LaTeX/plain input, a dictionary selector (English,
French, Russian, Arabic), a corrected-text box with a copy button, a highlighted
preview, and per-word suggestions (click a suggestion to apply it).

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
  French/English/Russian are fast (~0.2–0.6 s); Arabic (≈ 1M words) takes a few seconds.

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

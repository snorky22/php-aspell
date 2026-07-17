# PHP-Aspell

A pure PHP 8.4 port of GNU Aspell.

## Goal
To provide a faceless, high-performance spelling engine that can be easily integrated into PHP applications (e.g., Symfony controllers) without external binary dependencies.

## Features
- **Pure PHP implementation** (Targeting PHP 8.4+).
- **Phonetic Engine**: Full implementation of the Aspell "Phonet" algorithm for soundslike transformations.
- **Dictionary Parsing**: Support for reading GNU Aspell's optimized binary dictionary files (`.aspell`).
- **Modern PHP 8.4 Features**: Utilizes property hooks, readonly classes, and asymmetric visibility for performance and safety.

## Installation
```bash
composer require php-aspell/php-aspell
```

## Usage

### Speller Engine (High-Level API)
The `Speller` class is the primary entry point for spell checking documents. It supports specialized modes like `latex`.

```php
use Aspell\Config\AspellConfig;
use Aspell\Engine\Speller;

$config = new AspellConfig();
$speller = new Speller($config);
$speller->loadDictionary('path/to/english.aspell');

// Check a LaTeX document
$misspelled = $speller->checkDocument($latexContent, 'latex');

foreach ($misspelled as $word => $suggestions) {
    echo "Misspelled: $word\n";
}
```

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

<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Aspell\Config\AspellConfig;
use Aspell\Engine\Speller;

$config = new AspellConfig();
$speller = new Speller($config);

// Load French dictionary
$dictPath = __DIR__ . '/dictionaries/aspell-fr-0.50-3/fr.multi';
echo "Loading French dictionary: $dictPath\n";
try {
    $speller->loadDictionary($dictPath);
    echo "Dictionaries loaded: " . count($speller->getLoadedWords()) . " unique words.\n";
    if (count($speller->getLoadedWords()) > 0) {
        $words = $speller->getLoadedWords();
        echo "Sample words: " . implode(', ', array_slice($words, 0, 20)) . "\n";
        $targets = ['ainsi', 'algorithme', 'optimisation', 'fonction'];
        foreach ($targets as $target) {
            $foundInList = false;
            foreach ($words as $w) {
                if ($w === $target) { $foundInList = true; break; }
            }
            echo "Is '$target' in word list? " . ($foundInList ? "YES" : "NO") . "\n";
        }
    }
} catch (\Exception $e) {
    echo "Error loading dictionary: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Check 'Ainsi': " . ($speller->check('Ainsi') ? "OK" : "FAILED") . "\n";
echo "Check 'ainsi': " . ($speller->check('ainsi') ? "OK" : "FAILED") . "\n";
echo "Check 'Algorithme': " . ($speller->check('Algorithme') ? "OK" : "FAILED") . "\n";
echo "Check 'algorithme': " . ($speller->check('algorithme') ? "OK" : "FAILED") . "\n";
echo "Check 'Été': " . ($speller->check('Été') ? "OK" : "FAILED") . "\n";
echo "Check 'été': " . ($speller->check('été') ? "OK" : "FAILED") . "\n";

$inputFile = __DIR__ . '/test-files/Cours d\'optimisation.tex';
if (!file_exists($inputFile)) {
    echo "Input file not found: $inputFile\n";
    exit(1);
}

echo "Spell checking file: $inputFile\n";
$content = file_get_contents($inputFile);

// Use LaTeX mode
$misspellings = $speller->checkDocument($content, 'tex');

echo "Found " . count($misspellings) . " potential misspellings.\n";

// Group by word to avoid long output
$uniqueMisspellings = [];
foreach ($misspellings as $m) {
    $word = $m['word'];
    if (!isset($uniqueMisspellings[$word])) {
        $uniqueMisspellings[$word] = 0;
    }
    $uniqueMisspellings[$word]++;
}

ksort($uniqueMisspellings);

echo "\nUnique misspellings (top 50):\n";
$count = 0;
foreach ($uniqueMisspellings as $word => $occ) {
    echo sprintf("- %-20s (%d occurrences)\n", $word, $occ);
    $count++;
    if ($count >= 50) break;
}

if (count($uniqueMisspellings) > 50) {
    echo "... and " . (count($uniqueMisspellings) - 50) . " more.\n";
}

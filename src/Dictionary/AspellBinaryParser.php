<?php

declare(strict_types=1);

namespace Aspell\Dictionary;

/**
 * Parser for GNU Aspell binary dictionary files (.aspell).
 */
class AspellBinaryParser implements WordListInterface
{
    private string $filename;
    private $handle;
    
    // Header info
    private array $header = [];
    private string $dictName = '';
    private string $langName = '';
    
    // Block pointers
    private int $jump1Offset;
    private int $jump2Offset;
    private int $wordOffset;
    private int $hashOffset;
    
    /** @var array<int, int> Hash table buckets */
    private array $buckets = [];

    /** @var array<int, int> translation table for clean hash */
    private array $toClean = [];

    public function __construct(string $filename, array $toClean = [])
    {
        $this->filename = $filename;
        $this->toClean = $toClean;
        $this->load();
    }

    private function load(): void
    {
        $this->handle = fopen($this->filename, 'rb');
        if (!$this->handle) {
            throw new \RuntimeException("Could not open dictionary file: {$this->filename}");
        }

        // Read DataHead
        $data = fread($this->handle, 112); // DataHead size is roughly this, need to be careful with alignment
        $head = unpack('a64check_word/Vendian_check/a16lang_hash/Vhead_size/Vblock_size/Vjump1_offset/Vjump2_offset/Vword_offset/Vhash_offset/Vword_count/Vword_buckets/Vsoundslike_count', $data);
        
        if ($head['check_word'] !== "aspell default speller rowl 1.10\0") {
            // Check word might be slightly different depending on version
            if (strncmp($head['check_word'], "aspell default speller rowl", 27) !== 0) {
                 throw new \RuntimeException("Invalid dictionary format: check word mismatch");
            }
        }

        if ($head['endian_check'] !== 12345678) {
            throw new \RuntimeException("Unsupported endianness or invalid dictionary file");
        }

        $this->header = $head;
        
        // Read remaining header info (names)
        $namesData = fread($this->handle, 16); // Reading enough to get some names
        // Names are variable length and followed after DataHead in some versions
        // Actually phonet.cpp reads them specifically.
        
        $this->jump1Offset = $head['head_size'] + $head['jump1_offset'];
        $this->jump2Offset = $head['head_size'] + $head['jump2_offset'];
        $this->wordOffset = $head['head_size'] + $head['word_offset'];
        $this->hashOffset = $head['head_size'] + $head['hash_offset'];

        // Load hash table buckets
        fseek($this->handle, $this->hashOffset);
        $bucketsData = fread($this->handle, $head['word_buckets'] * 4);
        $this->buckets = array_values(unpack('V*', $bucketsData));
    }

    public function lookup(string $word): bool
    {
        $hash = $this->calculateHash($word);
        $bucketCount = count($this->buckets);
        if ($bucketCount === 0) {
            return false;
        }
        
        $idx = $hash % $bucketCount;
        $wordPos = $this->buckets[$idx];
        
        if ($wordPos === 0xFFFFFFFF) {
            return false;
        }
        
        fseek($this->handle, $this->wordOffset + $wordPos);
        
        while (true) {
            $currentEntryPos = ftell($this->handle);
            $wordInfo = $this->readWordEntry($nextOffset);
            if ($this->isMatch($word, $wordInfo->word)) {
                return true;
            }
            
            if (!($wordInfo->wordInfo & 0x10)) { // DUPLICATE_FLAG
                break;
            }
            
            fseek($this->handle, $currentEntryPos + $nextOffset);
        }
        
        return false;
    }

    private function calculateHash(string $word): int
    {
        $h = 0;
        $len = strlen($word);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($word[$i]);
            $clean = $this->toClean[$c] ?? $c;
            if ($clean) {
                $h = (5 * $h + $clean) & 0xFFFFFFFF;
            }
        }
        return $h;
    }

    private function isMatch(string $word, string $other): bool
    {
        // Simple match for now, Aspell has complex case-insensitive comparison
        return strtolower($word) === strtolower($other);
    }

    private function readWordEntry(&$nextOffset): WordEntry
    {
        // Aspell words are stored as:
        // (<flags><offset to next word><word size><word><null>)
        // Depending on flags, it might have affix or category info
        
        $currentPos = ftell($this->handle);
        
        // Read the 3 bytes before the word
        // (frequency is also there in some versions, but let's stick to the 3 bytes offset)
        fseek($this->handle, -3, SEEK_CUR);
        $meta = fread($this->handle, 3);
        $flags = ord($meta[0]);
        $nextOffset = ord($meta[1]);
        $wordSize = ord($meta[2]);
        
        fseek($this->handle, $currentPos);
        $word = fread($this->handle, $wordSize);
        
        // Skip null terminator
        fseek($this->handle, 1, SEEK_CUR);
        
        $affix = null;
        if ($flags & 0x80) { // HAVE_AFFIX_FLAG
            $affix = $this->readNullTerminatedString();
        }
        
        // We'll need to handle category info too if needed
        
        return new WordEntry($word, $flags, $affix);
    }

    private function readNullTerminatedString(): string
    {
        $str = '';
        while (true) {
            $char = fread($this->handle, 1);
            if ($char === "\0" || $char === false) {
                break;
            }
            $str .= $char;
        }
        return $str;
    }

    public function soundslikeLookup(string $soundslike): array
    {
        if ($this->header['soundslike_count'] === 0) {
            return [];
        }

        $hash = $this->calculateHash($soundslike);
        // Soundslike hash table usually comes after the word hash table
        // But in some versions they are interleaved or separate.
        // Assuming standard rowl format where they might be separate.
        
        // This is a placeholder for actual jump table lookup logic
        // which requires knowing the exact layout of the soundslike blocks.
        return [];
    }

    /**
     * Parses a .multi file which points to multiple other dictionary files.
     */
    public static function parseMultiFile(string $filename): array
    {
        $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $files = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            
            if (str_starts_with($line, 'add ')) {
                $files[] = substr($line, 4);
            }
        }
        return $files;
    }

    public function __destruct()
    {
        if ($this->handle) {
            fclose($this->handle);
        }
    }
}

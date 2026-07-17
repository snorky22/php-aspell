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

    private string $charset = 'utf-8';

    public function __construct(string $filename, array $toClean = [], string $charset = 'utf-8')
    {
        $this->filename = $filename;
        $this->toClean = $toClean;
        $this->charset = strtolower($charset);
        $this->load();
    }

    private function load(): void
    {
        $this->handle = fopen($this->filename, 'rb');
        if (!$this->handle) {
            throw new \RuntimeException("Could not open dictionary file: {$this->filename}");
        }

        // Check if it's a .cwl (compressed word list)
        $firstByte = fread($this->handle, 1);
        if ($firstByte === "\x01" || $firstByte === "\x02") {
            // It's a CWL (prezip format). \x01 is older, \x02 is newer.
            $this->loadCwl();
            return;
        }
        fseek($this->handle, 0);

        // Read DataHead
        $data = fread($this->handle, 120);
        if ($data === false || strlen($data) < 120) {
            throw new \RuntimeException("Not a valid Aspell dictionary (too short): {$this->filename}");
        }
        $head = unpack('a64check_word/Vendian_check/a16lang_hash/Vhead_size/Vblock_size/Vjump1_offset/Vjump2_offset/Vword_offset/Vhash_offset/Vword_count/Vword_buckets/Vsoundslike_count', $data);
        if ($head === false) {
            throw new \RuntimeException("Could not parse dictionary header: {$this->filename}");
        }

        if ($head['check_word'] !== "aspell default speller rowl 1.10\0") {
            // Check word might be slightly different depending on version
            if (strncmp($head['check_word'], "aspell default speller rowl", 27) !== 0) {
                 throw new \RuntimeException("Invalid dictionary format: check word mismatch in {$this->filename}");
            }
        }

        if ($head['endian_check'] !== 12345678) {
            throw new \RuntimeException("Unsupported endianness or invalid dictionary file: {$this->filename}");
        }

        $this->header = $head;
        
        // Read remaining header info (names)
        $namesData = fread($this->handle, 16); // Reading enough to get some names
        
        $this->jump1Offset = $head['head_size'] + $head['jump1_offset'];
        $this->jump2Offset = $head['head_size'] + $head['jump2_offset'];
        $this->wordOffset = $head['head_size'] + $head['word_offset'];
        $this->hashOffset = $head['head_size'] + $head['hash_offset'];

        // Load hash table buckets
        fseek($this->handle, $this->hashOffset);
        $bucketsData = fread($this->handle, $head['word_buckets'] * 4);
        $this->buckets = array_values(unpack('V*', $bucketsData));
    }

    private array $words = [];

    /**
     * Loads a compressed word list (.cwl), produced by GNU Aspell's `prezip`.
     *
     * The format is a prefix-delta ("front coding") stream documented in
     * prog/prezip.c. Two versions exist, distinguished by the first byte:
     *   0x01 - legacy format, no escaping.
     *   0x02 - current format, with 0x1F escapes and a 0x1F 0xFF terminator.
     *
     * The whole file is read into memory and decoded with byte-string indexing
     * (fread-per-byte was both wrong and quadratic on multi-MB dictionaries).
     */
    private function loadCwl(): void
    {
        $this->words = [];

        $data = file_get_contents($this->filename);
        if ($data === false || $data === '') {
            return;
        }

        $len = strlen($data);
        $needsConvert = ($this->charset !== 'utf-8' && $this->charset !== 'utf8');

        $version = ord($data[0]);
        if ($version === 1) {
            $this->decodeCwlV1($data, $len, $needsConvert);
        } elseif ($version === 2) {
            $this->decodeCwlV2($data, $len, $needsConvert);
        } else {
            throw new \RuntimeException(
                "Unknown CWL format byte 0x" . dechex($version) . " in {$this->filename}"
            );
        }
    }

    /**
     * Legacy prezip format (leading 0x01).
     *
     * Each entry is: <keep-marker> <rest-bytes>*. The keep marker is a byte in
     * 1..32 encoding "keep length + 1"; a marker of 0 escapes a following byte
     * so keep lengths above 31 can be expressed. Rest bytes are any byte > 32
     * and are appended verbatim; the byte that ends the rest run is the next
     * entry's keep marker. There is no explicit terminator.
     */
    private function decodeCwlV1(string $data, int $len, bool $needsConvert): void
    {
        $prev = '';
        $lastMax = 0;
        $pos = 0;
        $c = ord($data[$pos++]); // the 0x01 marker doubles as the first keep byte (keep = 0)

        while (true) {
            if ($c === 0) {
                if ($pos >= $len) {
                    break;
                }
                $c = ord($data[$pos++]);
            }

            $keep = $c - 1;
            if ($keep < 0 || $keep > $lastMax) {
                // Unexpected keep length: bail rather than emit corrupt words.
                break;
            }

            $word = substr($prev, 0, $keep);
            while ($pos < $len && ($b = ord($data[$pos])) > 32) {
                $word .= $data[$pos];
                $pos++;
            }

            $prev = $word;
            $lastMax = strlen($word);
            $this->store($word, $needsConvert);

            if ($pos >= $len) {
                break;
            }
            $c = ord($data[$pos++]); // next entry's keep marker
        }
    }

    /**
     * Current prezip format (leading 0x02).
     *
     * <data> ::= 0x02 <line>+ 0x1F 0xFF
     * <line> ::= <prefix> <rest>*
     * <prefix> ::= 0x00..0x1D | 0x1E 0xFF* 0x00..0xFE   (characters to keep)
     * <rest> ::= 0x20..0xFF | <escape>
     * <escape> ::= 0x1F 0x20..0x3F   (decodes to second byte minus 0x20)
     *
     * The retained prefix is measured on the *escaped* bytes, so `$buf` keeps
     * the escaped representation of the previous line and unescaping only
     * happens when materialising each word.
     */
    private function decodeCwlV2(string $data, int $len, bool $needsConvert): void
    {
        $buf = '';   // escaped bytes retained from the previous line
        $pos = 1;    // skip the 0x02 marker
        if ($pos >= $len) {
            return;
        }
        $prefix = ord($data[$pos++]);

        while (true) {
            $keep = $prefix;
            if ($keep === 30) { // 0x1E: long prefix follows
                while ($pos < $len && ord($data[$pos]) === 255) {
                    $keep += 255;
                    $pos++;
                }
                if ($pos < $len) {
                    $keep += ord($data[$pos]);
                    $pos++;
                }
            }

            $cur = substr($buf, 0, $keep);
            while ($pos < $len && ($b = ord($data[$pos])) > 30) {
                $cur .= $data[$pos];
                $pos++;
            }
            $buf = $cur;

            // Unescape into the actual word and detect the stream terminator.
            $word = '';
            $terminated = false;
            $curLen = strlen($cur);
            for ($i = 0; $i < $curLen; $i++) {
                $o = ord($cur[$i]);
                if ($o !== 31) {
                    $word .= $cur[$i];
                    continue;
                }
                if (++$i >= $curLen) {
                    break;
                }
                $e = ord($cur[$i]);
                if ($e >= 32 && $e < 64) {
                    $word .= chr($e - 32);
                } elseif ($e === 255) {
                    $terminated = true;
                    break;
                } else {
                    break; // corrupt escape
                }
            }

            if ($word !== '') {
                $this->store($word, $needsConvert);
            }

            if ($terminated || $pos >= $len) {
                break;
            }
            $prefix = ord($data[$pos++]); // next line's prefix byte
        }
    }

    /**
     * Normalises a decoded entry and stores it, keyed case-insensitively, in
     * the in-memory word set.
     *
     * Affix-compressed dictionaries (e.g. Russian, Arabic) store entries as
     * "stem/flags" (and a leading '*' marks a forbidden word). We keep only the
     * stem here; expanding the affix rules themselves is a separate concern.
     * The separators are ASCII, so this is done on the raw bytes before any
     * charset conversion.
     */
    private function store(string $word, bool $needsConvert): void
    {
        if (($slash = strpos($word, '/')) !== false) {
            $word = substr($word, 0, $slash);
        }
        if (isset($word[0]) && $word[0] === '*') {
            $word = substr($word, 1);
        }
        if ($word === '') {
            return;
        }

        if ($needsConvert) {
            $word = mb_convert_encoding($word, 'UTF-8', $this->charset);
        }
        $this->words[mb_strtolower($word, 'UTF-8')] = true;
    }

    public function lookup(string $word): bool
    {
        $originalWord = $word;
        if (!empty($this->words)) {
            return isset($this->words[mb_strtolower($word, 'UTF-8')]);
        }

        if ($this->charset !== 'utf-8' && $this->charset !== 'utf8') {
            $word = mb_convert_encoding($word, $this->charset, 'UTF-8');
        }

        // Try exact match first
        $hash = $this->calculateHash($word);
        if ($this->lookupWithHash($word, $hash, $originalWord)) {
            return true;
        }

        // Try uppercase/lowercase variants in hash calculation if needed? 
        // No, calculateHash uses toClean which should handle case if set up correctly.
        
        return false;
    }

    private function lookupWithHash(string $word, int $hash, string $originalWord): bool
    {
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
            
            $entryWord = $wordInfo->word;
            if ($this->charset !== 'utf-8' && $this->charset !== 'utf8') {
                $entryWord = mb_convert_encoding($entryWord, 'UTF-8', $this->charset);
            }

            if ($this->isMatch($originalWord, $entryWord)) {
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
            if (is_string($clean)) {
                $clean = ord($clean);
            }
            if ($clean) {
                $h = (5 * $h + $clean) & 0xFFFFFFFF;
            }
        }
        return $h;
    }

    private function isMatch(string $word, string $other): bool
    {
        return mb_strtolower($word, 'UTF-8') === mb_strtolower($other, 'UTF-8');
    }

    private function readWordEntry(&$nextOffset): WordEntry
    {
        // Aspell words in rowl format are stored as:
        // [1 byte word size including meta][1 byte flags][1 byte next offset][word string][null terminator]
        // This is a simplified version for our parser
        
        $sizeByte = fread($this->handle, 1);
        if ($sizeByte === false || $sizeByte === "") {
             return new WordEntry("", 0, null);
        }
        $totalSize = ord($sizeByte);
        
        $meta = fread($this->handle, 2);
        $flags = ord($meta[0]);
        $nextOffset = ord($meta[1]);
        
        $wordSize = $totalSize - 3; // totalSize includes the 3 meta bytes
        if ($wordSize < 0) $wordSize = 0;
        
        $word = $wordSize > 0 ? fread($this->handle, $wordSize) : "";
        
        // Skip null terminator
        fread($this->handle, 1);
        
        $affix = null;
        if ($flags & 0x80) { // HAVE_AFFIX_FLAG
            $affix = $this->readNullTerminatedString();
        }
        
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
        if (!empty($this->words)) {
            // For CWL, we don't have phonetic jump tables.
            // In a full implementation, we would have pre-calculated phonetics for all words.
            // For now, return empty or we could do a full scan (slow).
            return [];
        }

        if (($this->header['soundslike_count'] ?? 0) === 0) {
            return [];
        }

        // The rowl format uses jump tables for soundslike lookups.
        // Jump1 maps soundslike hashes to positions in Jump2.
        // Jump2 maps to word blocks.
        
        $hash = $this->calculateHash($soundslike);
        // This is a simplified implementation of the jump table traversal.
        // In rowl, jump1 and jump2 are used to narrow down the search in the word list.
        
        // For now, since we haven't fully implemented the complex jump table bit-packing,
        // we will do a scan or return empty.
        // Full implementation requires bit-level parsing of jump1 and jump2.
        
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

    public function getWords(): array
    {
        return array_keys($this->words);
    }
}

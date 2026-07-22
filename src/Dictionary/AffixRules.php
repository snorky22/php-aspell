<?php

declare(strict_types=1);

namespace Aspell\Dictionary;

/**
 * Parses a GNU Aspell / MySpell affix file (`<lang>_affix.dat`) and recognises
 * the inflected forms that an affix-compressed word list stands for.
 *
 * Affix-compressed dictionaries (those whose `.dat` sets `affix-compress true`,
 * e.g. German, Hebrew, Russian) do not store every inflected form. They store a
 * stem plus a set of one-character affix flags — "schön/AL", "achen/S" — and a
 * companion affix file describes, per flag, the prefixes and suffixes those
 * flags license. Without applying them a speller wrongly flags legitimate
 * inflections ("schöner", "das", …).
 *
 * Rather than *expand* every stem up front — infeasible for a language like
 * Hebrew, whose ~960 prefixes cross-producted with suffixes yield hundreds of
 * millions of forms — this class works the way Aspell itself does: it *strips*
 * affixes off the word being checked to recover candidate stems, and the caller
 * accepts the word if some candidate is a stored stem that actually carries the
 * matching flag. That is bounded by the number of rules per lookup, not by the
 * size of the language.
 *
 * File format (the subset produced by the bundled dictionaries):
 *
 *   SET ISO8859-1                         # source charset
 *   PFX <flag> <cross> <count>            # a prefix group header
 *   PFX <flag> <strip> <add> <condition>  # ...followed by <count> rules
 *   SFX <flag> <cross> <count>            # a suffix group header
 *   SFX <flag> <strip> <add> <condition>  # ...followed by <count> rules
 *
 *   - <cross> is Y or N: whether the affix may combine with one of the other
 *     kind (a prefix stacked onto a suffixed form).
 *   - <strip> characters removed from the stem edge ("0" = remove nothing).
 *   - <add>   characters glued on ("0" = add nothing). Any "/moreflags" tail is
 *     ignored — the bundled dictionaries do not use continuation classes.
 *   - <condition> a MySpell condition matched against the stem edge; a small
 *     regex dialect ("." = any char, "[...]"/"[^...]" classes, literals), used
 *     directly as a PCRE fragment.
 *
 * Rules are converted from the dictionary's native charset to lowercased UTF-8
 * at load, so all matching happens in the same space as the stored (lowercased,
 * UTF-8) stem set and can use Unicode-aware PCRE (/u).
 */
final class AffixRules
{
    /**
     * @var list<array{flag: string, cross: bool, strip: string, add: string, pattern: string}>
     *      prefix rules, flattened
     */
    private array $prefixRules = [];

    /**
     * @var list<array{flag: string, cross: bool, strip: string, add: string, pattern: string}>
     *      suffix rules, flattened
     */
    private array $suffixRules = [];

    /**
     * @param list<string> $lines   raw lines of the affix file (native charset)
     * @param string       $charset the dictionary's charset (mbstring name)
     */
    public function __construct(array $lines, string $charset = 'utf-8')
    {
        $this->parse($lines, strtolower($charset));
    }

    /**
     * Reads and parses an affix file. Returns null when the file cannot be read
     * so callers can silently fall back to the un-expanded word list.
     */
    public static function fromFile(string $path, string $charset = 'utf-8'): ?self
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        return new self($lines, $charset);
    }

    /** True when the file contained no usable affix rules. */
    public function isEmpty(): bool
    {
        return $this->prefixRules === [] && $this->suffixRules === [];
    }

    /**
     * @param list<string> $lines
     */
    private function parse(array $lines, string $charset): void
    {
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $type = $parts[0] ?? '';
            if ($type !== 'PFX' && $type !== 'SFX') {
                continue; // SET, TRY, REP, … are not needed for recognition.
            }

            // Group header: "PFX <flag> <Y|N> <count>". The <count> rule lines
            // that follow all repeat the same flag.
            if (isset($parts[3]) && ($parts[2] === 'Y' || $parts[2] === 'N')) {
                $flag  = $parts[1];
                $cross = $parts[2] === 'Y';
                $n = (int) $parts[3];

                for ($j = 0; $j < $n && $i + 1 < $count; $j++) {
                    $rule = $this->parseRule($lines[++$i], $type, $flag, $cross, $charset);
                    if ($rule === null) {
                        continue;
                    }
                    if ($type === 'PFX') {
                        $this->prefixRules[] = $rule;
                    } else {
                        $this->suffixRules[] = $rule;
                    }
                }
            }
        }
    }

    /**
     * @return array{flag: string, cross: bool, strip: string, add: string, pattern: string}|null
     */
    private function parseRule(string $line, string $type, string $flag, bool $cross, string $charset): ?array
    {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) < 5 || $parts[0] !== $type || $parts[1] !== $flag) {
            return null;
        }

        $strip = $parts[2] === '0' ? '' : $this->toUtf8Lower($parts[2], $charset);

        $add = $parts[3];
        if (($slash = strpos($add, '/')) !== false) { // drop continuation flags
            $add = substr($add, 0, $slash);
        }
        $add = $add === '0' ? '' : $this->toUtf8Lower($add, $charset);

        $cond = $this->toUtf8Lower($parts[4], $charset);
        // MySpell conditions are already a PCRE fragment. A suffix condition
        // must match the END of the stem ("cond$"); a prefix condition the
        // START ("^cond"). '.' means "no condition". Unicode-aware (/u) since
        // everything has been converted to UTF-8; '#' is a safe delimiter as
        // conditions only contain letters, '.', and '[...]' classes.
        $pattern = $type === 'SFX'
            ? '#' . $cond . '$#u'
            : '#^' . $cond . '#u';

        return ['flag' => $flag, 'cross' => $cross, 'strip' => $strip, 'add' => $add, 'pattern' => $pattern];
    }

    private function toUtf8Lower(string $s, string $charset): string
    {
        if ($charset !== 'utf-8' && $charset !== 'utf8') {
            $s = (string) mb_convert_encoding($s, 'UTF-8', $charset);
        }
        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * Strips one prefix off $word, yielding candidate stems. Each candidate is
     * the form the prefix was attached to (which, for cross-product, may still
     * carry a suffix). $word must be lowercased UTF-8.
     *
     * @return list<array{stem: string, flag: string, cross: bool}>
     */
    public function stripPrefix(string $word): array
    {
        $out = [];
        foreach ($this->prefixRules as $rule) {
            $add = $rule['add'];
            if ($add !== '' && !str_starts_with($word, $add)) {
                continue;
            }
            $stem = $rule['strip'] . substr($word, strlen($add));
            if ($stem === '' || preg_match($rule['pattern'], $stem) !== 1) {
                continue;
            }
            $out[] = ['stem' => $stem, 'flag' => $rule['flag'], 'cross' => $rule['cross']];
        }
        return $out;
    }

    /**
     * Strips one suffix off $word, yielding candidate stems. $word must be
     * lowercased UTF-8.
     *
     * @return list<array{stem: string, flag: string, cross: bool}>
     */
    public function stripSuffix(string $word): array
    {
        $out = [];
        foreach ($this->suffixRules as $rule) {
            $add = $rule['add'];
            if ($add !== '' && !str_ends_with($word, $add)) {
                continue;
            }
            $base = $add === '' ? $word : substr($word, 0, strlen($word) - strlen($add));
            $stem = $base . $rule['strip'];
            if ($stem === '' || preg_match($rule['pattern'], $stem) !== 1) {
                continue;
            }
            $out[] = ['stem' => $stem, 'flag' => $rule['flag'], 'cross' => $rule['cross']];
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Aspell\Engine;

/**
 * Port of Aspell's tex.cpp filter.
 * Uses a state machine to track LaTeX commands, options, and parameters.
 */
class TexFilter
{
    private const NAME = 0;
    private const OPT = 1;
    private const PARM = 2;
    private const OTHER = 3;
    private const SWALLOW = 4;

    private bool $inComment = false;
    private bool $prevBackslash = false;
    private array $stack = [];
    private array $commands = [];
    private bool $checkComments = false;

    /**
     * Per-environment rules for the mandatory {…} arguments that follow the
     * environment name in \begin{env}{…}{…}. Keyed by environment name; the
     * value is a TexFilter spec ('p' = ignore one {…}). An optional [placement]
     * argument after \begin{env} is always ignored regardless of this map.
     *
     * @var array<string, string>
     */
    private array $environments = [];

    public function __construct(array $customCommands = [], array $customEnvironments = [])
    {
        // Default rules matching Aspell's behavior
        // P/p: check/ignore parameter {}
        // O/o: check/ignore option []
        $this->commands = array_merge([
            'cite' => 'p',
            'nocite' => 'p',
            // \bibitem[<author-year label>]{<key>}: ignore both the optional
            // label and the mandatory cite key. Without this the key leaks
            // through the filter, and since the tokenizer splits on digits,
            // "halloran1997study" becomes the misspelling "halloran".
            'bibitem' => 'op',
            'ref' => 'p',
            'eqref' => 'p',
            'label' => 'p',
            'pageref' => 'p',
            'bibliographystyle' => 'p',
            'bibliography' => 'p',
            'documentclass' => 'p',
            'usepackage' => 'p',
            'hypersetup' => 'p',
            'begin' => 'p',
            'end' => 'p',
            'input' => 'p',
            'include' => 'p',
            'includeonly' => 'p',
            'includegraphics' => 'op', // ignore [key=val options] and {filename}
            'graphicspath' => 'p',
            // Theorem machinery: style names and \newtheorem environment keys
            // are identifiers, not prose.
            'theoremstyle' => 'p',
            'newtheorem' => 'p',
            'newtheoremstyle' => 'p',
            'textcolor' => 'pp', // ignore color name, check text
            'definecolor' => 'ppp',
            'newadd' => 'P', // example of custom command to check
        ], $customCommands);

        // Environments whose \begin{env}{…} takes extra identifier arguments.
        // \begin{restatable}{<type>}{<macro>} names a theorem type and the
        // macro that restates it — neither is prose.
        $this->environments = array_merge([
            'restatable' => 'pp',
        ], $customEnvironments);

        $this->reset();
    }

    public function reset(): void
    {
        $this->inComment = false;
        $this->prevBackslash = false;
        $this->stack = [
            ['in_what' => self::PARM, 'name' => '', 'do_check' => 'P']
        ];
    }

    public function filter(string $text): string
    {
        $this->reset();
        $result = '';
        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1);
            if ($this->processChar($char)) {
                $result .= ' ';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    private function processChar(string $c): bool
    {
        // Deal with comments
        if ($c === '%' && !$this->prevBackslash) {
            $this->inComment = true;
        }
        if ($this->inComment && $c === "\n") {
            $this->inComment = false;
        }

        $this->prevBackslash = false;

        if ($this->inComment) {
            return !$this->checkComments;
        }

        $top = &$this->stack[count($this->stack) - 1];

        if ($top['in_what'] === self::NAME) {
            if (ctype_alpha($c)) {
                $top['name'] .= $c;
                return true;
            } else {
                if ($top['name'] === '' && $c === '@') {
                    $top['name'] .= $c;
                    return true;
                }

                $top['in_what'] = self::OTHER;

                if ($top['name'] === '') {
                    $top['name'] = $c;
                    $top['do_check'] = $this->commands[$top['name']] ?? '';
                    return !ctype_space($c);
                }

                $top['do_check'] = $this->commands[$top['name']] ?? '';
                // \begin's first {…} is the environment name: capture it so
                // per-environment argument rules can be applied when it closes.
                $top['captureEnv'] = ($top['name'] === 'begin');

                if (ctype_space($c)) {
                    $top['in_what'] = self::SWALLOW;
                    return true;
                } elseif ($c === '*') {
                    return true;
                }
                // Fall through to Other processing...
            }
        } elseif ($top['in_what'] === self::SWALLOW) {
            if (ctype_space($c)) {
                return true;
            } else {
                $top['in_what'] = self::OTHER;
            }
        }

        if ($c === '{') {
            while (isset($top['do_check'][0]) && ($top['do_check'][0] === 'O' || $top['do_check'][0] === 'o')) {
                $top['do_check'] = substr($top['do_check'], 1);
            }
        }

        if (($top['do_check'] ?? '') === '') {
            $this->popCommand();
            $top = &$this->stack[count($this->stack) - 1];
        }

        if ($c === '{') {
            if ($top['in_what'] === self::PARM || $top['in_what'] === self::OPT || ($top['do_check'] ?? '') === '') {
                $this->pushCommand(self::PARM);
                $top = &$this->stack[count($this->stack) - 1];
            }
            $top['in_what'] = self::PARM;
            return true;
        }

        if ($top['in_what'] === self::OTHER) {
            if ($c === '[') {
                $top['in_what'] = self::OPT;
                return true;
            } elseif (ctype_space($c)) {
                return true;
            } else {
                $this->popCommand();
                $top = &$this->stack[count($this->stack) - 1];
            }
        }

        if ($c === '\\') {
            $this->prevBackslash = true;
            $this->pushCommand(self::NAME);
            return true;
        }

        if ($top['in_what'] === self::PARM) {
            if ($c === '}') {
                if (($top['captureEnv'] ?? false) === true) {
                    // Just closed \begin's {env} name. Apply this environment's
                    // extra-argument rule and always ignore a following optional
                    // [placement] (e.g. \begin{table}[htbp], enumitem options).
                    $env = $top['envName'] ?? '';
                    $top['captureEnv'] = false;
                    $top['envName'] = '';
                    $top['do_check'] = 'o' . ($this->environments[$env] ?? '');
                    $top['in_what'] = self::OTHER;
                    return true;
                }
                return $this->endOption('P', 'p');
            }
            if (($top['captureEnv'] ?? false) === true) {
                $top['envName'] = ($top['envName'] ?? '') . $c;
            }
            return isset($top['do_check'][0]) && $top['do_check'][0] === 'p';
        } elseif ($top['in_what'] === self::OPT) {
            if ($c === ']') {
                return $this->endOption('O', 'o');
            } else {
                return isset($top['do_check'][0]) && $top['do_check'][0] === 'o';
            }
        }

        return false;
    }

    private function pushCommand(int $w): void
    {
        $this->stack[] = ['in_what' => $w, 'name' => '', 'do_check' => 'P'];
    }

    private function popCommand(): void
    {
        if (count($this->stack) > 1) {
            array_pop($this->stack);
        } else {
            $this->stack[0] = ['in_what' => self::PARM, 'name' => '', 'do_check' => 'P'];
        }
    }

    private function endOption(string $u, string $l): bool
    {
        $top = &$this->stack[count($this->stack) - 1];
        $top['in_what'] = self::OTHER;
        if (isset($top['do_check'][0]) && ($top['do_check'][0] === $u || $top['do_check'][0] === $l)) {
            $top['do_check'] = substr($top['do_check'], 1);
        }
        return true;
    }
}

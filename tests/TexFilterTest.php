<?php

declare(strict_types=1);

namespace Aspell\Tests;

use Aspell\Engine\TexFilter;
use PHPUnit\Framework\TestCase;

class TexFilterTest extends TestCase
{
    private TexFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new TexFilter();
    }

    public function testSimpleCommandRemoval(): void
    {
        $input = "Hello \\textbf{world}";
        $output = $this->filter->filter($input);
        // \textbf is not in ignored list, so it checks parameter.
        // The command itself should be replaced by spaces.
        $this->assertStringContainsString("Hello  ", $output);
        $this->assertStringContainsString("world ", $output);
    }

    public function testIgnoredCommand(): void
    {
        $input = "See \\cite{author2023}";
        $output = $this->filter->filter($input);
        // \cite is ignored ('p'), so author2023 should be replaced by spaces.
        $this->assertStringNotContainsString("author2023", $output);
        $this->assertStringContainsString("See", $output);
    }

    public function testComments(): void
    {
        $input = "Visible text % hidden comment\nStill visible";
        $output = $this->filter->filter($input);
        $this->assertStringContainsString("Visible text", $output);
        $this->assertStringNotContainsString("hidden comment", $output);
        $this->assertStringContainsString("Still visible", $output);
    }

    public function testEscapedPercent(): void
    {
        $input = "100\\% sure";
        $output = $this->filter->filter($input);
        $this->assertStringContainsString("sure", $output);
        // The backslash and percent might be replaced by spaces or kept depending on logic,
        // but it shouldn't start a comment.
        $this->assertStringContainsString("sure", $output);
    }

    public function testNestedCommands(): void
    {
        $input = "\\section{A \\textbf{bold} title}";
        $output = $this->filter->filter($input);
        $this->assertStringContainsString("bold", $output);
        $this->assertStringContainsString("title", $output);
    }

    /** \includegraphics: ignore [key=val options] and the {filename}. */
    public function testIncludegraphicsArgumentsIgnored(): void
    {
        $out = $this->filter->filter(
            '\includegraphics[width=.99\linewidth]{FigF1_riskCompensation.jpg}'
        );
        $this->assertStringNotContainsString('FigF1', $out);
        $this->assertStringNotContainsString('riskCompensation', $out);
        // No optional arg form must work too.
        $this->assertStringNotContainsString('plot', $this->filter->filter('\includegraphics{plot.pdf}'));
    }

    /** Theorem-style/definition names are identifiers, not prose. */
    public function testTheoremStyleNamesIgnored(): void
    {
        $this->assertStringNotContainsString('thmstyleone', $this->filter->filter('\theoremstyle{thmstyleone}'));
        $this->assertStringNotContainsString(
            'thmstyletwo',
            $this->filter->filter('\newtheoremstyle{thmstyletwo}{3pt}{3pt}{}{}{\bfseries}{.}{.5em}{}')
        );
    }

    /**
     * \begin{env}: the optional [placement] argument (float specifiers,
     * enumitem key=value options) is an identifier list, not prose.
     */
    public function testBeginOptionalArgumentIgnored(): void
    {
        $this->assertStringNotContainsString('htbp', $this->filter->filter('\begin{table}[htbp]'));
        $out = $this->filter->filter('\begin{enumerate}[label=\Roman*., labelwidth=2em, labelsep=1em]');
        $this->assertStringNotContainsString('labelwidth', $out);
        $this->assertStringNotContainsString('labelsep', $out);
    }

    /**
     * \begin{restatable}{<type>}{<macro>}: both extra arguments name a theorem
     * type and the macro that restates it — neither is prose.
     */
    public function testRestatableEnvironmentArgumentsIgnored(): void
    {
        $out = $this->filter->filter('\begin{restatable}{proposition}{FinalSizeLambda}');
        $this->assertStringNotContainsString('FinalSizeLambda', $out);
        // With the optional counter argument present.
        $out2 = $this->filter->filter('\begin{restatable}[thm]{proposition}{VEoevrall}');
        $this->assertStringNotContainsString('VEoevrall', $out2);
    }

    /** Regression: ordinary environments still expose their body prose. */
    public function testBeginDoesNotSwallowBodyProse(): void
    {
        $out = $this->filter->filter('\begin{itemize} apples oranges');
        $this->assertStringContainsString('apples', $out);
        $this->assertStringContainsString('oranges', $out);
        // \newtheorem's caption is real prose and must be checked.
        $this->assertStringContainsString('Theorem', $this->filter->filter('\newtheorem{thm}{Theorem}'));
    }
}

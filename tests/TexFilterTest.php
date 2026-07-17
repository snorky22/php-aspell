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
}

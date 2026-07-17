<?php

declare(strict_types=1);

namespace Aspell\Tests\Config;

use Aspell\Config\AspellConfig;
use PHPUnit\Framework\TestCase;

class AspellConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new AspellConfig();
        $this->assertEquals('en', $config->retrieve('lang'));
        $this->assertEquals('utf-8', $config->retrieve('encoding'));
        $this->assertEquals(50, $config->retrieve('size'));
    }

    public function testReplaceValues(): void
    {
        $config = new AspellConfig();
        $config->replace('lang', 'fr');
        $this->assertEquals('fr', $config->retrieve('lang'));

        $config->replace('size', "70"); // String should be cast to int
        $this->assertEquals(70, $config->retrieve('size'));
        $this->assertIsInt($config->retrieve('size'));
    }

    public function testUnknownKeyThrowsException(): void
    {
        $config = new AspellConfig();
        $this->expectException(\InvalidArgumentException::class);
        $config->replace('non-existent-key', 'value');
    }
}

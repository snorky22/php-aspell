<?php

declare(strict_types=1);

namespace Aspell\Tests\Dictionary;

use Aspell\Dictionary\CustomDictionary;
use PHPUnit\Framework\TestCase;

class CustomDictionaryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'aspell_cd_') ?: '';
        // Start from a clean, non-existent path so construction does not read it.
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testInMemoryLookupIsCaseInsensitive(): void
    {
        $dict = new CustomDictionary();

        $this->assertTrue($dict->addWord('Symfony'));
        $this->assertTrue($dict->lookup('Symfony'));
        $this->assertTrue($dict->lookup('symfony'));
        $this->assertFalse($dict->lookup('django'));
    }

    public function testAddWordReturnsFalseForDuplicatesAndBlanks(): void
    {
        $dict = new CustomDictionary();

        $this->assertTrue($dict->addWord('word'));
        $this->assertFalse($dict->addWord('word'));
        $this->assertFalse($dict->addWord('  word  ')); // trimmed duplicate
        $this->assertFalse($dict->addWord('   '));      // blank
    }

    public function testPersistsInAspellPersonalFormat(): void
    {
        $dict = new CustomDictionary($this->path, 'fr');
        $dict->addWord('Kubernetes');
        $dict->addWord('Symfony');

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);

        $this->assertSame('personal_ws-1.1 fr 2 utf-8', $lines[0]);
        $this->assertSame('Kubernetes', $lines[1]);
        $this->assertSame('Symfony', $lines[2]);
    }

    public function testReloadsPersistedWords(): void
    {
        (new CustomDictionary($this->path, 'en'))->addWord('Kubernetes');

        $reloaded = new CustomDictionary($this->path, 'en');

        $this->assertTrue($reloaded->lookup('kubernetes'));
        $this->assertSame(['Kubernetes'], $reloaded->getWords());
    }
}

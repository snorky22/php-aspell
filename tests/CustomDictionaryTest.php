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

    public function testToJsonEmitsLangAndUnescapedUnicode(): void
    {
        $dict = new CustomDictionary(null, 'fr');
        $dict->addWord('Symfony');
        $dict->addWord('café');

        $json = $dict->toJson();

        // Multibyte characters stay as UTF-8, not \uXXXX escapes.
        $this->assertStringContainsString('café', $json);
        $this->assertSame(
            ['lang' => 'fr', 'words' => ['Symfony', 'café']],
            json_decode($json, true)
        );
    }

    public function testJsonRoundTripPreservesWordsAndLang(): void
    {
        $original = new CustomDictionary(null, 'fr');
        $original->addWord('Symfony');
        $original->addWord('café'); // multibyte

        $restored = CustomDictionary::fromJson($original->toJson());

        $this->assertSame(['Symfony', 'café'], $restored->getWords());
        $this->assertTrue($restored->lookup('symfony')); // case-insensitive
        $this->assertTrue($restored->lookup('CAFÉ'));
        // Lang survived the round-trip.
        $this->assertStringContainsString('"lang":"fr"', $restored->toJson());
    }

    public function testFromJsonAcceptsBareWordArray(): void
    {
        $dict = CustomDictionary::fromJson('["Symfony","café"]', null, 'de');

        $this->assertSame(['Symfony', 'café'], $dict->getWords());
        // Falls back to the supplied $lang when the JSON carries none.
        $this->assertStringContainsString('"lang":"de"', $dict->toJson());
    }

    public function testFromJsonBindsPathWhenProvided(): void
    {
        $dict = CustomDictionary::fromJson('{"lang":"fr","words":["Symfony"]}', $this->path);

        // Binding a path means the words are persisted in the Aspell format.
        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        $this->assertSame('personal_ws-1.1 fr 1 utf-8', $lines[0]);
        $this->assertSame('Symfony', $lines[1]);
    }

    public function testFromJsonRejectsInvalidJson(): void
    {
        $this->expectException(\JsonException::class);
        CustomDictionary::fromJson('{not valid json');
    }

    public function testFromJsonRejectsNonListNonObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomDictionary::fromJson('42');
    }
}

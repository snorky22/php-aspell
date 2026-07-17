<?php

declare(strict_types=1);

namespace Aspell\Config;

use Aspell\Models\Error;
use Aspell\Models\ErrorInfo;

/**
 * Handles Aspell configuration settings.
 * Port of acommon::Config.
 */
class AspellConfig
{
    /** @var array<string, mixed> */
    private array $settings = [];

    /** @var array<string, KeyInfo> */
    private static array $defaultKeys = [];

    public function __construct()
    {
        $this->initializeDefaultKeys();
        foreach (self::$defaultKeys as $key => $info) {
            $this->settings[$key] = $info->default;
        }
    }

    private function initializeDefaultKeys(): void
    {
        if (self::$defaultKeys !== []) {
            return;
        }

        // Essential keys ported from Aspell
        $keys = [
            new KeyInfo('lang', KeyType::String, 'en', 'language code'),
            new KeyInfo('encoding', KeyType::String, 'utf-8', 'encoding to use'),
            new KeyInfo('dict-dir', KeyType::String, '/usr/lib/aspell', 'directory of dictionaries'),
            new KeyInfo('data-dir', KeyType::String, '/usr/share/aspell', 'directory of language data files'),
            new KeyInfo('jargon', KeyType::String, '', 'extra information to distinguish dictionaries'),
            new KeyInfo('size', KeyType::Integer, 50, 'size of the dictionary'),
        ];

        foreach ($keys as $ki) {
            self::$defaultKeys[$ki->name] = $ki;
        }
    }

    public function replace(string $key, mixed $value): void
    {
        if (!isset(self::$defaultKeys[$key])) {
            // In a real port we would use the Error system here
            throw new \InvalidArgumentException("Unknown config key: $key");
        }

        // Simple type validation
        $info = self::$defaultKeys[$key];
        switch ($info->type) {
            case KeyType::Integer:
                $value = (int) $value;
                break;
            case KeyType::Boolean:
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;
            case KeyType::String:
                $value = (string) $value;
                break;
        }

        $this->settings[$key] = $value;
    }

    public function retrieve(string $key): mixed
    {
        return $this->settings[$key] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Aspell\Config;

/**
 * Metadata for a configuration key.
 */
readonly class KeyInfo
{
    public function __construct(
        public string $name,
        public KeyType $type,
        public string|int|bool|array $default,
        public string $description = '',
        public int $flags = 0,
    ) {}
}

enum KeyType
{
    case String;
    case Integer;
    case Boolean;
    case List;
}

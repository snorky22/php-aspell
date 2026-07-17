<?php

declare(strict_types=1);

namespace Aspell\Models;

/**
 * Represents a word being checked.
 * Leveraging PHP 8.4 Property Hooks.
 */
class Word
{
    public function __construct(
        public string $original,
        public ?string $soundslike = null,
    ) {}

    public string $clean {
        get => strtolower(trim($this->original));
    }
}

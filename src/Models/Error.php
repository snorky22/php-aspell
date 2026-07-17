<?php

declare(strict_types=1);

namespace Aspell\Models;

/**
 * Represents a specific error instance.
 * Mirrors acommon::Error from GNU Aspell.
 */
readonly class Error
{
    public function __construct(
        public string $message,
        public ErrorInfo $type,
    ) {}

    /**
     * Checks if this error is of a certain type.
     */
    public function isA(ErrorInfo $type): bool
    {
        return $this->type->isA($type);
    }
}

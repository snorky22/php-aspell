<?php

declare(strict_types=1);

namespace Aspell\Models;

/**
 * Represents metadata about an error type.
 * Mirrors acommon::ErrorInfo from GNU Aspell.
 */
readonly class ErrorInfo
{
    /**
     * @param string[] $parameters Names of parameters for the error message.
     */
    public function __construct(
        public ?ErrorInfo $parent = null,
        public string $message = '',
        public array $parameters = [],
    ) {}

    /**
     * Checks if this error info is of a certain type (checking parent hierarchy).
     */
    public function isA(ErrorInfo $type): bool
    {
        $current = $this;
        while ($current !== null) {
            if ($current === $type) {
                return true;
            }
            $current = $current->parent;
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

use RuntimeException;

/**
 * A launchroom.json that isn't valid JSON, with the place it went wrong
 * (1-based line and column, columns counted in characters).
 */
final class JsonSyntaxError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $atLine,
        public readonly int $atColumn,
        public readonly ?string $hint = null,
    ) {
        parent::__construct($message);
    }
}

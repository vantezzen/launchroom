<?php

declare(strict_types=1);

namespace App\Support\Compose;

use RuntimeException;

/**
 * The file as a whole can't be imported (not YAML, too large, no services).
 * Per-line problems are notes, never this.
 */
final class ComposeRejected extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $yamlLine = null)
    {
        parent::__construct($message);
    }
}

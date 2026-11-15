<?php

declare(strict_types=1);

namespace App\Support\Files;

use RuntimeException;

final class SecureFileFailed extends RuntimeException
{
    private function __construct(string $message, public readonly bool $exists)
    {
        parent::__construct($message);
    }

    public static function exists(string $path): self
    {
        return new self("{$path} already exists.", true);
    }

    public static function notWritable(string $path): self
    {
        return new self("Can't write {$path}.", false);
    }
}

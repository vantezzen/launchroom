<?php

declare(strict_types=1);

namespace App\Services\Crypto\Exceptions;

use RuntimeException;

final class InvalidKeyFile extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("Key file [{$path}] does not exist or is not readable. Run `php artisan launchroom:keys:generate`.");
    }

    public static function malformed(string $path): self
    {
        return new self("Key file [{$path}] is malformed.");
    }

    public static function alreadyExists(string $path): self
    {
        return new self("Key file [{$path}] already exists; refusing to overwrite it.");
    }

    public static function notWritable(string $path): self
    {
        return new self("Key file [{$path}] could not be created.");
    }
}

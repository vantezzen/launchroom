<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use Spatie\LaravelData\Data;

/**
 * "What launchroom sent": one file's path and size (never its content in
 * the browser).
 */
final class DockerfileFileSentData extends Data
{
    public function __construct(
        public string $path,
        public int $bytes,
        public bool $truncated,
    ) {}
}

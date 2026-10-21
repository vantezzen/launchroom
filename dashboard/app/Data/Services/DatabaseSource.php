<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Enums\DatabaseEngine;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * An engine driver and a major version (docs/04 §10).
 */
final class DatabaseSource extends Data implements ServiceSource
{
    public function __construct(
        public DatabaseEngine $engine,
        #[Regex('/^[0-9]{1,3}(?:\.[0-9]{1,3})?$/D')]
        public string $version,
    ) {}

    public function type(): string
    {
        return 'database';
    }
}

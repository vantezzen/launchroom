<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Enums\DatabaseEngine;
use App\Enums\ServiceSourceType;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * An engine driver and a major version (docs/04 §10).
 */
final class DatabaseSource extends Data implements ServiceSource
{
    /** A major, optionally with a minor version ("16", "8.0"). */
    public const string VERSION = '/^[0-9]{1,3}(?:\.[0-9]{1,3})?$/D';

    public function __construct(
        public DatabaseEngine $engine,
        #[Regex(self::VERSION)]
        public string $version,
    ) {}

    public function type(): ServiceSourceType
    {
        return ServiceSourceType::Database;
    }
}

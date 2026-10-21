<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * `POST /cli/sessions`: what the CLI says about itself; shown to the person
 * approving (display only, never trusted).
 */
final class StartCliSessionInput extends Data
{
    public function __construct(
        #[Max(64), Regex('/^[A-Za-z0-9._ -]+$/D')]
        public string $clientName = 'launchroom-cli',
        #[Max(255), Regex('/^[A-Za-z0-9._-]+$/D')]
        public ?string $hostname = null,
        #[Max(64), Regex('/^[A-Za-z0-9.+_-]+$/D')]
        public ?string $version = null,
    ) {}
}

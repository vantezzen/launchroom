<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * `POST /deployments/{deployment}/fixes/{code}`: values that override the
 * fix's suggestion. Without them the suggested port or start period is used.
 */
final class ApplyFailureFixInput extends Data
{
    public function __construct(
        #[Min(1), Max(65535)]
        public ?int $port = null,
        #[Min(1), Max(3600)]
        public ?int $seconds = null,
    ) {}
}

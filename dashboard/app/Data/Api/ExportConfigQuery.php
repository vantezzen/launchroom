<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * `GET /environments/{environment}/config`: the current settings, or a
 * deployed revision (docs/18 §4).
 */
final class ExportConfigQuery extends Data
{
    public function __construct(
        #[Min(1), Max(2147483647)]
        public ?int $revision = null,
    ) {}
}

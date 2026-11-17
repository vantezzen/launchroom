<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * BuildSummary `requiredSecrets[]` (protocol RequiredSecret): a secret the
 * app needs that launchroom can't generate (RAILS_MASTER_KEY). The plan
 * preview asks for it before the first deploy.
 */
final class RequiredSecret extends Data
{
    public function __construct(
        #[Regex(Grammar::ENV_NAME)]
        public string $name,
        #[Max(256)]
        public ?string $reason = null,
    ) {}
}

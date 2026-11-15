<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\DeploymentFilter;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * The project deployments list's filters (public ids and a status group).
 */
final class DeploymentListFilterData extends Data
{
    public function __construct(
        #[Max(40)]
        public ?string $environment = null,
        #[Max(40)]
        public ?string $service = null,
        public ?DeploymentFilter $status = null,
    ) {}
}

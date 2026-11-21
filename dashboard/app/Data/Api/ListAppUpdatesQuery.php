<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * `GET /app-updates`: the team's image services with a newer release or an
 * update in flight, or one project's (`project`).
 */
final class ListAppUpdatesQuery extends Data
{
    public function __construct(
        /** A project id (`prj_…`) of the token's team. */
        #[Max(64)]
        public ?string $project = null,
    ) {}
}

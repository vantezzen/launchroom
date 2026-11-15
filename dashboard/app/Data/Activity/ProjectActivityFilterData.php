<?php

declare(strict_types=1);

namespace App\Data\Activity;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

/**
 * The project activity page's filter: the groups that exist for one project.
 */
final class ProjectActivityFilterData extends Data
{
    public function __construct(
        #[In(['deploys', 'promotions', 'changes'])]
        public ?string $kind = null,
    ) {}
}

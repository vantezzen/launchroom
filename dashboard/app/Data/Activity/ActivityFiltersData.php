<?php

declare(strict_types=1);

namespace App\Data\Activity;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Activity filters: a project (team page) and a group of kinds.
 */
final class ActivityFiltersData extends Data
{
    public const array GROUPS = [
        'deploys' => ['deploy', 'rollback', 'redeploy'],
        'promotions' => ['promotion', 'created'],
        'changes' => ['service', 'project'],
        'team' => ['server', 'member'],
        'alerts' => ['alert'],
    ];

    public function __construct(
        #[Regex('/^prj_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $project = null,
        #[In(['deploys', 'promotions', 'changes', 'team', 'alerts'])]
        public ?string $kind = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Activity;

use App\Data\Attributes\PublicIdOf;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

/**
 * Activity filters: a project (team page), a group of kinds and, for
 * alerts, `state=open` (only alerts still firing; Settings → Notifications
 * links here).
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
        #[PublicIdOf('prj')]
        public ?string $project = null,
        #[In(['deploys', 'promotions', 'changes', 'team', 'alerts'])]
        public ?string $kind = null,
        #[In(['open'])]
        public ?string $state = null,
    ) {}
}

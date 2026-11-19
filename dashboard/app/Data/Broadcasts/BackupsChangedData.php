<?php

declare(strict_types=1);

namespace App\Data\Broadcasts;

use Spatie\LaravelData\Data;

/**
 * `backups.changed`: the Backups page refetches its props. `scope` is
 * `instance` for launchroom's own backups (sent to every team; only
 * instance owners see them in the refetched page).
 */
final class BackupsChangedData extends Data
{
    public function __construct(
        public string $teamId,
        public string $scope,
    ) {}
}

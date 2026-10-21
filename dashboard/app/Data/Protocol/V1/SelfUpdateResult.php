<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * TaskCompletion.selfUpdate (protocol/schema/v1/task-completion.json): sent
 * for succeeded and failed self_update tasks (docs/11 §4).
 */
final class SelfUpdateResult extends Data
{
    /**
     * @param  'daemon'|'control_plane'  $scope
     */
    public function __construct(
        #[In(['daemon', 'control_plane'])]
        public string $scope,
        #[Max(64)]
        public string $fromVersion,
        #[Max(64)]
        public string $toVersion,
        public bool $rolledBack,
    ) {}
}

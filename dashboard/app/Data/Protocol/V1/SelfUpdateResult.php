<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Enums\Updates\UpdateScope;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * TaskCompletion.selfUpdate (protocol/schema/v1/task-completion.json): sent
 * for succeeded and failed self_update tasks (docs/11 §4).
 */
final class SelfUpdateResult extends Data
{
    public function __construct(
        public UpdateScope $scope,
        #[Max(64)]
        public string $fromVersion,
        #[Max(64)]
        public string $toVersion,
        /** The new version did not become healthy and fromVersion runs again. */
        public bool $rolledBack,
        /** Why the new version was rolled back (secret-free). */
        #[Max(4096)]
        public ?string $reason = null,
    ) {}
}

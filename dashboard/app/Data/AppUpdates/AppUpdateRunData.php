<?php

declare(strict_types=1);

namespace App\Data\AppUpdates;

use App\Enums\AppUpdates\AppUpdateStatus;
use App\Enums\AppUpdates\UpdateKind;
use Spatie\LaravelData\Data;

/**
 * One update as the service shows it (docs/04 §14.4): its step, what is
 * backed up, the deployment and, when it ended badly, what happened.
 */
final class AppUpdateRunData extends Data
{
    /**
     * @param  list<string>  $backingUp  names still being backed up ("Postgres", "listmonk’s volume")
     */
    public function __construct(
        public string $id,
        public AppUpdateStatus $status,
        public bool $automatic,
        public UpdateKind $kind,
        public string $fromVersion,
        public string $toVersion,
        public ?string $actor,
        public ?string $deploymentId,
        public ?string $deploymentHref,
        public ?string $message,
        public ?string $backupNote,
        public array $backingUp,
        public string $startedAt,
        public ?string $finishedAt,
        /** While watching: when the probation ends. */
        public ?string $watchUntil,
    ) {}
}

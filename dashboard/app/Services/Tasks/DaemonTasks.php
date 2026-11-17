<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Data\Protocol\V1\Tasks\TaskSpec;
use App\Data\Tasks\TaskSeal;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Models\DaemonTask;
use App\Models\Server;
use InvalidArgumentException;

/**
 * The one place daemon tasks are created (docs/03 §7). The caller decides
 * the spec, where it runs and how long it may take; the task type decides
 * how often it is attempted, and the spec what a server needs to lease it
 * (features, platform), stored with the task for TaskEligibility.
 */
final readonly class DaemonTasks
{
    /**
     * @param  Server|null  $pin  the only server that may lease it; null: any eligible server of the team
     * @param  ServerRole|null  $role  the role a server of the pool needs
     * @param  string|null  $publicId  a reserved id (a value sealed before the task exists is bound to it)
     */
    public function create(
        int $teamId,
        TaskSpec $spec,
        int $timeoutSeconds,
        ?Server $pin = null,
        ?ServerRole $role = null,
        ?TaskSeal $seal = null,
        ?DaemonTaskPurpose $purpose = null,
        ?int $deploymentId = null,
        ?string $publicId = null,
        ?int $requestedById = null,
    ): DaemonTask {
        if ($pin instanceof Server && $pin->team_id !== $teamId) {
            throw new InvalidArgumentException('A task runs on a server of its own team.');
        }

        $task = new DaemonTask([
            'team_id' => $teamId,
            'server_id' => $pin?->id,
            'pinned_to_server' => $pin instanceof Server,
            'required_role' => $role,
            'type' => $spec->type(),
            'spec' => $spec->toProtocol(),
            'seal' => $seal,
            'required_features' => array_map(static fn (DaemonFeature $feature): string => $feature->value, $spec->requiredFeatures()),
            'required_platform' => $spec->platform(),
            'platform_emulation' => $spec->platformEmulation(),
            'max_attempts' => self::attempts($spec->type()),
            'timeout_seconds' => $timeoutSeconds,
            'purpose' => $purpose,
            'deployment_id' => $deploymentId,
            'requested_by_id' => $requestedById,
        ]);

        if ($publicId !== null) {
            $task->public_id = $publicId;
        }

        $task->save();

        return $task;
    }

    /**
     * Attempts per task type. Work that would repeat a side effect (another
     * snapshot, a second import, a release command run twice, a session) is
     * attempted once and reports its failure; exports write idempotent
     * chunks; a self-update may be completed by the restarted daemon after
     * its lease expired; inventories only read.
     */
    public static function attempts(DaemonTaskType $type): int
    {
        return match ($type) {
            DaemonTaskType::Build, DaemonTaskType::Plan => config()->integer('launchroom.builds.max_attempts'),
            DaemonTaskType::Inventory, DaemonTaskType::SelfUpdate => 3,
            DaemonTaskType::DbExport, DaemonTaskType::VolumeExport => 2,
            DaemonTaskType::RunOnce, DaemonTaskType::Backup, DaemonTaskType::Restore, DaemonTaskType::BackupMaintenance,
            DaemonTaskType::BackupSnapshots, DaemonTaskType::VerifyRestore, DaemonTaskType::Logs, DaemonTaskType::Session,
            DaemonTaskType::DbImport, DaemonTaskType::VolumeImport, DaemonTaskType::Access, DaemonTaskType::InspectContainer,
            DaemonTaskType::Cleanup, DaemonTaskType::CertRetry => 1,
        };
    }
}

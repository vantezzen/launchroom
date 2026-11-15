<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use InvalidArgumentException;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * The typed spec of one task type, mirroring the Go `state.TaskSpec`
 * implementations (daemon/internal/state/task.go, protocol task.json).
 * `daemon_tasks.spec` stores the template (`toProtocol()`, nothing sealed);
 * the lease decodes it again and seals its secrets for the leasing server
 * (App\Services\Tasks\TaskSecrets). Optional fields are null and left out
 * of the wire document, like Go's `omitzero`.
 */
abstract class TaskSpec extends Data
{
    abstract public function type(): DaemonTaskType;

    /**
     * The launchroomd features a server needs to decode this spec (older
     * daemons refuse unknown fields): stored with the task, so only servers
     * that announced them lease it (App\Services\Tasks\TaskEligibility).
     * Mirrors the field and value features of protocol vocabulary.json for
     * the tasks any server of a pool may take. Task types a pinned server
     * must support (sessions, accesses, transfers, verifications) are checked
     * by their creators with a message for the user instead: required here,
     * a task for a server that lacks the feature would stay pending without
     * a deadline instead of failing.
     *
     * @return list<DaemonFeature>
     */
    public function requiredFeatures(): array
    {
        return [];
    }

    /**
     * The platform the task runs for, null: any server's.
     */
    public function platform(): ?string
    {
        return null;
    }

    /**
     * Whether a server may run it for platform() through its engine's
     * emulation (builds, D53).
     */
    public function platformEmulation(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return self::wire($this);
    }

    /**
     * @param  object|array<string, mixed>  $stored
     *
     * @throws InvalidArgumentException when it doesn't decode
     */
    public static function fromStored(DaemonTaskType $type, object|array $stored): self
    {
        $class = self::classFor($type);

        try {
            /** @var array<string, mixed> $wire */
            $wire = json_decode(json_encode($stored, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);

            return $class::fromWire($wire);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("The {$type->value} spec doesn’t decode: ".mb_substr($e->getMessage(), 0, 300), $e->getCode(), previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $wire
     */
    protected static function fromWire(array $wire): static
    {
        return static::from($wire);
    }

    /**
     * @return class-string<self>
     */
    private static function classFor(DaemonTaskType $type): string
    {
        return match ($type) {
            DaemonTaskType::Build => BuildSpec::class,
            DaemonTaskType::Plan => PlanSpec::class,
            DaemonTaskType::RunOnce => RunOnceSpec::class,
            DaemonTaskType::Backup => BackupSpec::class,
            DaemonTaskType::Restore => RestoreSpec::class,
            DaemonTaskType::BackupMaintenance => BackupMaintenanceSpec::class,
            DaemonTaskType::Logs => LogsSpec::class,
            DaemonTaskType::SelfUpdate => SelfUpdateSpec::class,
            DaemonTaskType::BackupSnapshots => BackupSnapshotsSpec::class,
            DaemonTaskType::VerifyRestore => VerifyRestoreSpec::class,
            DaemonTaskType::Session => SessionSpec::class,
            DaemonTaskType::DbImport => DbImportSpec::class,
            DaemonTaskType::VolumeImport => VolumeImportSpec::class,
            DaemonTaskType::DbExport => DbExportSpec::class,
            DaemonTaskType::VolumeExport => VolumeExportSpec::class,
            DaemonTaskType::Access => AccessSpec::class,
            DaemonTaskType::Inventory => InventorySpec::class,
            DaemonTaskType::InspectContainer => InspectContainerSpec::class,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function wire(Data $data): array
    {
        /** @var array<string, mixed> $wire */
        $wire = self::compact($data->toArray());

        return $wire;
    }

    protected static function compact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::compact(...), $value);
        }

        $out = [];

        foreach ($value as $key => $item) {
            if ($item !== null) {
                $out[$key] = self::compact($item);
            }
        }

        return $out;
    }
}

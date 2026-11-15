<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use ReflectionMethod;
use ReflectionParameter;
use Spatie\LaravelData\Data;

/**
 * What a task reported (`daemon_tasks.result`): the result fields of its
 * TaskCompletion ({@see TaskCompletion::$results}), without the envelope.
 * Inventories and container inspections live in their own tables
 * (docs/22 §8), never here.
 */
final class TaskResult extends Data
{
    public function __construct(
        public ?BuildResult $build = null,
        public ?RunOnceResult $runOnce = null,
        public ?PlanResult $plan = null,
        public ?TaskFailure $failure = null,
        public ?BackupResult $backup = null,
        public ?RestoreResult $restore = null,
        public ?BackupSnapshotsResult $snapshots = null,
        public ?MaintenanceResult $maintenance = null,
        public ?SelfUpdateResult $selfUpdate = null,
        public ?VerifyResult $verify = null,
        public ?SessionResult $session = null,
        public ?RepoInspection $inspection = null,
        public ?DataImportResult $dataImport = null,
        public ?DataExportResult $dataExport = null,
        public ?AccessResult $access = null,
    ) {}

    /**
     * The result field names (also the wire keys of a TaskCompletion).
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), new ReflectionMethod(self::class, '__construct')->getParameters());
    }

    public static function failed(TaskFailure $failure): self
    {
        return new self(failure: $failure);
    }

    public function isEmpty(): bool
    {
        return array_all(self::fields(), fn (string $field): bool => $this->{$field} === null);
    }
}

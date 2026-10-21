<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * POST /daemon/v1/tasks/{id}/complete (protocol/schema/v1/task-completion.json).
 */
final class TaskCompletion extends Data
{
    /**
     * @param  'succeeded'|'failed'|'cancelled'|'released'  $outcome  released: launchroomd stopped before the task finished and hands it back (docs/03 §7)
     */
    public function __construct(
        #[In(['launchroom.vantezzen.io/v1'])]
        public string $apiVersion,
        #[In(['TaskCompletion'])]
        public string $kind,
        #[Min(1)]
        public int $attempt,
        #[In(['succeeded', 'failed', 'cancelled', 'released'])]
        public string $outcome,
        #[Max(4096)]
        public string|Optional $error = new Optional,
        public BuildResult|null|Optional $build = new Optional,
        public RunOnceResult|null|Optional $runOnce = new Optional,
        public PlanResult|null|Optional $plan = new Optional,
        public TaskFailure|null|Optional $failure = new Optional,
        public BackupResult|null|Optional $backup = new Optional,
        public RestoreResult|null|Optional $restore = new Optional,
        public BackupSnapshotsResult|null|Optional $snapshots = new Optional,
        public MaintenanceResult|null|Optional $maintenance = new Optional,
        public SelfUpdateResult|null|Optional $selfUpdate = new Optional,
        public VerifyResult|null|Optional $verify = new Optional,
        public SessionResult|null|Optional $session = new Optional,
        /** Plan tasks with `inspect`, succeeded or failed (docs/20 §6). */
        public RepoInspection|null|Optional $inspection = new Optional,
        /** db_import / volume_import tasks that succeeded (D55). */
        public DataImportResult|null|Optional $dataImport = new Optional,
        /** db_export / volume_export tasks that succeeded (D55). */
        public DataExportResult|null|Optional $dataExport = new Optional,
    ) {}
}

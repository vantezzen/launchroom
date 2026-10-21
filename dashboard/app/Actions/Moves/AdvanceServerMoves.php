<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Backups\StartVolumeBackup;
use App\Actions\Backups\TakeSafetySnapshot;
use App\Actions\Deployments\RedeployService;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Transfers\AdvanceImports;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\DatabaseSource;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\DeploymentStatus;
use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\MoveItemStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ExportSource;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\ImportSourceKind;
use App\Enums\Transfers\TransferPurpose;
use App\Enums\WorkloadRole;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupRun;
use App\Models\Deployment;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\RestoreHolds;
use App\Services\Moves\MoveHolds;
use App\Services\Moves\MovePlanner;
use App\Services\Transfers\ImportTarget;
use App\Services\Transfers\TransferStore;
use App\Services\Transfers\TransferTasks;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves server moves forward (docs/14 §13.2), right after one starts, after
 * each change, and from `launchroom:moves:tick` every minute. Idempotent:
 * every step re-reads what it waits for.
 *
 * - Stateless services are redeployed all at once while the source drains:
 *   rolling updates, so the old replica serves until the new one is healthy.
 * - Stateful services move one at a time (one copy through the dashboard's
 *   disk at a time, a short downtime per service): [build for the target's
 *   platform] → [fresh backup] → stop the writers → export from the source
 *   (D55 db_export / volume_export, live) → **cutover**: its source
 *   placements go, its volumes now belong to the target (the source's
 *   Docker volume is kept untouched) → the database starts empty on the
 *   target / its volumes are created there → import (db_import /
 *   volume_import from the same transfer) → [files: start on the target] →
 *   done, the writers start again.
 * - A failure (or a cancel) after the cutover rolls the service back: its
 *   volumes return to the source and it is redeployed there, on the data it
 *   had when it stopped.
 */
final readonly class AdvanceServerMoves
{
    public function __construct(
        private RedeployService $redeploy,
        private PrebuildForPlatform $prebuild,
        private TakeSafetySnapshot $safety,
        private StartVolumeBackup $volumeBackup,
        private TransferStore $store,
        private TransferTasks $tasks,
        private RenderNodeState $render,
        private MovePlanner $planner,
        private RecordAuditEvent $audit,
        private AdvanceImports $imports,
    ) {}

    public function handle(?int $moveId = null): int
    {
        /** @var list<int> $ids */
        $ids = ServerMove::query()
            ->whereIn('status', ServerMoveStatus::values([ServerMoveStatus::Running, ServerMoveStatus::Cancelling]))
            ->when($moveId !== null, static fn ($query) => $query->whereKey($moveId))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $advanced = 0;

        foreach ($ids as $id) {
            try {
                // Several steps can follow each other in one call.
                for ($i = 0; $i < 6 && $this->advance($id); $i++) {
                    $advanced++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $advanced;
    }

    private function advance(int $id): bool
    {
        $changed = DB::transaction(function () use ($id): bool {
            $move = ServerMove::query()->whereKey($id)->with(['source', 'target'])->lockForUpdate()->first();

            if (! $move instanceof ServerMove || ! in_array($move->status, [ServerMoveStatus::Running, ServerMoveStatus::Cancelling], true)) {
                return false;
            }

            $user = $move->created_by === null ? null : User::query()->find($move->created_by);
            /** @var list<ServerMoveItem> $items */
            $items = array_values(ServerMoveItem::query()->where('server_move_id', $move->id)->orderBy('position')->lockForUpdate()->get()->all());
            $changed = $move->status === ServerMoveStatus::Cancelling
                ? $this->cancelItems($move, $items, $user)
                : $this->runItems($move, $items, $user);

            return $this->settle($move, $items) || $changed;
        });

        if ($changed) {
            // Imports created in this step get their task without waiting for the tick.
            $this->imports->handle();
        }

        return $changed;
    }

    /**
     * @param  list<ServerMoveItem>  $items
     */
    private function runItems(ServerMove $move, array $items, ?User $user): bool
    {
        $changed = false;

        foreach ($items as $item) {
            if ($item->kind === MoveItemKind::Stateless && in_array($item->status, [MoveItemStatus::Pending, MoveItemStatus::Starting], true)) {
                $changed = $this->stateless($move, $item, $user) || $changed;
            }
        }

        // Stateful services one at a time.
        $current = null;

        foreach ($items as $item) {
            if ($item->kind->isStateful() && $item->status->isActive()) {
                $current = $item;

                break;
            }
        }

        // Stateless services move first (no downtime); a database stops its
        // apps only once they run on the target.
        $statelessMoving = array_any($items, static fn (ServerMoveItem $item): bool => $item->kind === MoveItemKind::Stateless && in_array($item->status, [MoveItemStatus::Pending, MoveItemStatus::Starting], true));

        if (! $statelessMoving) {
            $current ??= array_find($items, static fn (ServerMoveItem $item): bool => $item->kind->isStateful() && $item->status === MoveItemStatus::Pending);
        }

        if ($current instanceof ServerMoveItem) {
            return $this->stateful($move, $current, $user) || $changed;
        }

        return $changed;
    }

    /**
     * @param  list<ServerMoveItem>  $items
     */
    private function cancelItems(ServerMove $move, array $items, ?User $user): bool
    {
        $changed = false;

        foreach ($items as $item) {
            $status = $item->status;

            if ($status === MoveItemStatus::RollingBack || ($status === MoveItemStatus::Starting && $item->kind === MoveItemKind::Stateless)) {
                $changed = ($status === MoveItemStatus::RollingBack ? $this->rollingBack($move, $item) : $this->stateless($move, $item, $user)) || $changed;

                continue;
            }

            if ($status->isPastCutover()) {
                $this->rollback($move, $item, null, $user);
                $changed = true;

                continue;
            }

            if (in_array($status, [MoveItemStatus::Pending, MoveItemStatus::Building, MoveItemStatus::BackingUp, MoveItemStatus::Stopping, MoveItemStatus::Exporting, MoveItemStatus::Failed], true)) {
                $held = MoveHolds::heldBy($item);
                $item->transitionTo(MoveItemStatus::Cancelled, "Stays on {$move->source->name}.");
                $this->release($held, []);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * The move's own status from its items.
     *
     * @param  list<ServerMoveItem>  $items
     */
    private function settle(ServerMove $move, array $items): bool
    {
        if ($move->status === ServerMoveStatus::Running && array_all($items, static fn (ServerMoveItem $item): bool => $item->status->isSettled())) {
            $move->transitionTo(ServerMoveStatus::Ready);

            return true;
        }

        if ($move->status === ServerMoveStatus::Cancelling && ! array_any($items, static fn (ServerMoveItem $item): bool => $item->status->isActive() || $item->status === MoveItemStatus::Pending)) {
            $move->transitionTo(ServerMoveStatus::Cancelled);
            $done = count(array_filter($items, static fn (ServerMoveItem $item): bool => $item->status === MoveItemStatus::Done));

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerMoveCancelled,
                actor: AuditActor::system(),
                teamId: $move->team_id,
                subjectType: 'server',
                subjectId: $move->source->public_id,
                meta: ['move' => $move->public_id, 'stage' => 'finished', 'movedAlready' => $done],
            ));

            // The source no longer drains.
            event(new NodeStateInvalidated([$move->source_server_id, $move->target_server_id]));

            return true;
        }

        return false;
    }

    // ---- Stateless --------------------------------------------------------------

    private function stateless(ServerMove $move, ServerMoveItem $item, ?User $user): bool
    {
        $instance = $this->instance($item);

        if (! $instance instanceof ServiceInstance) {
            $item->transitionTo(MoveItemStatus::Skipped, 'The service no longer exists.');

            return true;
        }

        if ($item->status === MoveItemStatus::Pending) {
            $item->forceFill(['attempts' => $item->attempts + 1, 'started_at' => $item->started_at ?? now()]);

            if (! $this->runsOn($instance, $move->source_server_id)) {
                $item->transitionTo(MoveItemStatus::Done, 'Already runs on another server.');

                return true;
            }

            try {
                $deployment = $this->redeploy->handle($instance, null, $user, AuditActor::system());
            } catch (DomainRuleViolation $e) {
                $item->transitionTo(MoveItemStatus::Failed, $e->getMessage());

                return true;
            }

            $item->deployment_id = $deployment->id;
            $item->transitionTo(MoveItemStatus::Starting, "Starting on {$move->target->name}; the copy on {$move->source->name} serves until then.");

            return true;
        }

        $deployment = $item->deployment_id === null ? null : Deployment::query()->find($item->deployment_id);

        return match ($deployment?->status) {
            DeploymentStatus::Active => $this->finish($move, $item, "Runs on {$this->where($instance, $move)}."),
            DeploymentStatus::Failed, DeploymentStatus::Cancelled => $this->failStateless($item, 'The deployment failed: '.($deployment->failure_message ?? $deployment->status->value)." It still runs on {$move->source->name}."),
            // Someone deployed it meanwhile: done once nothing runs on the source any more.
            DeploymentStatus::Superseded, null => $this->runsOn($instance, $move->source_server_id)
                ? $this->restartStateless($item)
                : $this->finish($move, $item, "Runs on {$this->where($instance, $move)}."),
            default => false,
        };
    }

    private function failStateless(ServerMoveItem $item, string $message): bool
    {
        $item->transitionTo(MoveItemStatus::Failed, $message);

        return true;
    }

    private function restartStateless(ServerMoveItem $item): bool
    {
        $item->transitionTo(MoveItemStatus::Failed, 'Another deployment replaced the move’s. Retry to move it.');

        return true;
    }

    // ---- Stateful ---------------------------------------------------------------

    private function stateful(ServerMove $move, ServerMoveItem $item, ?User $user): bool
    {
        $instance = $this->instance($item);

        if (! $instance instanceof ServiceInstance) {
            $held = MoveHolds::heldBy($item);
            $item->transitionTo(MoveItemStatus::Skipped, 'The service no longer exists.');
            $this->release($held, []);

            return true;
        }

        return match ($item->status) {
            MoveItemStatus::Pending => $this->begin($move, $item, $instance, $user),
            MoveItemStatus::Building => $this->building($move, $item, $instance, $user),
            MoveItemStatus::BackingUp => $this->backingUp($item),
            MoveItemStatus::Stopping => $this->stopping($move, $item, $instance, $user),
            MoveItemStatus::Exporting => $this->exporting($move, $item, $instance, $user),
            MoveItemStatus::Preparing => $this->preparing($move, $item, $instance, $user),
            MoveItemStatus::Importing => $this->importing($move, $item, $instance, $user),
            MoveItemStatus::Starting => $this->starting($move, $item, $user),
            MoveItemStatus::RollingBack => $this->rollingBack($move, $item),
            default => false,
        };
    }

    private function begin(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, ?User $user): bool
    {
        $item->forceFill(['attempts' => $item->attempts + 1, 'started_at' => $item->started_at ?? now()]);

        if (! $instance->volumes->contains(static fn (Volume $volume): bool => $volume->server_id === $move->source_server_id)) {
            $item->transitionTo(MoveItemStatus::Done, 'Its data is no longer on '.$move->source->name.'.');

            return true;
        }

        $platform = is_string($move->plan['platformTo'] ?? null) ? $move->plan['platformTo'] : null;

        if ($platform !== null && $this->planner->needsRebuild($instance, $platform)) {
            return $this->startPrebuild($move, $item, $instance, $platform, $user);
        }

        return $this->backupOrStop($move, $item, $instance, $user);
    }

    private function startPrebuild(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, string $platform, ?User $user): bool
    {
        try {
            $deployment = $this->prebuild->handle($instance, $platform, $user);
        } catch (DomainRuleViolation $e) {
            $item->transitionTo(MoveItemStatus::Failed, "It couldn’t be built for {$platform}: {$e->getMessage()}");

            return true;
        }

        $item->prebuild_deployment_id = $deployment->id;

        if ($item->status === MoveItemStatus::Building) {
            $item->save();

            return true;
        }

        $item->transitionTo(MoveItemStatus::Building, "Building for {$platform} while it still runs on {$move->source->name}.");

        return true;
    }

    private function building(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, ?User $user): bool
    {
        $deployment = $item->prebuild_deployment_id === null ? null : Deployment::query()->find($item->prebuild_deployment_id);
        $platform = is_string($move->plan['platformTo'] ?? null) ? $move->plan['platformTo'] : 'linux/amd64';

        if (! $deployment instanceof Deployment) {
            return $this->startPrebuild($move, $item, $instance, $platform, $user);
        }

        return match ($deployment->status) {
            DeploymentStatus::Superseded => $deployment->image !== null
                ? $this->backupOrStop($move, $item, $instance, $user)
                // A newer deployment replaced the build before it finished: build again.
                : $this->startPrebuild($move, $item, $instance, $platform, $user),
            DeploymentStatus::Failed, DeploymentStatus::Cancelled => $this->failBefore($item, "The build for {$platform} failed: ".($deployment->failure_message ?? $deployment->status->value)." It still runs on {$move->source->name}."),
            default => false,
        };
    }

    private function backupOrStop(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, ?User $user): bool
    {
        if ($move->backupFirst()) {
            $runs = [];

            if (DatabaseWorkloads::source($instance) instanceof DatabaseSource) {
                $run = $this->safety->handle($instance, BackupReason::PreMove, $user, AuditActor::system());

                if ($run instanceof BackupRun) {
                    $runs[] = ['volume' => null, 'backup' => $run->public_id, 'export' => null, 'import' => null];
                }
            } else {
                foreach ($instance->volumes as $volume) {
                    try {
                        $run = $this->volumeBackup->handle($instance, $volume, BackupReason::PreMove, $user, AuditActor::system());
                        $runs[] = ['volume' => $volume->public_id, 'backup' => $run->public_id, 'export' => null, 'import' => null];
                    } catch (DomainRuleViolation) {
                        // No destination any more: nothing to wait for.
                    }
                }
            }

            if ($runs !== []) {
                $item->transfers = $runs;
                $item->transitionTo(MoveItemStatus::BackingUp, 'Backing up before it stops.');

                return true;
            }
        }

        return $this->stop($item);
    }

    private function backingUp(ServerMoveItem $item): bool
    {
        $ids = array_values(array_filter(array_map(static fn (array $entry): mixed => $entry['backup'] ?? null, $item->transfers), is_string(...)));
        $runs = BackupRun::query()->whereIn('public_id', $ids)->get();

        $failed = $runs->first(static fn (BackupRun $run): bool => $run->status === BackupRunStatus::Failed);

        if ($failed instanceof BackupRun) {
            return $this->failBefore($item, 'The backup before the move failed: '.($failed->error ?? 'unknown error').' Nothing was changed.');
        }

        if ($runs->count() < count($ids) || ! $runs->every(static fn (BackupRun $run): bool => $run->status === BackupRunStatus::Succeeded)) {
            return false;
        }

        return $this->stop($item);
    }

    private function stop(ServerMoveItem $item): bool
    {
        $names = $this->heldNames($item);
        $item->transitionTo(MoveItemStatus::Stopping, $names === '' ? 'Stopping it.' : "Stopping {$names}.");
        $this->hold(MoveHolds::heldBy($item));

        return true;
    }

    private function stopping(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, ?User $user): bool
    {
        $held = MoveHolds::heldBy($item);
        $wait = config()->integer('launchroom.backups.restore_hold_wait_seconds');

        if (! RestoreHolds::released($held) && $item->stopped_at?->gt(now()->subSeconds($wait)) === true) {
            return false;
        }

        $source = $move->source;
        $entries = [];

        try {
            if ($item->kind === MoveItemKind::Dump) {
                $placement = DatabaseWorkloads::servingPlacement($instance);

                if (! $placement instanceof Placement || $placement->server_id !== $source->id) {
                    return $this->failBefore($item, "{$instance->service->name} isn’t running on {$source->name}, so it can’t be copied. Start it and retry.");
                }

                $target = ImportTarget::for($instance);
                $transfer = $this->transfer($instance, null, TransferPurpose::DatabaseExport, $target->exportFileName());
                $task = $this->tasks->exportDatabase($source, $instance->team_id, $target->engine(), $placement->workload_id, null, null, $transfer);
                $entries[] = ['volume' => null, 'export' => $this->exportRun($instance, DataSubject::Database, null, $transfer, $task->id, $user)->public_id, 'import' => null];
            } else {
                foreach ($instance->volumes as $volume) {
                    if ($volume->server_id !== $source->id) {
                        continue;
                    }

                    $transfer = $this->transfer($instance, $volume, TransferPurpose::VolumeExport, $instance->service->slug.'-'.substr($volume->public_id, 4, 8).'.tar.gz');
                    // The service is held: nothing writes while it is read.
                    $task = $this->tasks->exportVolume($source, $instance->team_id, $volume->public_id, 'live', null, null, $transfer);
                    $entries[] = ['volume' => $volume->public_id, 'export' => $this->exportRun($instance, DataSubject::Volume, $volume, $transfer, $task->id, $user)->public_id, 'import' => null];
                }
            }
        } catch (DomainRuleViolation $e) {
            return $this->failBefore($item, $e->getMessage());
        }

        $item->transfers = $entries;
        $item->transitionTo(MoveItemStatus::Exporting, "Copying it off {$source->name}.");

        return true;
    }

    private function exporting(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, ?User $user): bool
    {
        $runs = DataExportRun::query()->whereIn('public_id', $this->ids($item, 'export'))->with('transfer')->get();

        $failed = $runs->first(static fn (DataExportRun $run): bool => in_array($run->status, [ExportRunStatus::Failed, ExportRunStatus::Expired], true));

        if ($failed instanceof DataExportRun) {
            return $this->failBefore($item, 'Copying it failed: '.($failed->error ?? 'the export ended early').' It still runs on '.$move->source->name.'.');
        }

        if ($runs->count() < count($this->ids($item, 'export')) || ! $runs->every(static fn (DataExportRun $run): bool => $run->status === ExportRunStatus::Ready)) {
            return false;
        }

        $bytes = 0;

        foreach ($runs as $run) {
            $bytes += $run->transfer->size_bytes ?? 0;
            // Kept until the import read it (and swept soon after the move).
            DataTransfer::query()->whereKey($run->transfer_id)->update(['expires_at' => now()->addDay()]);
        }

        $item->copied_bytes = $bytes;

        return $this->cutover($move, $item, $instance, $user);
    }

    /**
     * From here the service belongs to the target: its placements on the
     * source go (it is held or stopped already), its volume rows point at
     * the target. The source's Docker volume stays untouched, which is what
     * a rollback starts from.
     */
    private function cutover(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, ?User $user): bool
    {
        Placement::query()->where('service_instance_id', $instance->id)->where('server_id', $move->source_server_id)->delete();
        Volume::query()->where('service_instance_id', $instance->id)->where('server_id', $move->source_server_id)->update(['server_id' => $move->target_server_id]);
        $item->cutover_at = now();

        if ($item->kind === MoveItemKind::Dump) {
            try {
                $deployment = $this->redeploy->handle($instance->refresh(), null, $user, AuditActor::system());
            } catch (DomainRuleViolation $e) {
                $item->transitionTo(MoveItemStatus::Preparing);
                $this->rollback($move, $item, "It couldn’t start on {$move->target->name}: {$e->getMessage()}", $user);

                return true;
            }

            $item->deployment_id = $deployment->id;
            $item->transitionTo(MoveItemStatus::Preparing, "Starting an empty {$this->engineLabel($instance)} on {$move->target->name}.");
            event(new NodeStateInvalidated([$move->source_server_id]));

            return true;
        }

        $item->transitionTo(MoveItemStatus::Preparing, "Creating its volumes on {$move->target->name}.");
        event(new NodeStateInvalidated([$move->source_server_id]));
        $this->render->handle($move->target);
        $version = Server::query()->whereKey($move->target_server_id)->value('desired_version');
        $item->target_version = is_numeric($version) ? (int) $version : null;
        $item->save();

        return true;
    }

    private function preparing(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, ?User $user): bool
    {
        if ($item->kind === MoveItemKind::Dump) {
            $deployment = $item->deployment_id === null ? null : Deployment::query()->find($item->deployment_id);

            return match ($deployment?->status) {
                DeploymentStatus::Active => $this->startImports($move, $item, $instance, $user),
                DeploymentStatus::Failed, DeploymentStatus::Cancelled, DeploymentStatus::Superseded, null => $this->rollback($move, $item, "It couldn’t start on {$move->target->name}: ".($deployment->failure_message ?? 'the deployment ended').'.', $user),
                default => false,
            };
        }

        $target = Server::query()->find($move->target_server_id);
        $observed = $target instanceof Server ? $target->observed_version : 0;

        if ($item->target_version !== null && $observed >= $item->target_version) {
            return $this->startImports($move, $item, $instance, $user);
        }

        if ($item->cutover_at?->lt(now()->subMinutes(10)) === true) {
            return $this->rollback($move, $item, "{$move->target->name} didn’t pick up its new volumes within 10 minutes. Is it online?", $user);
        }

        return false;
    }

    private function startImports(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, ?User $user): bool
    {
        $entries = [];

        foreach ($item->transfers as $entry) {
            $export = is_string($entry['export'] ?? null) ? DataExportRun::query()->where('public_id', $entry['export'])->first() : null;

            if (! $export instanceof DataExportRun || $export->transfer_id === null) {
                return $this->rollback($move, $item, 'The copy of its data is gone. Retry to copy it again.', $user);
            }

            $volume = $export->volume_id === null ? null : Volume::query()->find($export->volume_id);
            $run = new DataImportRun([
                'team_id' => $instance->team_id,
                'service_instance_id' => $instance->id,
                'subject' => $export->subject,
                'volume_id' => $volume?->id,
                'transfer_id' => $export->transfer_id,
                'source_kind' => ImportSourceKind::File,
                'source_label' => mb_strcut("Moved from {$move->source->name}", 0, 255),
                'server_id' => $move->target_server_id,
                // Retries import again over a partial copy.
                'replace' => true,
                'stop_dependents' => true,
                'strip_components' => 0,
                'actor_id' => $user?->id,
            ]);
            $run->status = ImportRunStatus::WaitingForSafetyBackup;
            $run->save();
            $entries[] = [...$entry, 'import' => $run->public_id];
        }

        $item->transfers = $entries;
        $item->transitionTo(MoveItemStatus::Importing, "Loading it on {$move->target->name}.");

        return true;
    }

    private function importing(ServerMove $move, ServerMoveItem $item, ServiceInstance $instance, ?User $user): bool
    {
        $ids = $this->ids($item, 'import');
        $runs = DataImportRun::query()->whereIn('public_id', $ids)->get();

        $failed = $runs->first(static fn (DataImportRun $run): bool => in_array($run->status, [ImportRunStatus::Failed, ImportRunStatus::Cancelled], true));

        if ($failed instanceof DataImportRun) {
            return $this->rollback($move, $item, 'Loading it on '.$move->target->name.' failed: '.($failed->error ?? 'the import ended early'), $user);
        }

        if ($runs->count() < count($ids) || ! $runs->every(static fn (DataImportRun $run): bool => $run->status === ImportRunStatus::Succeeded)) {
            return false;
        }

        if ($item->kind === MoveItemKind::Dump) {
            return $this->finish($move, $item, "Runs on {$move->target->name} with its data.");
        }

        try {
            $deployment = $this->redeploy->handle($instance, null, $user, AuditActor::system());
        } catch (DomainRuleViolation $e) {
            return $this->rollback($move, $item, "It couldn’t start on {$move->target->name}: {$e->getMessage()}", $user);
        }

        $item->deployment_id = $deployment->id;
        $item->transitionTo(MoveItemStatus::Starting, "Starting on {$move->target->name}.");

        return true;
    }

    private function starting(ServerMove $move, ServerMoveItem $item, ?User $user): bool
    {
        $deployment = $item->deployment_id === null ? null : Deployment::query()->find($item->deployment_id);

        return match ($deployment?->status) {
            DeploymentStatus::Active => $this->finish($move, $item, "Runs on {$move->target->name} with its data."),
            DeploymentStatus::Failed, DeploymentStatus::Cancelled, DeploymentStatus::Superseded, null => $this->rollback($move, $item, "It couldn’t start on {$move->target->name}: ".($deployment->failure_message ?? 'the deployment ended').'.', $user),
            default => false,
        };
    }

    /**
     * Puts a service that is past its cutover back on the source: its
     * volumes return there (the source's copy was never touched), whatever
     * was started on the target goes, and it is redeployed there. $failure
     * null = a cancel.
     */
    private function rollback(ServerMove $move, ServerMoveItem $item, ?string $failure, ?User $user): bool
    {
        $instance = $this->instance($item);

        foreach (DataImportRun::query()->whereIn('public_id', $this->ids($item, 'import'))->whereIn('status', [ImportRunStatus::WaitingForSafetyBackup->value, ImportRunStatus::Holding->value])->get() as $run) {
            $run->transitionTo(ImportRunStatus::Cancelled, 'The move put the service back.');
        }

        if ($failure !== null) {
            $item->failed_step = $item->status->value;
        }

        if (! $instance instanceof ServiceInstance) {
            $item->transitionTo(MoveItemStatus::RollingBack, $failure);
            $item->transitionTo(MoveItemStatus::Skipped, 'The service no longer exists.');

            return true;
        }

        Placement::query()->where('service_instance_id', $instance->id)->where('server_id', $move->target_server_id)->delete();
        Volume::query()->where('service_instance_id', $instance->id)->where('server_id', $move->target_server_id)->update(['server_id' => $move->source_server_id]);
        $item->transitionTo(MoveItemStatus::RollingBack, $failure ?? "Putting it back on {$move->source->name}.");
        event(new NodeStateInvalidated([$move->target_server_id]));

        try {
            $deployment = $this->redeploy->handle($instance->refresh(), null, $user, AuditActor::system());
            $item->deployment_id = $deployment->id;
            $item->save();
        } catch (DomainRuleViolation $e) {
            $held = MoveHolds::heldBy($item);
            $item->transitionTo(MoveItemStatus::Failed, ($failure ?? 'Cancelled.')." It couldn’t be started on {$move->source->name} again ({$e->getMessage()}); deploy it from its page.");
            $this->release($held, []);
        }

        return true;
    }

    private function rollingBack(ServerMove $move, ServerMoveItem $item): bool
    {
        $deployment = $item->deployment_id === null ? null : Deployment::query()->find($item->deployment_id);
        $status = $deployment?->status;

        if ($status !== null && ! in_array($status, [DeploymentStatus::Active, DeploymentStatus::Failed, DeploymentStatus::Cancelled, DeploymentStatus::Superseded], true)) {
            return false;
        }

        $held = MoveHolds::heldBy($item);
        $back = $status === DeploymentStatus::Active;
        $reason = $item->message ?? 'It failed.';

        if ($item->failed_step !== null) {
            $item->transitionTo(MoveItemStatus::Failed, $back ? "{$reason} It runs on {$move->source->name} again." : "{$reason} Starting it on {$move->source->name} again failed too: deploy it from its page.");
        } else {
            $item->transitionTo(MoveItemStatus::RolledBack, $back ? "Back on {$move->source->name}." : "Its data is back on {$move->source->name}, but it didn’t start there: deploy it from its page.");
        }

        $this->release($held, []);

        return true;
    }

    private function finish(ServerMove $move, ServerMoveItem $item, string $message): bool
    {
        $held = MoveHolds::heldBy($item);
        $item->transitionTo(MoveItemStatus::Done, $message);
        $this->release($held, [$move->target_server_id]);

        // The copies through the dashboard are no longer needed.
        $transfers = DataExportRun::query()->whereIn('public_id', $this->ids($item, 'export'))->pluck('transfer_id')->filter()->all();

        if ($transfers !== []) {
            DataTransfer::query()->whereKey($transfers)->update(['expires_at' => now()->addMinutes(10)]);
        }

        $instance = $this->instance($item);

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::ServerMoveItemFinished,
            actor: AuditActor::system(),
            teamId: $move->team_id,
            subjectType: 'service',
            subjectId: $instance?->service->public_id ?? $item->public_id,
            meta: array_filter([
                'move' => $move->public_id,
                'item' => $item->public_id,
                'kind' => $item->kind->value,
                'target' => $move->target->public_id,
                'copiedBytes' => $item->copied_bytes,
                'downtimeSeconds' => $item->stopped_at === null ? null : (int) $item->stopped_at->diffInSeconds(now()),
                'attempts' => $item->attempts,
            ], static fn (mixed $value): bool => $value !== null),
        ));

        return true;
    }

    /**
     * A failure before the cutover: nothing changed on the source; the held
     * services start again.
     */
    private function failBefore(ServerMoveItem $item, string $message): bool
    {
        $held = MoveHolds::heldBy($item);
        $item->transitionTo(MoveItemStatus::Failed, $message);
        $this->release($held, []);

        return true;
    }

    // ---- Helpers ----------------------------------------------------------------

    private function transfer(ServiceInstance $instance, ?Volume $volume, TransferPurpose $purpose, string $fileName): DataTransfer
    {
        $transfer = new DataTransfer([
            'team_id' => $instance->team_id,
            'service_instance_id' => $instance->id,
            'volume_id' => $volume?->id,
            'direction' => $purpose->direction(),
            'purpose' => $purpose,
            'file_name' => $fileName,
            'size_bytes' => 0,
            'chunk_bytes' => config()->integer('launchroom.transfers.chunk_bytes'),
            'chunk_count' => 0,
            'created_by' => null,
            'expires_at' => now()->addSeconds(config()->integer('launchroom.transfers.task_timeout_seconds'))->addHour(),
        ]);
        $transfer->public_id = PublicId::generate(DataTransfer::publicIdPrefix());
        $this->store->initialise($transfer);
        $transfer->save();

        return $transfer;
    }

    private function exportRun(ServiceInstance $instance, DataSubject $subject, ?Volume $volume, DataTransfer $transfer, int $taskId, ?User $user): DataExportRun
    {
        $run = new DataExportRun([
            'team_id' => $instance->team_id,
            'service_instance_id' => $instance->id,
            'subject' => $subject,
            'volume_id' => $volume?->id,
            'source' => ExportSource::Live,
            'snapshot_id' => null,
            'transfer_id' => $transfer->id,
            'actor_id' => $user?->id,
            'file_name' => $transfer->file_name,
        ]);
        $run->daemon_task_id = $taskId;
        $run->save();

        return $run;
    }

    /**
     * @return list<string>
     */
    private function ids(ServerMoveItem $item, string $key): array
    {
        return array_values(array_filter(array_map(static fn (array $entry): mixed => $entry[$key] ?? null, $item->transfers), is_string(...)));
    }

    private function instance(ServerMoveItem $item): ?ServiceInstance
    {
        return ServiceInstance::query()
            ->whereKey($item->service_instance_id)
            ->where('team_id', $item->team_id)
            ->whereNull('removed_at')
            ->with(['service', 'volumes', 'environment.project'])
            ->first();
    }

    private function runsOn(ServiceInstance $instance, int $serverId): bool
    {
        return Placement::query()
            ->where('service_instance_id', $instance->id)
            ->where('server_id', $serverId)
            ->where('role', WorkloadRole::Serving->value)
            ->exists();
    }

    private function where(ServiceInstance $instance, ServerMove $move): string
    {
        $names = Placement::query()
            ->where('service_instance_id', $instance->id)
            ->where('role', WorkloadRole::Serving->value)
            ->with('server')
            ->get()
            ->map(static fn (Placement $placement): string => $placement->server->name)
            ->unique()
            ->values()
            ->all();

        return $names === [] ? $move->target->name : implode(', ', $names);
    }

    private function engineLabel(ServiceInstance $instance): string
    {
        return DatabaseWorkloads::source($instance)?->engine->label() ?? $instance->service->name;
    }

    private function heldNames(ServerMoveItem $item): string
    {
        $ids = MoveHolds::heldBy($item);

        if ($ids === []) {
            return '';
        }

        $names = ServiceInstance::query()->whereKey($ids)->with('service')->get()->map(static fn (ServiceInstance $i): string => $i->service->name)->unique()->values()->all();

        return implode(', ', $names);
    }

    /**
     * @param  list<int>  $instanceIds
     */
    private function hold(array $instanceIds): void
    {
        $servers = RestoreHolds::serverIds($instanceIds);

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }
    }

    /**
     * @param  list<int>  $instanceIds  held before the change
     * @param  list<int>  $also  more servers to re-render
     */
    private function release(array $instanceIds, array $also): void
    {
        $servers = array_values(array_unique([...RestoreHolds::serverIds($instanceIds), ...$also]));

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }
    }
}

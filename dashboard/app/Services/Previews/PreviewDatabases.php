<?php

declare(strict_types=1);

namespace App\Services\Previews;

use App\Actions\Previews\AdvancePreviews;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\EnvironmentKind;
use App\Enums\PreviewStrategy;
use App\Enums\ServerRole;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Services\Backups\DatabaseWorkloads;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Variables\SecretTaint;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Data preparation of a preview's databases (docs/06 §5, docs/14 §6), run
 * under the preview's row lock by {@see AdvancePreviews}:
 *
 *     waiting → restoring → [sanitizing] → ready   (or failed)
 *
 * "Clone from another database": once the preview's own (new, empty)
 * database runs, a restore-as-new of the source's **latest successful backup
 * snapshot** goes into it through the backup machinery (`restore_runs`,
 * daemon `restore` task; the source takes no load). The optional sanitize
 * command then runs once in the database's image on the preview network
 * (`run_once`, purpose `sanitize`) before any app deploys.
 *
 * State lives in `previews.data.databases[<service id>]`.
 */
final readonly class PreviewDatabases
{
    public const string PURPOSE_SANITIZE = 'sanitize';

    /**
     * Advances every clone of the preview. Returns whether all of its
     * databases are ready for the apps (null: preparation failed).
     */
    public function advance(Preview $preview, Environment $environment): ?bool
    {
        $data = $preview->data;
        /** @var array<string, array<string, mixed>> $databases */
        $databases = is_array($data['databases'] ?? null) ? $data['databases'] : [];
        $ready = true;
        $failed = false;

        // Fork previews never receive cloned data (docs/09 R20); their
        // databases were created as new empty instances, and an older
        // fork preview with a clone setting is not filled either.
        $instances = ServiceInstance::query()
            ->current()
            ->where('environment_id', $environment->id)
            ->where('enabled', true)
            ->where('preview_strategy', PreviewStrategy::Clone->value)
            ->when($environment->secrets_withheld, static fn (Builder $query) => $query->whereRaw('false'))
            ->with(['service', 'previewCloneSource.environment'])
            ->get();

        foreach ($instances as $instance) {
            if (! $instance->service->kind->isStateful()) {
                continue;
            }

            $id = $instance->service->public_id;
            $state = $databases[$id] ?? ['strategy' => PreviewStrategy::Clone->value, 'state' => 'waiting'];
            $state = $this->step($preview, $instance, $state);
            $databases[$id] = $state;

            $ready = $ready && $state['state'] === 'ready';
            $failed = $failed || $state['state'] === 'failed';
        }

        $preview->data = [...$data, 'databases' => $databases];
        $preview->save();

        return $failed ? null : $ready;
    }

    /**
     * Clone candidates for the "In previews" picker: every database of the
     * project outside previews (including the base's own) with its latest backup size, and whether the
     * team limit / production toggle allow it.
     *
     * @return list<array{instance: ServiceInstance, sizeBytes: int|null, backedUpAt: string|null, allowed: bool, reason: string|null}>
     */
    public function candidates(ServiceInstance $target, PreviewSettings $settings): array
    {
        $limit = $this->limitBytes($target->team_id);
        $instances = ServiceInstance::query()
            ->current()
            ->where('team_id', $target->team_id)
            ->whereIn('environment_id', Environment::query()->select('id')->where('project_id', $settings->project_id)->where('kind', '!=', EnvironmentKind::Preview->value))
            ->whereHas('service', static fn ($query) => $query->where('kind', 'database'))
            ->with(['service', 'environment'])
            ->orderBy('id')
            ->get();

        $candidates = [];

        foreach ($instances as $instance) {
            $run = $this->latestBackup($instance);
            [$allowed, $reason] = $this->check($instance, $run, $settings, $limit);
            $candidates[] = [
                'instance' => $instance,
                'sizeBytes' => $run?->size_bytes,
                'backedUpAt' => $run?->finished_at?->toIso8601String(),
                'allowed' => $allowed,
                'reason' => $reason,
            ];
        }

        return $candidates;
    }

    /**
     * Why a clone source can't be used (null: it can).
     */
    public function refusal(ServiceInstance $source, PreviewSettings $settings): ?string
    {
        return $this->check($source, $this->latestBackup($source), $settings, $this->limitBytes($source->team_id))[1];
    }

    public function limitBytes(int $teamId): int
    {
        $team = Team::query()->whereKey($teamId)->first();
        $mb = $team?->settings->previewCloneLimitMb ?? config()->integer('launchroom.previews.clone_limit_mb');

        return max(1, $mb) * 1024 * 1024;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function step(Preview $preview, ServiceInstance $instance, array $state): array
    {
        return match ($state['state'] ?? 'waiting') {
            'waiting' => $this->startRestore($preview, $instance, $state),
            'restoring' => $this->afterRestore($instance, $state),
            'sanitizing' => $this->afterSanitize($state),
            default => $state,
        };
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function startRestore(Preview $preview, ServiceInstance $instance, array $state): array
    {
        $source = $instance->previewCloneSource;
        $settings = PreviewSettings::forProject($preview->project);

        if (! $source instanceof ServiceInstance || $source->team_id !== $instance->team_id) {
            return [...$state, 'state' => 'failed', 'message' => 'The clone source no longer exists. Pick another one under Settings → Previews.'];
        }

        // The preview's own database must run first (restore-as-new target).
        if (! DatabaseWorkloads::servingPlacement($instance) instanceof Placement) {
            return $state;
        }

        $run = $this->latestBackup($source);
        $refusal = $this->check($source, $run, $settings, $this->limitBytes($instance->team_id))[1];

        if ($refusal !== null || ! $run instanceof BackupRun || $run->snapshot_id === null) {
            return [...$state, 'state' => 'failed', 'message' => $refusal ?? 'The source database has no backup to clone.'];
        }

        $restore = new RestoreRun([
            'team_id' => $instance->team_id,
            'destination_id' => $run->destination_id,
            'snapshot_id' => $run->snapshot_id,
            'source_service_instance_id' => $source->id,
            'target_service_instance_id' => $instance->id,
            'mode' => RestoreMode::NewService,
        ]);
        $restore->status = RestoreRunStatus::WaitingForTarget;
        $restore->size_bytes = $run->size_bytes;
        $restore->save();

        return [...$state, 'state' => 'restoring', 'restoreRun' => $restore->public_id, 'source' => $source->public_id, 'snapshot' => substr($run->snapshot_id, 0, 12), 'sizeBytes' => $run->size_bytes];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function afterRestore(ServiceInstance $instance, array $state): array
    {
        $run = RestoreRun::query()->where('public_id', is_string($state['restoreRun'] ?? null) ? $state['restoreRun'] : '')->where('team_id', $instance->team_id)->first();

        if (! $run instanceof RestoreRun) {
            return [...$state, 'state' => 'failed', 'message' => 'The clone’s restore disappeared.'];
        }

        if ($run->status === RestoreRunStatus::Failed) {
            return [...$state, 'state' => 'failed', 'message' => 'Restoring the clone failed: '.($run->error ?? 'unknown error')];
        }

        if ($run->status !== RestoreRunStatus::Succeeded) {
            return $state;
        }

        $command = $instance->preview_sanitize_command;

        if ($command === null || $command === []) {
            return [...$state, 'state' => 'ready'];
        }

        $task = $this->sanitize($instance, $command);

        return $task instanceof DaemonTask
            ? [...$state, 'state' => 'sanitizing', 'task' => $task->public_id]
            : [...$state, 'state' => 'failed', 'message' => 'The sanitize command could not be scheduled (the database isn’t running).'];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function afterSanitize(array $state): array
    {
        $task = DaemonTask::query()->where('public_id', is_string($state['task'] ?? null) ? $state['task'] : '')->first();

        if (! $task instanceof DaemonTask) {
            return [...$state, 'state' => 'failed', 'message' => 'The sanitize task disappeared.'];
        }

        $exit = is_array($task->result['runOnce'] ?? null) ? ($task->result['runOnce']['exitCode'] ?? null) : null;

        return match ($task->status) {
            DaemonTaskStatus::Pending, DaemonTaskStatus::Leased => $state,
            DaemonTaskStatus::Succeeded => $exit === 0
                ? [...$state, 'state' => 'ready']
                : [...$state, 'state' => 'failed', 'message' => 'The sanitize command exited with code '.(is_int($exit) ? $exit : '?').'; the apps were not started.'],
            default => [...$state, 'state' => 'failed', 'message' => 'The sanitize command could not run: '.($task->error ?? 'unknown error')],
        };
    }

    /**
     * The sanitize command as a one-off container from the database's image,
     * on the preview network, with the database's env (its credentials).
     *
     * @param  list<string>  $command
     */
    private function sanitize(ServiceInstance $instance, array $command): ?DaemonTask
    {
        $placement = DatabaseWorkloads::servingPlacement($instance);
        $deployment = $placement instanceof Placement ? Deployment::query()->whereKey($placement->deployment_id)->with(['revision', 'environment'])->first() : null;
        $spec = $deployment?->revision?->spec->service($instance->service->public_id);

        if (! $placement instanceof Placement || ! $deployment instanceof Deployment || $spec === null || $deployment->image === null) {
            return null;
        }

        $environment = $deployment->environment;
        $timeout = config()->integer('launchroom.deployments.release_timeout_seconds');
        $mask = SecretTaint::fromSpec($deployment->revision->spec)->taintedKeys($spec->slug);
        $argv = EffectiveDeploy::for($spec, $deployment)->argv($command);

        return DaemonTask::query()->create([
            'team_id' => $instance->team_id,
            'server_id' => $placement->server_id,
            'pinned_to_server' => true,
            'required_role' => ServerRole::Runtime,
            'type' => DaemonTaskType::RunOnce,
            'purpose' => self::PURPOSE_SANITIZE,
            'spec' => json_decode(json_encode([
                'service' => $instance->service->public_id,
                'environment' => $environment->public_id,
                'deployment' => $deployment->public_id,
                'image' => $deployment->image,
                ...array_filter($argv, static fn (?array $value): bool => $value !== null),
                ...($mask === [] ? [] : ['maskEnv' => array_slice($mask, 0, 1024)]),
                'network' => ['id' => $environment->networkId(), 'name' => $environment->networkName(), 'subnet' => $environment->network_subnet],
                'timeout' => $timeout.'s',
                'resources' => ['cpus' => $spec->scaling->cpus, 'memoryMb' => $spec->scaling->memoryMb, 'pids' => $spec->scaling->pids()],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR),
            'seal' => ['env' => ['deployment' => $deployment->id, 'keys' => null, 'defaults' => true]],
            'max_attempts' => 1,
            'timeout_seconds' => $timeout + 120,
            // Not tied to the database's deployment (it is long active).
            'deployment_id' => null,
        ]);
    }

    private function latestBackup(ServiceInstance $instance): ?BackupRun
    {
        return BackupRun::query()
            ->where('service_instance_id', $instance->id)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->whereNotNull('snapshot_id')
            ->latest('finished_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array{bool, string|null}
     */
    private function check(ServiceInstance $source, ?BackupRun $run, PreviewSettings $settings, int $limit): array
    {
        $source->loadMissing('environment');

        if ($source->environment->kind === EnvironmentKind::Production && ! $settings->allow_production_clone) {
            return [false, 'Cloning production data into previews is off. An admin can allow it (mind personal data).'];
        }

        if (! $run instanceof BackupRun) {
            return [false, 'This database has no successful backup yet. Back it up first.'];
        }

        if ($run->size_bytes !== null && $run->size_bytes > $limit) {
            return [false, sprintf('Its latest backup is %s MB; this team’s limit for preview clones is %s MB.', number_format($run->size_bytes / 1048576, 1), number_format($limit / 1048576))];
        }

        return [true, null];
    }
}

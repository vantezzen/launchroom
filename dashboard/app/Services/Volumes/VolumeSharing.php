<?php

declare(strict_types=1);

namespace App\Services\Volumes;

use App\Data\Canvas\VolumeAttachPreviewData;
use App\Enums\NodeStateField;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Models\VolumeMount;
use App\Scheduling\SchedulableServer;
use App\Scheduling\Scheduler;
use App\Services\Deployments\Placer;
use App\Services\NodeStates\FeatureGate;
use App\Support\Services\VolumeMountRules;

/**
 * Whether one service may mount another service's volume (D73), and what
 * that changes for it. A Docker volume lives on one server, so every
 * service that mounts it runs there: attaching is refused (fail closed)
 * when the service can't run on the volume's server, and explained when it
 * moves there with the next deploy.
 */
final readonly class VolumeSharing
{
    public function __construct(
        private Placer $placer,
    ) {}

    public function preview(ServiceInstance $borrower, Volume $volume, ?string $mountPath = null): VolumeAttachPreviewData
    {
        $borrower->loadMissing(['service', 'environment', 'volumes', 'volumeMounts.volume']);
        $volume->loadMissing(['serviceInstance.service', 'server']);
        $owner = $volume->serviceInstance;
        $name = $borrower->service->name;
        $paths = $this->mountPaths($borrower);
        $suggested = in_array($volume->mount_path, $paths, true) ? '' : $volume->mount_path;

        $problem = match (true) {
            $borrower->removed_at !== null => "{$name} is removed with the next deploy.",
            ! $borrower->service->kind->canMountSharedVolume() => 'A database keeps its own data. Volumes can be shared with apps, workers and crons.',
            $owner->environment_id !== $borrower->environment_id => 'A volume can only be shared within one environment.',
            $owner->id === $borrower->id => "{$name} already uses this volume.",
            $owner->removed_at !== null || $volume->removed_at !== null => 'This volume is removed with the next deploy.',
            ! $owner->service->kind->ownsVolumes() => 'A database keeps its data on this volume, so it can’t be shared. Connect to the database instead.',
            $borrower->volumeMounts->contains('volume_id', $volume->id) => "{$name} already uses this volume.",
            default => $this->placementProblem($borrower, $volume),
        };

        if ($problem === null && $mountPath !== null) {
            $problem = VolumeMountRules::problem($mountPath, $paths);
        }

        return new VolumeAttachPreviewData($problem, $problem === null ? $this->notices($borrower, $volume) : [], $suggested);
    }

    /**
     * Mount paths the service uses now: its own volumes and shared ones.
     *
     * @return list<string>
     */
    public function mountPaths(ServiceInstance $instance): array
    {
        $instance->loadMissing(['volumes', 'volumeMounts']);

        return [
            ...$instance->volumes->map(static fn (Volume $volume): string => $volume->mount_path)->all(),
            ...$instance->volumeMounts->map(static fn (VolumeMount $mount): string => $mount->mount_path)->all(),
        ];
    }

    /**
     * Why the service can't run on the volume's server, or null.
     */
    private function placementProblem(ServiceInstance $borrower, Volume $volume): ?string
    {
        $name = $borrower->service->name;
        $server = $volume->server;
        $elsewhere = MountedVolumes::for($borrower)->first(static fn (Volume $other): bool => $other->server_id !== null && $other->server_id !== $volume->server_id);

        if ($server instanceof Server && $elsewhere instanceof Volume) {
            $elsewhere->loadMissing('server');

            return "{$name} keeps data on ".($elsewhere->server->name ?? 'another server').", and this volume is on {$server->name}. A volume stays on one server, so services that share it run on the same server.";
        }

        if (! $server instanceof Server) {
            return null;
        }

        if (! FeatureGate::accepts($server, NodeStateField::SharedVolume)) {
            return "{$server->name} runs an older version of launchroom that can’t share volumes. Update it first.";
        }

        $candidate = array_find($this->placer->servers($borrower->team_id, $borrower->id), static fn (SchedulableServer $option): bool => $option->id === $server->id);
        $request = $this->placer->request($borrower, $borrower->scaling, null, 1);
        $reason = $candidate instanceof SchedulableServer ? Scheduler::rejection($candidate, $request, checkOnline: false) : 'not available';

        if ($reason === null) {
            return null;
        }

        return "{$name} can’t run on {$server->name}, where this volume is kept".($reason === '' ? ': it isn’t a runtime server' : ": {$reason}").'. Services that share a volume run on the same server.';
    }

    /**
     * What changes for the service with the next deploy.
     *
     * @return list<string>
     */
    private function notices(ServiceInstance $borrower, Volume $volume): array
    {
        $name = $borrower->service->name;
        $notices = [];
        $running = $borrower->active_deployment_id === null ? [] : Placement::query()
            ->where('deployment_id', $borrower->active_deployment_id)
            ->with('server:id,name')
            ->get()
            ->map(static fn (Placement $placement): string => $placement->server->name)
            ->unique()
            ->values()
            ->all();

        if ($volume->server instanceof Server && $running !== [] && $running !== [$volume->server->name]) {
            $notices[] = "{$name} runs on ".implode(' and ', $running).". With the next deploy it moves to {$volume->server->name}, where this volume is kept.";
        } elseif (! $volume->server instanceof Server) {
            $notices[] = 'The volume is created with the next deploy, and both services run on the server it is created on.';
        }

        if ($borrower->scaling->replicas > 1) {
            $notices[] = "{$name} runs {$borrower->scaling->replicas} replicas now. With a volume it runs as one.";
        }

        return $notices;
    }
}

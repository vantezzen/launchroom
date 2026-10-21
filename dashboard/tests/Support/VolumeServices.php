<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\AddServiceVolume;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Services\AddVolumeData;
use App\Enums\DaemonTaskType;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use RuntimeException;

/**
 * Builders for volume backup, final backup and "Replace server" tests: a
 * deployed image service with a volume, and the daemon side of tasks.
 */
final class VolumeServices
{
    /**
     * A team with a server and a deployed, healthy image service that has a
     * volume at /data/uploads (pinned to that server).
     *
     * @return array{Team, Server, string, ServiceInstance}
     */
    public static function runningApp(?Team $team = null, ?Server $server = null, ?string $keypair = null): array
    {
        $team ??= Team::factory()->create();

        if (! $server instanceof Server || $keypair === null) {
            [$server, $keypair] = Projects::server($team);
        }

        $project = Projects::create($team, 'files-'.bin2hex(random_bytes(3)));
        $service = Projects::image($project, 'uploads');
        $environment = $project->production()->sole();
        $instance = ServiceInstance::query()->where('service_id', $service->id)->where('environment_id', $environment->id)->sole();

        resolve(AddServiceVolume::class)->handle($instance, new AddVolumeData('/data/uploads'), AuditActor::system());
        resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());
        Projects::report($server);
        Projects::report($server);

        $instance->refresh();

        if ($instance->active_deployment_id === null) {
            throw new RuntimeException('The test app did not become active.');
        }

        return [$team, $server, $keypair, $instance];
    }

    public static function lease(Server $server, DaemonTaskType $type = DaemonTaskType::Backup): DaemonTask
    {
        $tasks = resolve(LeaseTasks::class)->handle($server, LeaseRequest::validateAndCreate([
            'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 8, 'types' => [$type->value],
        ]));

        if (count($tasks) !== 1) {
            throw new RuntimeException('Expected exactly one '.$type->value.' task, got '.count($tasks).'.');
        }

        return DaemonTask::query()->where('public_id', $tasks[0]->id)->sole();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function finish(Server $server, DaemonTask $task, string $outcome, array $extra = []): void
    {
        resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
            'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, 'outcome' => $outcome, ...$extra,
        ]));
    }

    public static function backedUp(Server $server, string $snapshot): void
    {
        self::finish($server, self::lease($server), 'succeeded', ['backup' => ['snapshotId' => $snapshot, 'sizeBytes' => 2048, 'durationMs' => 5]]);
    }
}

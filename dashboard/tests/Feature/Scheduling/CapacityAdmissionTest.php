<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Enums\ServerRole;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Scheduling\PlacementFailed;
use App\Scheduling\PlacementPlan;
use App\Scheduling\Scheduler;
use App\Services\Deployments\Placer;
use Illuminate\Support\Facades\DB;
use Tests\Support\Projects;

/*
| Limits are caps, not reservations (D72, docs/02 §2.1): CPU limits never
| block a deploy, and memory admission looks at what a server really has
| free, with each replica expected to use its measured peak (or a small
| default), never its full limit.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
});

function reportFreeMemory(Server $server, int $availableMb, int $totalMb = 4096, int $cpus = 2, ?string $collectedAt = null): void
{
    $server->forceFill([
        'host_status' => [
            'cpus' => $cpus,
            'memoryMb' => $totalMb,
            'health' => ['collectedAt' => $collectedAt ?? now()->toIso8601String(), 'memory' => ['totalMb' => $totalMb, 'availableMb' => $availableMb]],
        ],
        'host_status_at' => now(),
    ])->save();
}

test('a dozen idle services with default limits fit on a small server that has the memory free', function (): void {
    [$server] = Projects::server($this->team, [ServerRole::Edge, ServerRole::Runtime], memoryMb: 4096);
    // 2 cores, 4 GB, 2.6 GB really free.
    reportFreeMemory($server, 2600);
    $project = Projects::create($this->team, 'tools');

    foreach (range(1, 12) as $n) {
        Projects::image($project, "tool-{$n}");
    }

    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    // 12 × 1 CPU and 12 × 512 MB of limits on 2 cores and 4 GB: placed anyway.
    expect(Placement::query()->where('server_id', $server->id)->count())->toBe(12)
        ->and(Placement::query()->where('server_id', $server->id)->sum('cpus'))->toEqual(12);
});

test('memory that is really in use blocks a deploy, with a clear message', function (): void {
    [$server] = Projects::server($this->team, [ServerRole::Edge, ServerRole::Runtime], memoryMb: 4096);
    // Plenty of limits room on paper, but only 300 MB really available (256 MB headroom).
    reportFreeMemory($server, 300);
    $project = Projects::create($this->team, 'shop');
    $web = Projects::image($project, 'web');
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    $placer = resolve(Placer::class);

    expect(fn (): PlacementPlan => Scheduler::place($placer->servers($this->team->id, $instance->id), $placer->request($instance, $instance->scaling, null, 1)))
        ->toThrow(PlacementFailed::class, "There isn't enough free memory for this service right now. A replica needs about 128 MB, and the most any server has left is 44 MB.");
});

test('a replica is expected to use its measured peak, at least the default, at most its limit', function (): void {
    [$server] = Projects::server($this->team);
    $project = Projects::create($this->team, 'shop');
    $web = Projects::image($project, 'web');
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    $placer = resolve(Placer::class);

    expect($placer->expectedMemoryMb($instance, 512))->toBe(128);

    DB::table('workload_metric_samples')->insert([
        ['workload_id' => 'wl_a', 'resolution' => 1, 'at' => now()->subHour(), 'server_id' => $server->id, 'service_instance_id' => $instance->id, 'project_id' => $project->id, 'replica' => 0, 'cpu_cores' => 0.01, 'mem_mb' => 380],
        ['workload_id' => 'wl_a', 'resolution' => 1, 'at' => now()->subMinute(), 'server_id' => $server->id, 'service_instance_id' => $instance->id, 'project_id' => $project->id, 'replica' => 0, 'cpu_cores' => 0.01, 'mem_mb' => 210],
        // Older than 6 hours: ignored.
        ['workload_id' => 'wl_a', 'resolution' => 1, 'at' => now()->subHours(8), 'server_id' => $server->id, 'service_instance_id' => $instance->id, 'project_id' => $project->id, 'replica' => 0, 'cpu_cores' => 0.01, 'mem_mb' => 900],
    ]);

    expect($placer->expectedMemoryMb($instance, 512))->toBe(380)
        ->and($placer->expectedMemoryMb($instance, 256))->toBe(256)
        ->and($placer->request($instance, $instance->scaling, null, 1)->memoryNeedMb())->toBe(380);
});

test('workloads the measurement does not show yet count; a stale measurement falls back to the estimate', function (): void {
    [$server] = Projects::server($this->team, [ServerRole::Edge, ServerRole::Runtime], memoryMb: 4096);
    $project = Projects::create($this->team, 'shop');
    Projects::image($project, 'web');
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());
    $placer = resolve(Placer::class);

    // The web replica hasn't started: it isn't in the 3000 MB yet.
    reportFreeMemory($server, 3000);
    $view = $placer->servers($this->team->id)[0];

    expect($view->availableMemoryMb)->toBe(3000 - 256 - 128)
        ->and($view->limitMemoryMb)->toBe(512)
        ->and($view->allocatedMemoryMb)->toBe(128);

    // Running and measured since: only the headroom is kept.
    Placement::query()->update(['phase' => 'running', 'created_at' => now()->subMinutes(2)]);
    expect($placer->servers($this->team->id)[0]->availableMemoryMb)->toBe(3000 - 256);

    // A measurement older than 10 minutes is no longer the server's real usage.
    reportFreeMemory($server, 3000, collectedAt: now()->subMinutes(20)->toIso8601String());
    $stale = $placer->servers($this->team->id)[0];

    expect($stale->availableMemoryMb)->toBeNull()
        ->and(Scheduler::freeMemory($stale))->toBe(4096 - 256 - 128);
});

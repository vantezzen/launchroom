<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\RecordReport;
use App\Data\Audit\AuditActor;
use App\Data\Deployments\ContainerExitData;
use App\Data\Protocol\V1\Report;
use App\Data\Servers\ServerNoticeData;
use App\Enums\CertFailureKind;
use App\Enums\HealthCheckState;
use App\Enums\IngressFailureReason;
use App\Enums\ProbeHint;
use App\Enums\Servers\DaemonExitKind;
use App\Enums\WorkloadFailureCode;
use App\Events\NodeStateInvalidated;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\ServerHealth;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeDaemon;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

/**
 * @return array<string, mixed>
 */
function serverOpsReportFixture(Server $server): array
{
    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/report.ops.json'), true);
    $fixture['serverId'] = $server->public_id;
    $fixture['appliedVersion'] = 0;
    $fixture['workloads'] = [];
    $fixture['events'] = [];

    return $fixture;
}

/**
 * @param  array<string, mixed>  $fields
 */
function workloadReport(Server $server, int $seq, array $workloads = [], array $events = [], array $fields = []): Report
{
    return Report::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => now()->toIso8601ZuluString(), 'seq' => $seq, 'full' => true, 'appliedVersion' => $server->refresh()->desired_version,
        'workloads' => $workloads, ...($events === [] ? [] : ['events' => $events]), ...$fields,
    ]);
}

beforeEach(function (): void {
    Projects::useTestKek();
    CarbonImmutable::setTestNow('2026-11-17 12:00:40');
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('the host, edge and watchdog parts of a report are kept for the server page', function (): void {
    $seed = random_bytes(32);
    $server = Server::factory()->withSigningSeed($seed)->online()->create();
    $daemon = new FakeDaemon($seed)->as($server->public_id);

    $daemon->send($this, 'POST', '/daemon/v1/reports', serverOpsReportFixture($server))->assertNoContent();
    $server->refresh();

    $host = $server->host_status;
    expect($host?->docker?->reachable)->toBeTrue()
        ->and($host?->reconcile?->errors[0]->code)->toBe('volume')
        ->and($host?->daemon?->hostBootedAt)->toBe('2026-11-17T03:11:58Z')
        ->and($host?->lastRefusal?->clockSkewMs)->toBe(-94000)
        ->and($host?->health?->dataDisk?->inodesFree)->toBe(4100000)
        ->and($host?->health?->disk?->volumes[0]->sizeMb)->toBe(18432)
        ->and($host?->health?->disk?->walBacklogMb)->toBe(128)
        ->and($host?->health?->cleanup?->trigger->value)->toBe('manual');

    // The panic arrived: a crash, kept after the report that carried it.
    expect($server->last_daemon_exit?->kind)->toBe(DaemonExitKind::Crash)
        ->and($server->last_daemon_exit?->panic)->toStartWith('panic: runtime error');

    $ingress = $server->ingress_status;
    expect($ingress?->reason)->toBe(IngressFailureReason::PortInUse)
        ->and($ingress?->port)->toBe(80)
        ->and($ingress?->process)->toBe('nginx')
        ->and($ingress?->message)->toContain('address already in use')
        ->and($ingress?->output)->toHaveCount(1)
        ->and($ingress?->failingSince)->not->toBeNull()
        ->and($ingress?->problem()['title'])->toBe('Port 80 is taken by nginx');

    expect($server->control_plane_health?->ready)->toBeTrue()
        ->and($server->control_plane_health?->restarts)->toHaveCount(1)
        ->and($server->control_plane_health?->notices)->toHaveCount(2);

    // A later report without them keeps the refusal and the exit; restarts aren't duplicated.
    $next = serverOpsReportFixture($server);
    $next['seq'] = 78;
    unset($next['host']['lastRefusal'], $next['host']['daemon']['previousExit']);
    $daemon->send($this, 'POST', '/daemon/v1/reports', $next)->assertNoContent();
    $server->refresh();

    expect($server->host_status?->lastRefusal?->status)->toBe(401)
        ->and($server->last_daemon_exit?->kind)->toBe(DaemonExitKind::Crash)
        ->and($server->control_plane_health?->restarts)->toHaveCount(1)
        ->and($server->ingress_status?->failingSince)->toBe($ingress?->failingSince);

    // Admins see the crash report, others only the sentence.
    $notices = collect(ServerNoticeData::forServer($server, admin: true))->keyBy('key');
    expect($notices['exit']->trace)->toStartWith('panic:')
        ->and(collect(ServerNoticeData::forServer($server))->keyBy('key')['exit']->trace)->toBeNull();
});

test('Docker out of reach is a critical check and a notice', function (): void {
    $seed = random_bytes(32);
    $server = Server::factory()->withSigningSeed($seed)->online()->create(['name' => 'fra-1']);
    $fixture = serverOpsReportFixture($server);
    $fixture['host']['docker'] = ['reachable' => false, 'checkedAt' => now()->toIso8601ZuluString(), 'error' => 'dial unix /var/run/docker.sock: connect: no such file or directory'];

    new FakeDaemon($seed)->as($server->public_id)->send($this, 'POST', '/daemon/v1/reports', $fixture)->assertNoContent();
    $server->refresh();

    $docker = collect(resolve(ServerHealth::class)->evaluate($server)->checks)->firstWhere('key', 'docker');
    expect($docker?->state)->toBe(HealthCheckState::Critical)
        ->and(collect(ServerNoticeData::forServer($server))->firstWhere('key', 'docker')?->title)->toBe('fra-1 can’t reach Docker');
});

test('a certificate failure keeps its kind and the time of the next allowed try', function (): void {
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'Shop');
    Projects::image($project, 'web');
    $instance = ServiceInstance::query()->whereHas('environment', fn (Builder $q) => $q->where('project_id', $project->id))->sole();
    $domain = Domain::query()->create(['team_id' => $team->id, 'service_instance_id' => $instance->id, 'hostname' => 'shop.example.com', 'kind' => 'custom']);

    $fixture = serverOpsReportFixture($server);
    resolve(RecordReport::class)->handle($server, Report::validateAndCreate($fixture));

    $domain->refresh();
    expect($domain->cert_failure_kind)->toBe(CertFailureKind::RateLimited)
        ->and($domain->cert_retry_after?->toIso8601ZuluString())->toBe('2026-11-18T10:00:00Z');
});

test('pulled image facts, pull failures, crashes and cron runs are recorded from a report', function (): void {
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'Shop');
    Projects::image($project, 'web');
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());
    $placement = Placement::query()->where('server_id', $server->id)->sole();
    $instance = ServiceInstance::query()->whereKey($placement->service_instance_id)->sole();
    $instance->forceFill(['active_deployment_id' => $placement->deployment_id])->save();
    $workload = $placement->workload_id;
    $status = fn (array $more = []): array => ['id' => $workload, 'phase' => 'failed', 'observedAt' => now()->toIso8601ZuluString(), 'restartCount' => 0, ...$more];

    Event::fake([NodeStateInvalidated::class]);
    // The failure fails the rollout at once (AdvanceDeployment); here only what is stored counts.
    Bus::fake([AdvanceDeploymentJob::class]);
    resolve(RecordReport::class)->handle($server, workloadReport($server, 1, [$status([
        'image' => ['user' => 'root'],
        'failure' => ['code' => 'unauthorized', 'message' => 'pull access denied', 'at' => now()->subSecond()->toIso8601ZuluString()],
    ])]));

    expect(Deployment::query()->whereKey($placement->deployment_id)->sole()->image_facts?->user)->toBe('root');
    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $event): bool => in_array($server->id, $event->serverIds, true));

    $exit = $placement->refresh()->last_exit;
    Bus::assertDispatched(AdvanceDeploymentJob::class);
    expect($exit)->toBeInstanceOf(ContainerExitData::class)
        ->and($exit?->failure)->toBe(WorkloadFailureCode::Unauthorized)
        ->and($exit?->message)->toBe('pull access denied');

    // An older failure never replaces a newer exit.
    $placement->forceFill(['last_exit' => new ContainerExitData(at: now()->addMinute()->toIso8601ZuluString(), code: 1)])->save();
    resolve(RecordReport::class)->handle($server, workloadReport($server, 2, [$status([
        'failure' => ['code' => 'image_not_found', 'at' => now()->toIso8601ZuluString()],
    ])]));
    expect($placement->refresh()->last_exit?->code)->toBe(1);

    // A crash of the live replica; launchroom's own stop is not one.
    $event = fn (int $seq, string $kind, string $at, array $more = []): array => ['seq' => $seq, 'workload' => $workload, 'at' => $at, 'kind' => $kind, ...$more];
    resolve(RecordReport::class)->handle($server, workloadReport($server, 3, events: [
        $event(10, 'exited', '2026-11-17T12:00:00Z', ['exitCode' => 1]),
        $event(11, 'exited', '2026-11-17T12:00:05Z', ['exitCode' => 137, 'signal' => 9, 'byLaunchroom' => true]),
        $event(10, 'exited', '2026-11-17T12:00:00Z', ['exitCode' => 1]),
    ]));
    expect(DB::table('service_crashes')->where('service_instance_id', $instance->id)->pluck('exit_code')->all())->toBe([1]);

    // As a cron: a run opens on start and closes with its exit code.
    Service::query()->whereKey($instance->service_id)->update(['kind' => 'cron']);
    resolve(RecordReport::class)->handle($server, workloadReport($server, 4, events: [
        $event(20, 'started', '2026-11-17T12:01:00Z'),
        $event(21, 'exited', '2026-11-17T12:01:09Z', ['exitCode' => 2]),
        $event(22, 'started', '2026-11-17T12:02:00Z'),
    ]));
    $runs = DB::table('cron_runs')->where('service_instance_id', $instance->id)->orderBy('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs[0]->exit_code)->toBe(2)
        ->and($runs[1]->finished_at)->toBeNull();
});

test('a failing health check keeps what the answer means and its first bytes', function (): void {
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'Shop');
    Projects::image($project, 'web');
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());
    $placement = Placement::query()->where('server_id', $server->id)->sole();
    Bus::fake([AdvanceDeploymentJob::class]);

    $health = fn (string $hint): array => ['id' => $placement->workload_id, 'phase' => 'unhealthy', 'observedAt' => now()->toIso8601ZuluString(), 'restartCount' => 0, 'health' => [
        'state' => 'failing', 'consecutiveFailures' => 3, 'lastError' => 'HTTP 400', 'lastCheckedAt' => now()->toIso8601ZuluString(),
        'body' => "DisallowedHost: Invalid HTTP_HOST header: 'shop.example.com'", 'hint' => $hint,
    ]];
    resolve(RecordReport::class)->handle($server, workloadReport($server, 1, [$health('host_not_allowed')]));

    $probe = $placement->refresh()->probe;
    expect($probe?->hint)->toBe(ProbeHint::HostNotAllowed)
        ->and($probe?->body)->toBe("DisallowedHost: Invalid HTTP_HOST header: 'shop.example.com'");

    // An unknown hint reads as none (lenient), and that counts as progress.
    resolve(RecordReport::class)->handle($server, workloadReport($server, 2, [$health('something_new')]));
    expect($placement->refresh()->probe?->hint)->toBeNull();
});

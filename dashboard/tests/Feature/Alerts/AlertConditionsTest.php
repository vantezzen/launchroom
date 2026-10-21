<?php

declare(strict_types=1);

use App\Actions\Alerts\EvaluateAlertConditions;
use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Enums\WorkloadPhase;
use App\Events\DeploymentStatusChanged;
use App\Events\ServerStatusChanged;
use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertRuleSetting;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Support\PublicId;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Alerts;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Alerts::fakeDns();
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    CarbonImmutable::setTestNow('2026-10-20 12:00:30');
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    Alerts::channel($this->team, $this->owner);
    [$this->server] = Projects::server($this->team);
    $this->server->forceFill(['name' => 'fra-1'])->save();
    $this->project = Projects::create($this->team, 'Shop');
    Projects::image($this->project, 'web');
    resolve(DeployEnvironment::class)->handle($this->project->production()->sole(), null, AuditActor::system());
    $this->instance = ServiceInstance::query()->whereHas('environment', fn (Builder $q) => $q->where('project_id', $this->project->id))->sole();
    $this->placement = Placement::query()->where('service_instance_id', $this->instance->id)->sole();
    $this->instance->forceFill(['active_deployment_id' => $this->placement->deployment_id])->save();
    $this->evaluate = fn (): array => resolve(EvaluateAlertConditions::class)->handle();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function firing(AlertRule $rule): ?Alert
{
    return Alert::query()->where('rule', $rule->value)->where('status', AlertStatus::Firing->value)->first();
}

/**
 * @param  array<string, mixed>  $values
 */
function hostMinutes(int $serverId, int $minutes, array $values): void
{
    for ($i = 1; $i <= $minutes; $i++) {
        DB::table('server_metric_samples')->upsert([[
            'server_id' => $serverId, 'resolution' => 1, 'at' => CarbonImmutable::now()->startOfMinute()->subMinutes($i)->toIso8601String(),
            'cpu_pct' => 10.0, 'mem_used_mb' => 1000, 'mem_total_mb' => 8000, 'disk_used_mb' => 10_000, 'disk_total_mb' => 100_000, ...$values,
        ]], ['server_id', 'resolution', 'at'], ['cpu_pct', 'mem_used_mb', 'mem_total_mb', 'disk_used_mb', 'disk_total_mb']);
    }
}

test('CPU and memory alert after the configured duration and resolve with hysteresis', function (): void {
    hostMinutes($this->server->id, 10, ['cpu_pct' => 95.0, 'mem_used_mb' => 7600]);
    ($this->evaluate)();

    $cpu = firing(AlertRule::CpuHigh);
    expect($cpu?->subject_key)->toBe('server:'.$this->server->public_id)
        ->and($cpu?->title)->toBe('fra-1 is busy')
        ->and($cpu?->severity)->toBe(AlertSeverity::Warning)
        ->and(firing(AlertRule::MemoryHigh)?->title)->toBe('fra-1 is low on memory');

    // 85 %: below the threshold but above the resolve bar (80): still firing.
    hostMinutes($this->server->id, 10, ['cpu_pct' => 85.0, 'mem_used_mb' => 1000]);
    ($this->evaluate)();
    expect(firing(AlertRule::CpuHigh)?->id)->toBe($cpu?->id)
        ->and(firing(AlertRule::MemoryHigh))->toBeNull();

    hostMinutes($this->server->id, 10, ['cpu_pct' => 40.0]);
    ($this->evaluate)();
    expect(firing(AlertRule::CpuHigh))->toBeNull()
        ->and($cpu?->refresh()->resolve_reason)->toBe('CPU is back to normal.');
});

test('thresholds follow the team settings, and too few minutes never alert', function (): void {
    AlertRuleSetting::query()->create(['team_id' => $this->team->id, 'rule' => AlertRule::CpuHigh, 'enabled' => true, 'threshold' => 50, 'duration_minutes' => 5]);
    hostMinutes($this->server->id, 2, ['cpu_pct' => 60.0]);
    ($this->evaluate)();
    expect(firing(AlertRule::CpuHigh))->toBeNull();

    hostMinutes($this->server->id, 5, ['cpu_pct' => 60.0]);
    ($this->evaluate)();
    expect(firing(AlertRule::CpuHigh))->not->toBeNull();
});

test('a nearly full disk alerts, turns critical past 95 % and resolves 5 points below', function (): void {
    hostMinutes($this->server->id, 1, ['disk_used_mb' => 92_000]);
    ($this->evaluate)();
    expect(firing(AlertRule::DiskFull)?->severity)->toBe(AlertSeverity::Warning)
        ->and(firing(AlertRule::DiskFull)?->facts)->toBe(['Disk' => '92% full']);

    hostMinutes($this->server->id, 1, ['disk_used_mb' => 96_000]);
    ($this->evaluate)();
    expect(firing(AlertRule::DiskFull)?->severity)->toBe(AlertSeverity::Critical);

    hostMinutes($this->server->id, 1, ['disk_used_mb' => 84_000]);
    ($this->evaluate)();
    expect(firing(AlertRule::DiskFull))->toBeNull();
});

test('a service answering with errors alerts once there is enough traffic', function (): void {
    $sample = fn (int $requests, int $errors, int $minutesAgo) => DB::table('request_metric_samples')->upsert([[
        'server_id' => $this->server->id, 'route_id' => 'rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'resolution' => 1,
        'at' => CarbonImmutable::now()->startOfMinute()->subMinutes($minutesAgo)->toIso8601String(),
        'service_instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'requests' => $requests, 'status_5xx' => $errors,
    ]], ['server_id', 'route_id', 'resolution', 'at'], ['requests', 'status_5xx']);

    $sample(20, 10, 1);
    ($this->evaluate)();
    expect(firing(AlertRule::ErrorRateHigh))->toBeNull(); // 20 requests: not enough to judge

    foreach (range(1, 4) as $m) {
        $sample(100, 30, $m);
    }
    ($this->evaluate)();
    $alert = firing(AlertRule::ErrorRateHigh);
    expect($alert?->subject_key)->toBe('service:'.$this->instance->public_id)
        ->and($alert?->severity)->toBe(AlertSeverity::Critical)
        ->and($alert?->summary)->toBe('30% of requests failed with a server error in the last 5 minutes.')
        ->and($alert?->project_id)->toBe($this->project->id);

    foreach (range(1, 5) as $m) {
        $sample(100, 1, $m);
    }
    ($this->evaluate)();
    expect(firing(AlertRule::ErrorRateHigh))->toBeNull();
});

test('a certificate close to expiry alerts and resolves once renewed', function (): void {
    $domain = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'hostname' => 'shop.example.com', 'kind' => 'custom']);
    $domain->forceFill(['cert_expires_at' => now()->addDays(2), 'cert_error' => 'DNS problem: NXDOMAIN'])->save();

    ($this->evaluate)();
    $alert = firing(AlertRule::CertificateExpiring);
    expect($alert?->title)->toBe('The certificate for shop.example.com is about to expire')
        ->and($alert?->severity)->toBe(AlertSeverity::Critical)
        ->and($alert?->facts['Last error'] ?? null)->toBe('DNS problem: NXDOMAIN');

    $domain->forceFill(['cert_expires_at' => now()->addDays(89)])->save();
    ($this->evaluate)();
    expect(firing(AlertRule::CertificateExpiring))->toBeNull();
});

test('local *.localhost certificates (12-hour internal ones) never alert', function (): void {
    $domain = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'hostname' => 'web-production.shop.localhost', 'kind' => 'generated']);
    $domain->forceFill(['cert_expires_at' => now()->addHours(6)])->save();

    ($this->evaluate)();

    expect(firing(AlertRule::CertificateExpiring))->toBeNull();
});

test('an unhealthy service alerts after 3 minutes and resolves after 3 healthy ones', function (): void {
    $this->placement->forceFill(['phase' => WorkloadPhase::Unhealthy, 'message' => 'probe failed: connection refused'])->save();

    ($this->evaluate)();
    expect(firing(AlertRule::ServiceUnhealthy))->toBeNull();

    CarbonImmutable::setTestNow('2026-10-20 12:04:00');
    ($this->evaluate)();
    $alert = firing(AlertRule::ServiceUnhealthy);
    expect($alert?->title)->toBe('web is unhealthy in production')
        ->and($alert?->summary)->toBe('No replica is passing its health check. Its logs usually say why.')
        ->and($alert?->facts)->toMatchArray(['Replicas' => '0 of 1 healthy', 'Last message' => 'probe failed: connection refused']);

    $this->placement->forceFill(['phase' => WorkloadPhase::Healthy])->save();
    CarbonImmutable::setTestNow('2026-10-20 12:05:00');
    ($this->evaluate)();
    expect(firing(AlertRule::ServiceUnhealthy))->not->toBeNull();

    CarbonImmutable::setTestNow('2026-10-20 12:08:30');
    ($this->evaluate)();
    expect(firing(AlertRule::ServiceUnhealthy))->toBeNull();
});

test('a replica that keeps restarting counts as crash-looping', function (): void {
    $this->placement->forceFill(['phase' => WorkloadPhase::Running, 'restart_count' => 1])->save();
    ($this->evaluate)();

    foreach ([['12:02:00', 3], ['12:03:00', 4], ['12:06:00', 6]] as [$time, $count]) {
        CarbonImmutable::setTestNow("2026-10-20 {$time}");
        $this->placement->forceFill(['restart_count' => $count])->save();
        ($this->evaluate)();
    }

    expect(firing(AlertRule::ServiceUnhealthy)?->title)->toBe('web keeps restarting in production')
        ->and(firing(AlertRule::ServiceUnhealthy)?->facts['Restarts'] ?? null)->toBe('5 in 10 min');
});

test('replicas on servers that are not online are the server\'s alert, not the service\'s', function (): void {
    $this->placement->forceFill(['phase' => WorkloadPhase::Exited])->save();
    $this->server->forceFill(['status' => ServerStatus::Offline])->save();

    ($this->evaluate)();
    CarbonImmutable::setTestNow('2026-10-20 12:05:00');
    ($this->evaluate)();

    expect(firing(AlertRule::ServiceUnhealthy))->toBeNull();
});

test('a failed deploy alerts with the reassurance; the next live deploy resolves it; previews never alert', function (): void {
    $deployment = Deployment::query()->whereKey($this->placement->deployment_id)->sole();
    $deployment->forceFill(['commit_sha' => '3b7d5e1aaaaaaa'])->save();
    $failed = $deployment->replicate(['public_id']);
    $failed->forceFill(['public_id' => PublicId::generate('dpl'), 'commit_sha' => '9f00c2dbbbbbbb', 'status' => DeploymentStatus::Failed, 'failure_message' => "Build failed: pnpm lockfile is out of date\nmore"])->save();

    event(new DeploymentStatusChanged($failed->id, DeploymentStatus::Building, DeploymentStatus::Failed));

    $alert = firing(AlertRule::DeployFailed);
    expect($alert?->title)->toBe('web failed to deploy to production')
        ->and($alert?->summary)->toBe('Build failed: pnpm lockfile is out of date. Production still serves 3b7d5e1.')
        ->and($alert?->severity)->toBe(AlertSeverity::Critical)
        ->and($alert?->facts['Commit'] ?? null)->toBe('9f00c2d')
        ->and($alert?->occurrence)->toBe($failed->public_id)
        ->and($alert?->href)->toBe("/p/{$this->project->public_id}/{$this->project->production()->sole()->public_id}/d/{$failed->public_id}");

    event(new DeploymentStatusChanged($deployment->id, DeploymentStatus::Routing, DeploymentStatus::Active));
    expect(firing(AlertRule::DeployFailed))->toBeNull()
        ->and($alert?->refresh()->resolve_reason)->toBe('3b7d5e1 is live in production.');

    $failed->environment->forceFill(['kind' => 'preview'])->save();
    event(new DeploymentStatusChanged($failed->id, DeploymentStatus::Building, DeploymentStatus::Failed));
    expect(firing(AlertRule::DeployFailed))->toBeNull();
});

test('a server going offline alerts; coming back resolves it', function (): void {
    event(new ServerStatusChanged($this->server->id, ServerStatus::Unreachable, ServerStatus::Offline));

    expect(firing(AlertRule::ServerOffline)?->title)->toBe('fra-1 is offline')
        ->and(firing(AlertRule::ServerOffline)?->server_id)->toBe($this->server->id);

    event(new ServerStatusChanged($this->server->id, ServerStatus::Offline, ServerStatus::Online));
    expect(firing(AlertRule::ServerOffline))->toBeNull();
});

test('the evaluator is idempotent and the command runs', function (): void {
    hostMinutes($this->server->id, 10, ['cpu_pct' => 99.0]);

    expect(($this->evaluate)())->toBe(['raised' => 1, 'resolved' => 0])
        ->and(($this->evaluate)())->toBe(['raised' => 0, 'resolved' => 0]);

    $this->artisan('launchroom:alerts:evaluate')->assertSuccessful();
    $this->artisan('launchroom:alerts:prune')->assertSuccessful();
    expect(Alert::query()->count())->toBe(1);
});

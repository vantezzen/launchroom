<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Enums\Alerts\AlertChannelKind;
use App\Enums\CertStatus;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\EnvironmentKind;
use App\Enums\TeamRole;
use App\Models\DaemonTask;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\ServerDisk;
use App\Services\Alerts\FallbackWatchdog;
use Illuminate\Contracts\Database\Query\Builder;
use Tests\Support\Alerts;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Alerts::fakeDns();
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    [$this->server, $this->keypair] = Projects::server($this->team);
    $this->project = Projects::create($this->team, 'Shop');
    Projects::image($this->project, 'web');
    $this->instance = ServiceInstance::query()->whereHas('environment', fn (Builder $q) => $q->where('project_id', $this->project->id))->sole();
    $this->features = fn (DaemonFeature ...$features) => $this->server->forceFill(['daemon_features' => array_map(static fn (DaemonFeature $f): string => $f->value, $features)])->save();
});

test('"Free space now" creates one cleanup task pinned to the server', function (): void {
    $this->actingAs($this->owner)->post("/servers/{$this->server->public_id}/free-space")->assertSessionHasErrors();
    expect(DaemonTask::query()->count())->toBe(0);

    ($this->features)(DaemonFeature::CleanupTask);
    $this->actingAs($this->owner)->post("/servers/{$this->server->public_id}/free-space")->assertRedirect();
    $this->actingAs($this->owner)->post("/servers/{$this->server->public_id}/free-space")->assertRedirect();

    $task = DaemonTask::query()->sole();
    expect($task->type)->toBe(DaemonTaskType::Cleanup)
        ->and($task->server_id)->toBe($this->server->id)
        ->and($task->pinned_to_server)->toBeTrue();

    $disk = resolve(ServerDisk::class)->for($this->server->refresh(), true);
    expect($disk->cleanupTask?->status)->toBe(DaemonTaskStatus::Pending)
        ->and($disk->canFreeSpace)->toBeTrue();
});

test('only people who may change the server can free space', function (): void {
    ($this->features)(DaemonFeature::CleanupTask);
    $viewer = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($viewer)->post("/servers/{$this->server->public_id}/free-space")->assertForbidden();
    $this->actingAs(memberOf(Team::factory()->create(), TeamRole::Owner))->post("/servers/{$this->server->public_id}/free-space")->assertNotFound();

    expect(DaemonTask::query()->count())->toBe(0);
});

test('"Retry now" asks every edge that can retry, and waits out a rate limit', function (): void {
    ($this->features)(DaemonFeature::CertificateRetry);
    $domain = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'hostname' => 'shop.example.com', 'kind' => 'custom']);
    $domain->forceFill(['cert_status' => CertStatus::Failed, 'cert_retry_after' => now()->addHour()])->save();
    $environment = $this->instance->environment;
    $url = "/p/{$this->project->public_id}/{$environment->public_id}/s/{$this->instance->service->public_id}/domains/{$domain->public_id}/certificate/retry";

    $this->actingAs($this->owner)->post($url)->assertSessionHasErrors();
    expect(DaemonTask::query()->count())->toBe(0);

    $domain->forceFill(['cert_retry_after' => null])->save();
    $this->actingAs($this->owner)->post($url)->assertRedirect();

    $task = DaemonTask::query()->sole();
    expect($task->type)->toBe(DaemonTaskType::CertRetry)
        ->and((array) $task->spec)->toBe(['hosts' => ['shop.example.com']])
        ->and($task->server_id)->toBe($this->server->id);
});

test('the fallback channel is sealed into the control-plane server’s watchdog', function (): void {
    $this->server->forceFill(['is_control_plane' => true])->save();
    ($this->features)(DaemonFeature::Watchdog);
    $owner = memberOf($this->team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    $channel = Alerts::channel($this->team, $owner, AlertChannelKind::Webhook);

    expect(resolve(FallbackWatchdog::class)->for($this->server->refresh())?->toProtocol())->toBe(['role' => 'control_plane']);

    $this->actingAs($this->owner)->patch("/settings/notifications/channels/{$channel->public_id}", ['fallback' => true])->assertForbidden();
    $this->actingAs($owner)->patch("/settings/notifications/channels/{$channel->public_id}", ['fallback' => true])->assertRedirect();

    $spec = resolve(FallbackWatchdog::class)->for($this->server->refresh());
    $protocol = $spec?->toProtocol() ?? [];
    expect($channel->refresh()->fallback)->toBeTrue()
        ->and($protocol['fallback']['kind'] ?? null)->toBe('webhook')
        ->and($protocol['fallback']['serverLabel'] ?? null)->toBe($this->server->name)
        ->and(json_encode($protocol))->not->toContain('hooks.example.com');

    $opened = json_decode(sodium_crypto_box_seal_open(base64_decode((string) $protocol['fallback']['secret']['sealed']), $this->keypair), true);
    expect($opened['purpose'])->toBe('fallback_channel')
        ->and($opened['values'])->toBe(['url' => Alerts::WEBHOOK]);
});

test('production environments keep runtime logs for a day by default', function (): void {
    $production = $this->project->production()->sole();
    $preview = Environment::query()->create([
        'team_id' => $this->team->id, 'project_id' => $this->project->id, 'name' => 'staging', 'slug' => 'staging',
        'kind' => EnvironmentKind::Persistent, 'network_subnet' => '10.210.9.0/24',
    ]);

    expect($production->runtime_log_retention_days)->toBe(Environment::PRODUCTION_LOG_DAYS)
        ->and($production->runtime_log_max_mb)->toBe(Environment::PRODUCTION_LOG_MAX_MB)
        ->and($preview->runtime_log_retention_days)->toBeNull();
});

test('the server page carries the disk and the notices', function (): void {
    resolve(DeployEnvironment::class)->handle($this->project->production()->sole(), null, AuditActor::system());

    $this->actingAs($this->owner)->get("/servers/{$this->server->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('disk')->has('server.notices'));
});

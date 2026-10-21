<?php

declare(strict_types=1);

use App\Actions\Logs\PruneRuntimeLogs;
use App\Actions\Servers\RenderNodeState;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\LogChunk;
use App\Models\Placement;
use Carbon\CarbonImmutable;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
| Runtime log retention (opt-in per environment, docs/08 §12): the setting,
| shipping through the NodeState (only to daemons that announce it) and the
| pruning by period, size cap and the live-tail window.
*/

beforeEach(function (): void {
    Projects::useTestKek();
});

function runtimeChunk(DeploymentUi $world, int $seq, CarbonImmutable $at, int $bytes = 100, bool $runtime = true): LogChunk
{
    $web = $world->latest($world->web);
    $placement = Placement::query()->where('deployment_id', $web->id)->firstOrFail();

    $chunk = LogChunk::query()->create([
        'team_id' => $world->team->id, 'server_id' => $world->server->id, 'deployment_id' => $web->id,
        'source_kind' => $runtime ? LogSourceKind::Workload : LogSourceKind::Deployment,
        'source_id' => $runtime ? $placement->workload_id : $web->public_id,
        'seq' => $seq,
        'lines' => [['time' => $at->toIso8601String(), 'stream' => 'stdout', 'text' => "line {$seq}"]],
        'created_at' => $at,
    ]);
    $chunk->forceFill(['bytes' => $bytes])->save();

    return $chunk;
}

test('runtime log retention is a project setting per environment', function (): void {
    $world = DeploymentUi::make();
    $url = route('projects.log-retention', $world->envArgs());

    $this->actingAs($world->member(TeamRole::Viewer))->get(route('projects.settings', ['project' => $world->project->public_id, 'section' => 'logs']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/settings')
            ->where('logRetention.0.name', 'production')
            ->where('logRetention.0.enabled', false)
            ->where('logRetention.0.canUpdate', false)
            ->where('logRetentionOptions.days', [1, 3, 7, 14, 30]));

    $this->actingAs($world->member(TeamRole::Viewer))->patch($url, ['enabled' => true, 'days' => 7, 'maxMb' => 1024])->assertForbidden();
    $developer = $world->member(TeamRole::Developer);
    $this->actingAs($developer)->patch($url, ['enabled' => true, 'days' => 5, 'maxMb' => 1024])->assertSessionHasErrors('days');
    $this->actingAs($developer)->patch($url, ['enabled' => true, 'days' => 14, 'maxMb' => 5120])->assertRedirect();

    $world->production->refresh();
    expect($world->production->runtime_log_retention_days)->toBe(14)
        ->and($world->production->runtime_log_max_mb)->toBe(5120)
        ->and(AuditEvent::query()->where('action', 'environment.updated')->latest('id')->first()?->meta)->toMatchArray(['runtimeLogs' => 'on', 'runtimeLogDays' => 14, 'runtimeLogMaxMb' => 5120]);

    $this->actingAs($developer)->patch($url, ['enabled' => false, 'days' => 14, 'maxMb' => 5120])->assertRedirect();
    expect($world->production->refresh()->retainsRuntimeLogs())->toBeFalse();

    $outsider = DeploymentUi::make()->member(TeamRole::Owner);
    $this->actingAs($outsider)->patch($url, ['enabled' => true, 'days' => 7, 'maxMb' => 1024])->assertNotFound();
});

test('workloads ship their logs only while retention is on and the daemon can', function (): void {
    $world = DeploymentUi::make();
    $ships = static function () use ($world): array {
        resolve(RenderNodeState::class)->handle($world->server->refresh());

        return array_map(static fn (array $w): bool => $w['shipLogs'] ?? false, Projects::nodeState($world->server)['workloads']);
    };

    expect($ships())->each->toBeFalse();

    $world->production->forceFill(['runtime_log_retention_days' => 7, 'runtime_log_max_mb' => 1024])->save();
    // An older launchroomd would refuse the unknown field.
    expect($ships())->each->toBeFalse();

    $world->server->forceFill(['daemon_features' => ['shipLogs']])->save();
    expect($ships())->not->toBeEmpty()->each->toBeTrue();
});

test('pruning keeps runtime logs for their period and size cap; tails for a day; build logs stay', function (): void {
    $world = DeploymentUi::make();
    $now = CarbonImmutable::now();

    // Retention off: tail-like runtime lines older than 24 h go, build lines stay.
    $oldTail = runtimeChunk($world, 1, $now->subHours(30));
    $recentTail = runtimeChunk($world, 2, $now->subHours(2));
    $oldBuild = runtimeChunk($world, 3, $now->subDays(40), runtime: false);
    resolve(PruneRuntimeLogs::class)->handle($now);
    expect(LogChunk::query()->pluck('id')->all())->toEqualCanonicalizing([$recentTail->id, $oldBuild->id]);
    expect(LogChunk::query()->whereKey($oldTail->id)->exists())->toBeFalse();

    // Retention on: 3 days and 1 MB.
    $world->production->forceFill(['runtime_log_retention_days' => 3, 'runtime_log_max_mb' => 1])->save();
    $kept = runtimeChunk($world, 4, $now->subDays(2), bytes: 400_000);
    $tooOld = runtimeChunk($world, 5, $now->subDays(4));
    $newest = runtimeChunk($world, 6, $now->subMinutes(5), bytes: 600_000);
    $overCap = runtimeChunk($world, 7, $now->subDays(2)->subHour(), bytes: 300_000);

    resolve(PruneRuntimeLogs::class)->handle($now);
    $left = LogChunk::query()->pluck('id')->all();
    expect($left)->toContain($kept->id, $newest->id, $recentTail->id, $oldBuild->id)
        ->and($left)->not->toContain($tooOld->id, $overCap->id);

    // Idempotent.
    expect(resolve(PruneRuntimeLogs::class)->handle($now))->toBe(0);
});

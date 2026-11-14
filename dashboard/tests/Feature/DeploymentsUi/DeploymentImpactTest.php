<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Queries\DeploymentImpact;
use App\Support\PublicId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    CarbonImmutable::setTestNow('2026-10-20 12:00:30');
    $this->world = DeploymentUi::make();
    $this->live = $this->world->latest($this->world->web);
    $this->live->forceFill(['status' => DeploymentStatus::Active, 'commit_sha' => '9f00c2dbbbbbbb', 'finished_at' => CarbonImmutable::parse('2026-10-20 11:30:00')])->save();
    $this->previous = $this->live->replicate(['public_id']);
    $this->previous->forceFill([
        'public_id' => PublicId::generate('dpl'), 'status' => DeploymentStatus::Superseded,
        'commit_sha' => '3b7d5e1aaaaaaa', 'image' => 'registry.local/web@sha256:'.str_repeat('a', 64),
        'queued_at' => CarbonImmutable::parse('2026-10-20 09:00:00'), 'finished_at' => CarbonImmutable::parse('2026-10-20 09:05:00'),
    ])->save();
    $this->instance = ServiceInstance::query()->whereKey($this->live->service_instance_id)->sole();
    $this->instance->forceFill(['active_deployment_id' => $this->live->id])->save();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * One request metric row per minute in [from, to).
 */
function trafficMinutes(ServiceInstance $instance, string $from, string $to, int $requests, int $errors): void
{
    $server = DB::table('servers')->where('team_id', $instance->team_id)->value('id');

    for ($t = CarbonImmutable::parse($from); $t->lessThan(CarbonImmutable::parse($to)); $t = $t->addMinute()) {
        DB::table('request_metric_samples')->insert([
            'server_id' => $server, 'route_id' => 'rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'resolution' => 1, 'at' => $t->toIso8601String(),
            'service_instance_id' => $instance->id, 'project_id' => DB::table('services')->where('id', $instance->service_id)->value('project_id'),
            'requests' => $requests, 'status_5xx' => $errors,
        ]);
    }
}

test('errors that rose after a deploy are compared with the hour before and offer a rollback', function (): void {
    trafficMinutes($this->instance, '2026-10-20 10:30:00', '2026-10-20 11:30:00', 60, 0);
    trafficMinutes($this->instance, '2026-10-20 11:30:00', '2026-10-20 12:00:00', 60, 12);

    $this->actingAs($this->world->member(TeamRole::Developer))
        ->get(route('deployments.show', $this->world->deploymentArgs($this->live)))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('impact.worse', true)
            ->where('impact.minutesAfter', 30)
            ->where('impact.before.errorRatePct', 0)
            ->where('impact.after.errorRatePct', 20)
            ->where('impact.after.requestsPerSecond', 1)
            ->where('impact.rollbackTo.id', $this->previous->public_id)
            ->where('impact.rollbackTo.commitSha', '3b7d5e1aaaaaaa'));
});

test('viewers see the comparison without the rollback, and a calm deploy offers none', function (): void {
    trafficMinutes($this->instance, '2026-10-20 10:30:00', '2026-10-20 12:00:00', 60, 0);

    $impact = resolve(DeploymentImpact::class)->for($this->live);
    expect($impact?->worse)->toBeFalse()
        ->and($impact?->rollbackTo)->toBeNull()
        ->and($impact?->before?->requests)->toBe(3600);

    DB::table('request_metric_samples')->where('at', '>=', '2026-10-20 11:30:00+00')->update(['status_5xx' => 30]);

    $this->actingAs($this->world->member(TeamRole::Viewer))
        ->get(route('deployments.show', $this->world->deploymentArgs($this->live)))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('impact.worse', true)
            ->where('impact.rollbackTo', null));
});

test('no comparison without traffic, for deploys that never went live or went live just now', function (): void {
    expect(resolve(DeploymentImpact::class)->for($this->live))->toBeNull();

    trafficMinutes($this->instance, '2026-10-20 10:30:00', '2026-10-20 12:00:00', 60, 0);
    $this->live->forceFill(['finished_at' => CarbonImmutable::parse('2026-10-20 11:59:30')])->save();
    expect(resolve(DeploymentImpact::class)->for($this->live))->toBeNull();

    $failed = Deployment::query()->whereKey($this->live->id)->sole();
    $failed->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => CarbonImmutable::parse('2026-10-20 11:30:00')])->save();
    expect(resolve(DeploymentImpact::class)->for($failed))->toBeNull();
});

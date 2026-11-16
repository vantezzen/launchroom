<?php

declare(strict_types=1);

use App\Data\Canvas\EnvironmentHealthData;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\DeploymentStatus;
use App\Enums\NodeStatusReason;
use App\Enums\ServerStatus;
use App\Enums\StatusTone;
use App\Enums\TeamRole;
use App\Models\Alerts\Alert;
use App\Models\Deployment;
use App\Models\Security\SecurityFinding;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\EnvironmentStatuses;
use App\Queries\ProjectShell;
use App\Queries\ShellSignals;
use Inertia\DeferProp;
use Inertia\OptionalProp;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

function shellAlert(Team $team, string $subject, string $status = 'firing'): void
{
    Alert::query()->forceCreate([
        'team_id' => $team->id,
        'rule' => AlertRule::BackupFailed->value,
        'subject_key' => $subject,
        'severity' => AlertSeverity::Warning->value,
        'status' => $status,
        'title' => 'Backup failed',
        'summary' => 'The last backup failed.',
        'opened_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function shellFinding(ServiceInstance $instance, string $group, string $name, string $level = 'action_needed'): void
{
    SecurityFinding::query()->forceCreate([
        'team_id' => $instance->team_id,
        'project_id' => $instance->environment->project_id,
        'environment_id' => $instance->environment_id,
        'service_instance_id' => $instance->id,
        'group_key' => $group,
        'ecosystem' => 'npm',
        'name' => $name,
        'version' => '1.0.0',
        'kind' => 'library',
        'scope' => 'runtime',
        'presence' => 'loaded',
        'level' => $level,
        'status' => 'open',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

test('the Activity count is the team’s firing alerts', function (): void {
    $world = DeploymentUi::make(deploy: false);
    shellAlert($world->team, 'backup:a');
    shellAlert($world->team, 'backup:b');
    shellAlert($world->team, 'backup:c', 'resolved');
    shellAlert(Team::factory()->create(), 'backup:a');

    expect(resolve(ShellSignals::class)->openAlerts($world->member(TeamRole::Viewer), $world->team))->toBe(2)
        ->and(resolve(ShellSignals::class)->openAlerts(null, null))->toBe(0);
});

test('the Security badge counts advisory groups, not findings', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $web = ServiceInstance::query()->where('service_id', $world->web->id)->sole();
    $postgres = ServiceInstance::query()->where('service_id', $world->postgres->id)->sole();
    shellFinding($web, 'GHSA-one', 'lodash');
    shellFinding($postgres, 'GHSA-one', 'lodash');
    shellFinding($web, 'GHSA-one', 'lodash-es');
    shellFinding($web, 'GHSA-two', 'express');
    shellFinding($web, 'GHSA-three', 'qs', 'worth_knowing');

    expect(resolve(ShellSignals::class)->securityActionNeeded($world->member(TeamRole::Viewer), $world->team))->toBe(2);
});

test('the team line says how the servers are', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $viewer = $world->member(TeamRole::Viewer);
    $signals = resolve(ShellSignals::class);

    expect($signals->fleet($viewer, $world->team))
        ->state->toBe(StatusTone::Active)
        ->label->toBe('1 server online')
        ->total->toBe(1)
        ->online->toBe(1);

    Server::query()->whereKey($world->server->id)->update(['status' => ServerStatus::Offline->value]);

    expect($signals->fleet($viewer, $world->team))
        ->state->toBe(StatusTone::Failed)
        ->label->toBe('1 server down');

    $emptyTeam = Team::factory()->create();
    $empty = memberOf($emptyTeam, TeamRole::Owner);

    expect($signals->fleet($empty, $emptyTeam))->label->toBe('No servers yet')
        ->and($signals->fleet(null, null))->toBeNull();
});

test('the shared props carry the shell signals', function (): void {
    $world = DeploymentUi::make(deploy: false);
    shellAlert($world->team, 'backup:a');

    $this->actingAs($world->member(TeamRole::Viewer))
        ->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page
            ->where('openAlertCount', 1)
            ->where('securityActionNeeded', 0)
            ->where('fleet.label', '1 server online')
            ->etc());
});

test('the top bar knows when a deploy runs or failed in the open environment', function (): void {
    $world = DeploymentUi::make();
    $statuses = resolve(EnvironmentStatuses::class);

    $health = $statuses->health($world->production);
    expect($health->status->state)->not->toBe(StatusTone::Failed)
        ->and($health->deployFailed)->toBeFalse();

    $deployment = $world->latest($world->web);
    Deployment::query()->whereKey($deployment->id)->update(['status' => DeploymentStatus::Failed->value]);
    ServiceInstance::query()->whereKey($deployment->service_instance_id)->update(['active_deployment_id' => null]);

    $failed = $statuses->health($world->production);
    expect($failed->deployFailed)->toBeTrue()
        ->and($failed->status->reason)->toBe(NodeStatusReason::ServicesFailed)
        ->and($statuses->of([$world->production]))->sequence(
            fn ($entry) => $entry->environmentId->toBe($world->production->public_id),
        );

    $this->actingAs($world->member(TeamRole::Viewer));
    $props = ProjectShell::props($world->project, $world->production);

    expect($props['environmentHealth'])->toBeInstanceOf(DeferProp::class)
        ->and($props['environmentHealth']())->toBeInstanceOf(EnvironmentHealthData::class)
        ->and($props['environmentStatuses'])->toBeInstanceOf(OptionalProp::class);
});

<?php

declare(strict_types=1);

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Data\Alerts\AlertSignal;
use App\Data\Alerts\OpenAlertData;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\TeamRole;
use App\Events\CanvasChanged;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\OpenAlerts;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Alerts;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Alerts::fakeDns();
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    [$this->server] = Projects::server($this->team);
    $this->project = Projects::create($this->team, 'Shop');
    Projects::image($this->project, 'web');
    $this->environment = $this->project->production()->sole();
    $this->instance = ServiceInstance::query()->whereHas('environment', fn (Builder $q) => $q->where('project_id', $this->project->id))->sole();
});

function raiseFor(Team $team, AlertRule $rule, string $subject, ?ServiceInstance $instance = null, ?Server $server = null, array $facts = [], AlertSeverity $severity = AlertSeverity::Warning): void
{
    resolve(RaiseAlert::class)->handle(new AlertSignal(
        team: $team,
        rule: $rule,
        subject: $subject,
        title: 'Something is wrong',
        summary: 'It is.',
        severity: $severity,
        serviceInstance: $instance,
        server: $server,
        facts: $facts,
    ));
}

test('open alerts are grouped by service, server and project, most severe first, with a short label', function (): void {
    raiseFor($this->team, AlertRule::ErrorRateHigh, 'service:'.$this->instance->public_id, $this->instance, facts: ['Errors' => '12 of 150 requests']);
    raiseFor($this->team, AlertRule::BackupFailed, 'backup:'.$this->instance->public_id, $this->instance, severity: AlertSeverity::Critical);
    raiseFor($this->team, AlertRule::DiskFull, 'server:'.$this->server->public_id, server: $this->server, facts: ['Disk' => '93% full']);

    $alerts = new OpenAlerts;
    $byService = $alerts->forEnvironment($this->team->id, $this->environment->id);
    $byServer = $alerts->forServers($this->team->id, [$this->server->id]);
    $byProject = $alerts->forProjects($this->team->id, [$this->project->id]);

    expect(array_map(fn (OpenAlertData $a): string => $a->label, $byService[$this->instance->id]))->toBe(['Backup failed', 'Errors 8%'])
        ->and($byServer[$this->server->id][0]->label)->toBe('Disk 93%')
        ->and($byProject[$this->project->id])->toHaveCount(2);
});

test('another team\'s alerts never show', function (): void {
    $other = Team::factory()->create();
    [$theirs] = Projects::server($other);
    raiseFor($other, AlertRule::DiskFull, 'server:'.$theirs->public_id, server: $theirs, facts: ['Disk' => '93% full']);

    $alerts = new OpenAlerts;

    expect($alerts->forServers($this->team->id, [$theirs->id]))->toBe([])
        ->and($alerts->forServers($other->id, [$theirs->id]))->toHaveKey($theirs->id)
        ->and($alerts->forEnvironment($this->team->id, $this->environment->id))->toBe([]);
});

test('the canvas node and the server pages carry the open alerts; resolving clears them', function (): void {
    raiseFor($this->team, AlertRule::ServiceUnhealthy, 'service:'.$this->instance->public_id, $this->instance);
    raiseFor($this->team, AlertRule::CpuHigh, 'server:'.$this->server->public_id, server: $this->server, facts: ['CPU' => '94%']);

    $this->actingAs($this->owner)
        ->get(route('projects.canvas', [$this->project->public_id, $this->environment->public_id]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('nodes.0.openAlerts.0.label', 'Unhealthy')
            ->where('nodes.0.openAlerts.0.rule', 'service_unhealthy'));

    $this->actingAs($this->owner)->get(route('servers.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('servers.0.openAlerts.0.label', 'CPU 94%'));
    $this->actingAs($this->owner)->get(route('servers.show', $this->server->public_id))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('server.openAlerts.0.label', 'CPU 94%'));

    resolve(ResolveAlert::class)->handle($this->team, AlertRule::ServiceUnhealthy, 'service:'.$this->instance->public_id);

    $this->actingAs($this->owner)
        ->get(route('projects.canvas', [$this->project->public_id, $this->environment->public_id]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('nodes.0.openAlerts', []));
});

test('opening and resolving an alert about a service updates its canvas live', function (): void {
    Event::fake([CanvasChanged::class]);

    raiseFor($this->team, AlertRule::ServiceUnhealthy, 'service:'.$this->instance->public_id, $this->instance);
    Event::assertDispatched(CanvasChanged::class, fn (CanvasChanged $event): bool => $event->environmentId === $this->environment->public_id);

    // Refreshing a firing alert every minute broadcasts nothing.
    Event::fake([CanvasChanged::class]);
    raiseFor($this->team, AlertRule::ServiceUnhealthy, 'service:'.$this->instance->public_id, $this->instance);
    Event::assertNotDispatched(CanvasChanged::class);

    resolve(ResolveAlert::class)->handle($this->team, AlertRule::ServiceUnhealthy, 'service:'.$this->instance->public_id);
    Event::assertDispatched(CanvasChanged::class);
});

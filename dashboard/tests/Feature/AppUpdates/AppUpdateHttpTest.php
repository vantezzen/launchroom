<?php

declare(strict_types=1);

use App\Actions\AppUpdates\CheckAppUpdates;
use App\Enums\AppUpdates\AutoUpdateMode;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AppUpdates\AppUpdate;
use App\Models\AuditEvent;
use App\Models\ServiceInstance;
use App\Models\Team;
use Tests\Support\AppUpdates;
use Tests\Support\Backups;
use Tests\Support\Projects;

/*
 * The update endpoints (D106): who may update, set automatic updates and
 * check now; the panel and the Security page's App updates list.
 */

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    AppUpdates::fakeDns();
    AppUpdates::fakeHub(['v6.2.0', 'v6.3.0']);
});

/**
 * @return array{project: string, environment: string, service: string}
 */
function updateRoute(ServiceInstance $instance): array
{
    $instance->loadMissing(['service.project', 'environment']);

    return ['project' => $instance->service->project->public_id, 'environment' => $instance->environment->public_id, 'service' => $instance->service->public_id];
}

test('people who change and deploy services may update; viewers may not', function (TeamRole $role, bool $allowed): void {
    [$team, , $instance] = AppUpdates::listmonk();
    resolve(CheckAppUpdates::class)->handle($instance->id);
    $params = updateRoute($instance);

    $response = $this->actingAs(memberOf($team, $role))->post(route('projects.services.update.store', $params), ['tag' => 'v6.3.0']);

    if ($allowed) {
        $response->assertRedirect();
        expect(AppUpdate::query()->sole()->to_version)->toBe('v6.3.0')
            ->and(AuditEvent::query()->where('action', AuditAction::AppUpdateStarted->value)->exists())->toBeTrue();
    } else {
        $response->assertForbidden();
        expect(AppUpdate::query()->exists())->toBeFalse();
    }
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'developer' => [TeamRole::Developer, true],
    'viewer' => [TeamRole::Viewer, false],
]);

test('another team’s service is not found', function (): void {
    [, , $instance] = AppUpdates::listmonk();
    $params = updateRoute($instance);
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($stranger)->post(route('projects.services.update.store', $params), ['tag' => 'v6.3.0'])->assertNotFound();
    $this->actingAs($stranger)->put(route('projects.services.update.auto', $params), ['mode' => 'patch'])->assertNotFound();
    $this->actingAs($stranger)->post(route('projects.services.update.check', $params))->assertNotFound();
});

test('automatic updates are saved at once and audited', function (): void {
    [$team, , $instance] = AppUpdates::listmonk();
    $params = updateRoute($instance);

    $this->actingAs(memberOf($team, TeamRole::Admin))
        ->put(route('projects.services.update.auto', $params), ['mode' => 'minor', 'window' => ['days' => [6, 7], 'start' => '02:00', 'hours' => 4, 'timezone' => 'Europe/Berlin']])
        ->assertRedirect();

    $instance->refresh();
    expect($instance->auto_update)->toBe(AutoUpdateMode::Minor)
        ->and($instance->auto_update_window?->days)->toBe([6, 7])
        ->and($instance->auto_update_window?->timezone)->toBe('Europe/Berlin')
        ->and(AuditEvent::query()->where('action', AuditAction::AutoUpdateChanged->value)->exists())->toBeTrue();
});

test('a window with a made-up time zone is refused', function (): void {
    [$team, , $instance] = AppUpdates::listmonk();
    $params = updateRoute($instance);

    $this->actingAs(memberOf($team, TeamRole::Owner))
        ->put(route('projects.services.update.auto', $params), ['mode' => 'patch', 'window' => ['days' => [1], 'start' => '02:00', 'hours' => 4, 'timezone' => 'Mars/Olympus']])
        ->assertSessionHasErrors('window.timezone');
});

test('a service with data needs backup storage for automatic updates', function (): void {
    [$team, , $instance] = AppUpdates::listmonk(withDatabase: true);
    $params = updateRoute($instance);

    $this->actingAs(memberOf($team, TeamRole::Owner))
        ->put(route('projects.services.update.auto', $params), ['mode' => 'patch'])
        ->assertSessionHasErrors('mode');
});

test('check now queues one check per few minutes', function (): void {
    [$team, , $instance] = AppUpdates::listmonk();
    $params = updateRoute($instance);
    $owner = memberOf($team, TeamRole::Owner);

    $this->actingAs($owner)->post(route('projects.services.update.check', $params))->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(route('projects.services.update.check', $params))->assertSessionHasErrors('service');
});

test('the panel shows what is available and the Projects page lists it', function (): void {
    [$team, , $instance] = AppUpdates::listmonk();
    resolve(CheckAppUpdates::class)->handle($instance->id);
    $params = updateRoute($instance);
    $viewer = memberOf($team, TeamRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('projects.services.show', [...$params, 'tab' => 'deployments']))
        ->assertInertia(fn ($page) => $page
            ->where('panel.update.recommended.version', 'v6.3.0')
            ->where('panel.update.running', 'v6.2.0')
            ->where('panel.update.moving', false)
            ->where('panel.update.canUpdate', false));

    $this->actingAs($viewer)->getJson(route('app-updates.index'))
        ->assertOk()
        ->assertJsonPath('0.serviceName', 'listmonk')
        ->assertJsonPath('0.recommended.kind', 'minor')
        ->assertJsonPath('0.standsOut', true);

    $this->actingAs($viewer)->getJson(route('app-updates.index', ['project' => $params['project']]))->assertOk()->assertJsonCount(1);
    $this->actingAs($viewer)->getJson(route('app-updates.index', ['project' => 'prj_01JB7Z6X9Q3T8M5N2P4R6S8V0W']))->assertNotFound();

    $this->actingAs(memberOf(Team::factory()->create(), TeamRole::Owner))->getJson(route('app-updates.index'))->assertOk()->assertExactJson([]);
});

test('an app more than a year behind shows in Needs you', function (): void {
    [$team, , $instance] = AppUpdates::listmonk();
    resolve(CheckAppUpdates::class)->handle($instance->id);

    $this->actingAs(memberOf($team, TeamRole::Viewer))
        ->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload
            ->where('attention', fn ($items): bool => collect($items)->contains(static fn (array $item): bool => $item['kind'] === 'update'
                && str_starts_with($item['title'], 'listmonk runs v6.2.0, published')
                && $item['detail'] === 'v6.3.0 is available.'))));
});

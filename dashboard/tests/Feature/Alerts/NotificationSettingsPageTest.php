<?php

declare(strict_types=1);

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\TeamRole;
use App\Models\Team;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Alerts;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->raise = resolve(RaiseAlert::class);
});

test('the notifications page counts open alerts, shows the two most severe and when each rule last fired', function (): void {
    $this->raise->handle(Alerts::signal($this->team, subject: 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0A', severity: AlertSeverity::Warning));
    $this->raise->handle(Alerts::signal($this->team, subject: 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0B', severity: AlertSeverity::Critical));
    $this->raise->handle(Alerts::signal($this->team, subject: 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0C', severity: AlertSeverity::Warning));
    // Another team's alert never counts.
    $this->raise->handle(Alerts::signal(Team::factory()->create(), subject: 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0D'));

    $this->actingAs($this->admin)->get(route('notifications.edit'))->assertOk()
        ->assertInertia(function (AssertableInertia $page): AssertableInertia {
            $page->where('openAlertCount', 3)
                ->has('openAlerts', 2)
                ->where('openAlerts.0.severity', 'critical');

            $rules = collect($page->toArray()['props']['rules'])->keyBy('rule');
            expect($rules[AlertRule::BackupFailed->value]['lastFiredTitle'])->toBe('Backup of postgres failed')
                ->and($rules[AlertRule::BackupFailed->value]['lastFiredAt'])->not->toBeNull()
                ->and($rules->where('rule', '!=', AlertRule::BackupFailed->value)->pluck('lastFiredAt')->filter()->all())->toBe([]);

            return $page;
        });
});

test('activity lists only open alerts with state=open', function (): void {
    $this->raise->handle(Alerts::signal($this->team, subject: 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0A'));
    $this->raise->handle(Alerts::signal($this->team, subject: 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0B'));
    resolve(ResolveAlert::class)->handle($this->team, AlertRule::BackupFailed, 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0B');

    $this->actingAs($this->admin)->get(route('activity.index', ['kind' => 'alerts']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('entries', 2));

    $this->actingAs($this->admin)->get(route('activity.index', ['kind' => 'alerts', 'state' => 'open']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('entries', 1)
            ->where('entries.0.status', 'firing')
            ->where('filters.state', 'open'));
});

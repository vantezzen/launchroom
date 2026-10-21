<?php

declare(strict_types=1);

use App\Actions\Alerts\DeliverAlertNotification;
use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertDeliveryEvent;
use App\Enums\Alerts\AlertDeliveryStatus;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
use App\Enums\TeamRole;
use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertDelivery;
use App\Models\Alerts\AlertRuleSetting;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Alerts;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Alerts::fakeDns();
    $this->slackStatus = 200;
    Http::fake(['hooks.slack.com/*' => fn () => Http::response($this->slackStatus === 200 ? 'ok' : 'no_service', $this->slackStatus)]);
    CarbonImmutable::setTestNow('2026-10-20 12:00:00');
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->channel = Alerts::channel($this->team, $this->owner);
    $this->raise = resolve(RaiseAlert::class);
    $this->resolve = resolve(ResolveAlert::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return list<string> the events delivered so far, in order
 */
function deliveredEvents(): array
{
    return AlertDelivery::query()->orderBy('id')->get()->map(static fn (AlertDelivery $d): string => $d->event->value.':'.$d->status->value)->all();
}

test('raising opens one alert and tells every channel once; raising again only refreshes it', function (): void {
    $first = $this->raise->handle(Alerts::signal($this->team));
    $again = $this->raise->handle(Alerts::signal($this->team, facts: ['Destination' => 'r2']));

    expect($first)->toBeInstanceOf(Alert::class)
        ->and($again?->id)->toBe($first?->id)
        ->and($again?->occurrences)->toBe(2)
        ->and($again?->facts)->toBe(['Destination' => 'r2'])
        ->and($again?->status)->toBe(AlertStatus::Firing)
        ->and($again?->severity)->toBe(AlertSeverity::Critical) // the rule's default
        ->and(Alert::query()->count())->toBe(1)
        ->and(deliveredEvents())->toBe(['fired:sent']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains((string) json_encode($request->data()), 'Backup of postgres failed'));
    expect($this->channel->refresh()->last_delivery_ok)->toBeTrue();
});

test('a rule the team switched off raises nothing', function (): void {
    AlertRuleSetting::query()->create(['team_id' => $this->team->id, 'rule' => AlertRule::BackupFailed, 'enabled' => false]);

    expect($this->raise->handle(Alerts::signal($this->team)))->toBeNull()
        ->and(Alert::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a new occurrence repeats the notification only after the rule\'s repeat window', function (): void {
    $signal = fn (string $occurrence): AlertSignal => new AlertSignal(
        team: $this->team, rule: AlertRule::DeployFailed, subject: 'service:svc_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
        title: 'web failed to deploy to production', summary: 'Build failed.', occurrence: $occurrence,
    );

    $this->raise->handle($signal('dpl_1'));
    CarbonImmutable::setTestNow('2026-10-20 12:05:00');
    $this->raise->handle($signal('dpl_2'));     // inside 10 minutes: quiet
    $this->raise->handle($signal('dpl_2'));
    CarbonImmutable::setTestNow('2026-10-20 12:11:00');
    $this->raise->handle($signal('dpl_2'));     // same occurrence: quiet
    $this->raise->handle($signal('dpl_3'));     // new one after the window: again

    expect(deliveredEvents())->toBe(['fired:sent', 'repeated:sent'])
        ->and(Alert::query()->sole()->occurrences)->toBe(5);
});

test('an alert that escalates from warning to critical notifies again', function (): void {
    $this->raise->handle(Alerts::signal($this->team, AlertRule::DiskFull, 'server:srv_01JB7Z6X9Q3T8M5N2P4R6S8V0W', severity: AlertSeverity::Warning));
    $this->raise->handle(Alerts::signal($this->team, AlertRule::DiskFull, 'server:srv_01JB7Z6X9Q3T8M5N2P4R6S8V0W', severity: AlertSeverity::Warning));
    $this->raise->handle(Alerts::signal($this->team, AlertRule::DiskFull, 'server:srv_01JB7Z6X9Q3T8M5N2P4R6S8V0W', severity: AlertSeverity::Critical));

    expect(deliveredEvents())->toBe(['fired:sent', 'repeated:sent']);
});

test('resolving tells the channels that heard about it; resolving twice is a no-op', function (): void {
    $this->raise->handle(Alerts::signal($this->team));

    $resolved = $this->resolve->handle($this->team, AlertRule::BackupFailed, 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'The next backup succeeded.');

    expect($resolved?->status)->toBe(AlertStatus::Resolved)
        ->and($resolved?->resolve_reason)->toBe('The next backup succeeded.')
        ->and($this->resolve->handle($this->team->id, AlertRule::BackupFailed, 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0W'))->toBeNull()
        ->and(deliveredEvents())->toBe(['fired:sent', 'resolved:sent']);

    Http::assertSent(fn (Request $request): bool => str_contains((string) json_encode($request->data()), 'Resolved: Backup of postgres failed'));
});

test('a problem back within the cooldown reopens the same alert without paging again', function (): void {
    $alert = $this->raise->handle(Alerts::signal($this->team));
    CarbonImmutable::setTestNow('2026-10-20 12:03:00');
    $this->resolve->handle($this->team, AlertRule::BackupFailed, 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0W');
    CarbonImmutable::setTestNow('2026-10-20 12:06:00');
    $reopened = $this->raise->handle(Alerts::signal($this->team));

    expect($reopened?->id)->toBe($alert?->id)
        ->and($reopened?->status)->toBe(AlertStatus::Firing)
        ->and($reopened?->resolved_at)->toBeNull()
        ->and(deliveredEvents())->toBe(['fired:sent', 'resolved:sent']);

    // Long after: a fresh alert, notified again.
    $this->resolve->handle($this->team, AlertRule::BackupFailed, 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0W');
    CarbonImmutable::setTestNow('2026-10-20 13:00:00');
    $fresh = $this->raise->handle(Alerts::signal($this->team));

    expect($fresh?->id)->not->toBe($alert?->id)
        ->and(Alert::query()->count())->toBe(2)
        ->and(deliveredEvents())->toBe(['fired:sent', 'resolved:sent', 'resolved:sent', 'fired:sent']);
});

test('alerts nobody was told about resolve silently, and channels filter by severity', function (): void {
    $this->channel->forceFill(['min_severity' => AlertSeverity::Critical])->save();

    $this->raise->handle(Alerts::signal($this->team, AlertRule::CpuHigh, 'server:srv_01JB7Z6X9Q3T8M5N2P4R6S8V0W'));
    $alert = $this->resolve->handle($this->team, AlertRule::CpuHigh, 'server:srv_01JB7Z6X9Q3T8M5N2P4R6S8V0W');

    expect($alert?->notified_at)->toBeNull()
        ->and(AlertDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a failing channel is recorded and retried, and never fails the alert', function (): void {
    $this->slackStatus = 404;

    $alert = $this->raise->handle(Alerts::signal($this->team));
    $delivery = AlertDelivery::query()->sole();

    // The sync queue runs the first attempt; the job released itself for a retry.
    expect($alert?->status)->toBe(AlertStatus::Firing)
        ->and($delivery->status)->toBe(AlertDeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->error)->toBe('The address answered 404: the webhook was probably deleted.')
        ->and($delivery->error)->not->toContain('hooks.slack.com/services');

    // The last attempt marks it failed and shows the reason on the channel.
    resolve(DeliverAlertNotification::class)->handle($delivery->id, final: true);

    expect($delivery->refresh()->status)->toBe(AlertDeliveryStatus::Failed)
        ->and($delivery->event)->toBe(AlertDeliveryEvent::Fired)
        ->and($this->channel->refresh()->last_delivery_ok)->toBeFalse()
        ->and($this->channel->last_error)->toContain('404');
});

test('signals validate their subject, text and context', function (): void {
    expect(fn (): AlertSignal => Alerts::signal($this->team, subject: 'not a key'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): AlertSignal => Alerts::signal($this->team, facts: array_fill_keys(range('a', 'i'), 'x')))->toThrow(InvalidArgumentException::class)
        ->and(fn (): AlertSignal => new AlertSignal(team: $this->team, rule: AlertRule::BackupFailed, subject: 'backup:x', title: '', summary: ''))->toThrow(InvalidArgumentException::class);

    [$server] = Projects::server(Team::factory()->create());

    expect(fn (): AlertSignal => new AlertSignal(team: $this->team, rule: AlertRule::ServerOffline, subject: 'server:x', title: 'x', summary: 'y', server: $server))
        ->toThrow(InvalidArgumentException::class);
});

test('only one alert can fire per subject, even under a race', function (): void {
    $this->raise->handle(Alerts::signal($this->team));

    expect(fn () => Alert::query()->create([
        'team_id' => $this->team->id, 'rule' => AlertRule::BackupFailed, 'subject_key' => 'backup:bks_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
        'severity' => AlertSeverity::Critical, 'title' => 'x', 'summary' => 'y', 'opened_at' => now(), 'last_seen_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

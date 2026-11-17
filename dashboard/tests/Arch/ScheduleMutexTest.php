<?php

declare(strict_types=1);

use App\Services\Scheduling\ScheduleLocks;
use App\Services\Scheduling\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

// Reads the schedule routes/console.php defines, so it needs the app (no database).
uses(TestCase::class);

/**
 * @return list<Event>
 */
function scheduledEvents(): array
{
    resolve(Kernel::class)->bootstrap();

    return array_values(resolve(Schedule::class)->events());
}

test('every scheduled event runs without overlapping, with a lock that expires within hours, not a day', function (): void {
    $events = scheduledEvents();

    expect(count($events))->toBeGreaterThan(30);

    foreach ($events as $event) {
        $name = ScheduleLocks::name($event);

        expect($event->withoutOverlapping)->toBeTrue("{$name} runs without withoutOverlapping()")
            // Laravel's default lock lives 24 h: a scheduler killed mid-run
            // would keep the command from running for a day.
            ->and($event->expiresAt)->toBeLessThanOrEqual(360, "{$name} keeps its lock for {$event->expiresAt} minutes");
    }
});

test('the heartbeat is scheduled every minute and is not paused by an instance freeze', function (): void {
    $heartbeat = collect(scheduledEvents())->first(static fn (Event $event): bool => $event->description === SchedulerHeartbeat::EVENT);

    expect($heartbeat)->toBeInstanceOf(Event::class)
        ->and($heartbeat?->expression)->toBe('* * * * *');
});

test('the heartbeat reports locks held far longer than their command runs', function (): void {
    $events = scheduledEvents();
    $sweep = collect($events)->first(static fn (Event $event): bool => ScheduleLocks::name($event) === 'launchroom:deployments:sweep');
    $tick = collect($events)->first(static fn (Event $event): bool => ScheduleLocks::name($event) === 'launchroom:backups:tick');

    expect($sweep)->toBeInstanceOf(Event::class)->and($tick)->toBeInstanceOf(Event::class);

    // The sweep started 20 minutes ago and still holds its lock (the
    // scheduler died mid-run); the tick started a minute ago and is running.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subMinutes(20));
    resolve(ScheduleLocks::class)->started($sweep);
    $sweep->mutex->create($sweep);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(19));
    resolve(ScheduleLocks::class)->started($tick);
    $tick->mutex->create($tick);
    CarbonImmutable::setTestNow();

    $heartbeat = resolve(SchedulerHeartbeat::class);
    $heartbeat->beat(resolve(Schedule::class));

    expect($heartbeat->last()['staleLocks'] ?? null)->toBe(['launchroom:deployments:sweep'])
        ->and($heartbeat->alive())->toBeTrue()
        ->and($heartbeat->alive(CarbonImmutable::now()->addMinutes(4)))->toBeFalse();

    $sweep->mutex->forget($sweep);
    $tick->mutex->forget($tick);
});

<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The schedule's overlap locks (routes/console.php): every event records
 * when it started, so a lock left behind by a scheduler that was killed
 * mid-run can be told apart from a command that is still running. Each lock
 * also expires on its own (`withoutOverlapping($minutes)`); `./launchroom
 * doctor --fix clear-schedule-locks` (`schedule:clear-cache`) clears them
 * at once.
 */
final readonly class ScheduleLocks
{
    private const string STARTED = 'launchroom:schedule:started:';

    /** A lock is reported once it has been held this long and half its expiry. */
    private const int MIN_STALE_MINUTES = 5;

    public function __construct(
        private Cache $cache,
    ) {}

    public function started(Event $event): void
    {
        $this->cache->put($this->key($event), CarbonImmutable::now()->getTimestamp(), 2 * 86_400);
    }

    /**
     * Events whose lock is held for longer than they plausibly run.
     *
     * @param  iterable<Event>  $events
     * @return list<string> their names (the command or the event's name)
     */
    public function stale(iterable $events, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stale = [];

        foreach ($events as $event) {
            if (! $event->withoutOverlapping || ! $event->mutex->exists($event)) {
                continue;
            }

            $started = $this->cache->get($this->key($event));

            if (! is_int($started)) {
                continue;
            }

            $limit = max(self::MIN_STALE_MINUTES, intdiv($event->expiresAt, 2));

            if ($started < $now->subMinutes($limit)->getTimestamp()) {
                $stale[] = self::name($event);
            }
        }

        return $stale;
    }

    public static function name(Event $event): string
    {
        $summary = $event->getSummaryForDisplay();

        // Commands display as `'/usr/bin/php' 'artisan' launchroom:…`: keep the command.
        if (preg_match('/(launchroom:[a-z0-9:-]+)/', $summary, $match) === 1) {
            return $match[1];
        }

        return mb_substr($summary, 0, 80);
    }

    private function key(Event $event): string
    {
        return self::STARTED.hash('sha256', $event->mutexName());
    }
}

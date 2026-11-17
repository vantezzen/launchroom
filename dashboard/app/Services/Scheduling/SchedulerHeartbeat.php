<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * The scheduler's heartbeat: written every minute by the schedule itself
 * (routes/console.php), together with the locks that look stale
 * ({@see ScheduleLocks}), so pages and checks outside the scheduler can read
 * both. `launchroom:scheduler:alive` (the scheduler container's healthcheck)
 * fails once the beat is older than {@see ALIVE_SECONDS}; launchroomd's
 * watchdog then restarts the scheduler.
 */
final readonly class SchedulerHeartbeat
{
    public const string EVENT = 'launchroom:scheduler:heartbeat';

    public const int ALIVE_SECONDS = 180;

    private const string KEY = 'launchroom:scheduler:heartbeat';

    public function __construct(
        private Cache $cache,
        private ScheduleLocks $locks,
    ) {}

    public function beat(Schedule $schedule): void
    {
        $this->cache->forever(self::KEY, [
            'at' => CarbonImmutable::now()->getTimestamp(),
            'staleLocks' => $this->locks->stale($schedule->events()),
        ]);
    }

    /**
     * The last beat, or null when there is none (or the cache can't be read).
     *
     * @return array{at: CarbonImmutable, staleLocks: list<string>}|null
     */
    public function last(): ?array
    {
        try {
            $beat = $this->cache->get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($beat) || ! is_int($beat['at'] ?? null)) {
            return null;
        }

        $stale = is_array($beat['staleLocks'] ?? null) ? array_values(array_filter($beat['staleLocks'], is_string(...))) : [];

        return ['at' => CarbonImmutable::createFromTimestamp($beat['at']), 'staleLocks' => $stale];
    }

    public function alive(?CarbonImmutable $now = null): bool
    {
        $beat = $this->last();

        return $beat !== null && $beat['at']->greaterThan(($now ?? CarbonImmutable::now())->subSeconds(self::ALIVE_SECONDS));
    }
}

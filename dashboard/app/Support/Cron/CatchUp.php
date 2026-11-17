<?php

declare(strict_types=1);

namespace App\Support\Cron;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeInterface;

/**
 * Catch-up for schedules the dashboard runs itself (backups, base backups):
 * a schedule owes a run when its latest cron slot at or before now is later
 * than its last run (or than the moment it started counting, when it never
 * ran). A tick that comes late (scheduler down, server off, a deploy of the
 * control plane) still runs a slot it missed, and after any downtime a
 * schedule owes at most one run: the latest slot, never one per missed slot.
 *
 * Cron in UTC; slots are whole minutes.
 */
final class CatchUp
{
    /**
     * The slot owed at $now, or null when nothing is owed (or the
     * expression is invalid).
     */
    public static function owed(string $cron, DateTimeInterface $since, DateTimeInterface $now): ?CarbonImmutable
    {
        if (! CronExpression::isValidExpression($cron)) {
            return null;
        }

        $slot = CarbonImmutable::instance(new CronExpression($cron)->getPreviousRunDate(CarbonImmutable::instance($now)->utc(), 0, true, 'UTC'))->utc();

        return $slot->greaterThan(CarbonImmutable::instance($since)) ? $slot : null;
    }
}

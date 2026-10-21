<?php

declare(strict_types=1);

use App\Support\Backups\CronDescription;

test('schedules the picker writes read as sentences', function (string $cron, ?string $sentence): void {
    expect(CronDescription::describe($cron))->toBe($sentence);
})->with([
    'daily' => ['7 6 * * *', 'Daily at 06:07 UTC'],
    'every 6 hours' => ['28 */6 * * *', 'Every 6 hours'],
    'hourly' => ['0 * * * *', 'Every hour'],
    'hourly at a minute' => ['15 * * * *', 'Every hour at :15'],
    'weekly' => ['30 3 * * 1', 'Weekly on Monday at 03:30 UTC'],
    'weekly sunday as 7' => ['0 4 * * 7', 'Weekly on Sunday at 04:00 UTC'],
    'monthly' => ['0 2 1 * *', 'Monthly on day 1 at 02:00 UTC'],
    'ranges are custom' => ['0 3 * * 1-5', null],
    'lists are custom' => ['0,30 * * * *', null],
    'months are custom' => ['0 3 1 6 *', null],
    'garbage' => ['not cron', null],
]);

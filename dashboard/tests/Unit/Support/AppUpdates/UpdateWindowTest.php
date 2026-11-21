<?php

declare(strict_types=1);

use App\Data\AppUpdates\UpdateWindowData;
use Carbon\CarbonImmutable;

/*
 * Maintenance windows (docs/04 §14.3): days, start and length in the
 * window's own time zone; a late window runs past midnight.
 */

test('a window contains the hours after its start on its days', function (): void {
    $window = new UpdateWindowData([1, 2, 3, 4, 5], '03:00', 3, 'Europe/Berlin');

    // Monday 2026-11-23, 03:30 in Berlin is 02:30 UTC.
    expect($window->contains(CarbonImmutable::parse('2026-11-23 02:30:00', 'UTC')))->toBeTrue()
        ->and($window->contains(CarbonImmutable::parse('2026-11-23 05:30:00', 'UTC')))->toBeFalse()
        // Saturday is not a window day.
        ->and($window->contains(CarbonImmutable::parse('2026-11-28 02:30:00', 'UTC')))->toBeFalse();
});

test('a window that starts late belongs to the day it starts on', function (): void {
    $window = new UpdateWindowData([5], '23:00', 3, 'UTC');

    // Friday 23:00 to Saturday 02:00.
    expect($window->contains(CarbonImmutable::parse('2026-11-28 01:30:00', 'UTC')))->toBeTrue()
        ->and($window->contains(CarbonImmutable::parse('2026-11-29 01:30:00', 'UTC')))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Enums\AnalyticsRange;
use App\Enums\LogRange;
use App\Enums\MetricRange;

$lengths = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/range-lengths.json'), true, flags: JSON_THROW_ON_ERROR);

it('gives every range case the length the shared vectors state', function (LogRange|AnalyticsRange|MetricRange $range) use ($lengths): void {
    expect($lengths)->toHaveKey($range->value)
        ->and($range->seconds())->toBe($lengths[$range->value]);
})->with([...LogRange::cases(), ...AnalyticsRange::cases(), ...MetricRange::cases()]);

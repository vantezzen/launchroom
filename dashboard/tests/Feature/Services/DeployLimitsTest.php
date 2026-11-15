<?php

declare(strict_types=1);

use App\Data\Forms\NumberRangeData;
use App\Data\Services\DeployLimitsData;
use App\Data\Services\DeploySettings;
use App\Data\Services\HealthcheckSettings;
use Illuminate\Validation\ValidationException;

$vectors = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/health-timeout-vectors.json'), true, flags: JSON_THROW_ON_ERROR);

/**
 * @param  array<string, mixed>  $values
 */
function deployLimitsAccepts(string $class, array $values): bool
{
    try {
        $class::validate($values);

        return true;
    } catch (ValidationException) {
        return false;
    }
}

it('sends the deploy form exactly the bounds that validation accepts', function (string $class, string $parameter, string $field): void {
    /** @var NumberRangeData $range */
    $range = DeployLimitsData::make()->{$field};

    expect(deployLimitsAccepts($class, [$parameter => $range->min]))->toBeTrue()
        ->and(deployLimitsAccepts($class, [$parameter => $range->max]))->toBeTrue()
        ->and(deployLimitsAccepts($class, [$parameter => $range->min - 1]))->toBeFalse()
        ->and(deployLimitsAccepts($class, [$parameter => $range->max + 1]))->toBeFalse();
})->with([
    [HealthcheckSettings::class, 'startPeriodSeconds', 'startPeriod'],
    [HealthcheckSettings::class, 'intervalSeconds', 'interval'],
    [HealthcheckSettings::class, 'timeoutSeconds', 'timeout'],
    [HealthcheckSettings::class, 'failureThreshold', 'failures'],
    [DeploySettings::class, 'drainSeconds', 'drain'],
    [DeploySettings::class, 'rolloutTimeoutSeconds', 'rolloutTimeout'],
    [DeploySettings::class, 'healthTimeoutSeconds', 'healthTimeout'],
]);

it('never offers a zero start period, which validation refuses', function (): void {
    expect(DeployLimitsData::make()->startPeriod->min)->toBe(1);
});

test('the automatic health timeout matches the shared vectors the form reads', function (int $startPeriod, int $interval, int $timeout, int $failures, int $healthTimeout): void {
    $settings = new DeploySettings(healthcheck: new HealthcheckSettings(
        startPeriodSeconds: $startPeriod,
        intervalSeconds: $interval,
        timeoutSeconds: $timeout,
        failureThreshold: $failures,
    ));

    expect($settings->healthTimeout())->toBe($healthTimeout);
})->with(array_map(array_values(...), $vectors));

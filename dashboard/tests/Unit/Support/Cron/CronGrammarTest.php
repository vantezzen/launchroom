<?php

declare(strict_types=1);

use App\Enums\WorkloadCapability;
use App\Support\Blueprints\BlueprintValidator;
use App\Support\Cron\CronGrammar;
use Tests\Support\ProtocolSchemas;

/*
| The cron grammar is launchroomd's (daemon/internal/cronexpr): the same
| expressions are accepted and refused on both sides (the Go test lists them
| too), so a schedule the dashboard saves is never refused by a server.
*/

test('the grammar accepts what launchroomd runs', function (string $cron): void {
    expect(CronGrammar::error($cron))->toBeNull();
})->with([
    '* * * * *', '*/5 * * * *', '0 3 * * *', '0 9 * * 1-5', '30 2 1 * *', '0 0 * * 7',
    '0,15,30,45 * * * *', '5/10 * * * *', '0 8-18/2 * * *', '0 0 29 2 *',
]);

test('the grammar refuses what launchroomd refuses', function (string $cron): void {
    expect(CronGrammar::error($cron))->toBeString();
})->with([
    '', '* * * *', '* * * * * *', '*  * * * *', ' * * * * *', '60 * * * *', '* 24 * * *',
    '* * 0 * *', '* * * 13 *', '* * * * 8', '*/0 * * * *', '5-1 * * * *', '@daily',
    'MON * * * *', '* * * JAN *', '? * * * *', '1,,2 * * * *', '-1 * * * *', '001 * * * *',
    '* * L * *', '*/60 * * * *', '1-2-3 * * * *',
]);

test('time zones are IANA names', function (): void {
    expect(CronGrammar::timeZoneError('UTC'))->toBeNull()
        ->and(CronGrammar::timeZoneError('Europe/Berlin'))->toBeNull()
        ->and(CronGrammar::timeZoneError('America/Argentina/Buenos_Aires'))->toBeNull()
        ->and(CronGrammar::timeZoneError('Local'))->toBeString()
        ->and(CronGrammar::timeZoneError('Mars/Olympus'))->toBeString()
        ->and(CronGrammar::timeZoneError('../etc/passwd'))->toBeString();
});

test('the capabilities offered are launchroomd\'s generated allow-list', function (): void {
    $schema = json_decode((string) file_get_contents(ProtocolSchemas::root().'/schema/v1/capability-allow-list.json'), true);
    $config = json_decode((string) file_get_contents(resource_path('schema/launchroom.v1.json')), true);

    expect(WorkloadCapability::values())->toBe($schema['enum'])
        ->and(BlueprintValidator::CAPABILITIES)->toBe($schema['enum'])
        ->and($config['$defs']['deploy']['properties']['capabilities']['items']['enum'])->toBe($schema['enum']);
});

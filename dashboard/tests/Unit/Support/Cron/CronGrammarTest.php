<?php

declare(strict_types=1);

use App\Support\Cron\CronGrammar;
use Tests\Support\ProtocolSchemas;

/*
| The cron grammar is launchroomd's (daemon/internal/cronexpr): the shared
| vectors (protocol/fixtures/cron.json, read by the Go and TS tests too) are
| accepted and refused on both sides, so a schedule the dashboard saves is
| never refused by a server.
*/

function cronVectors(): array
{
    return json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/cron.json'), true, flags: JSON_THROW_ON_ERROR);
}

test('the grammar accepts what launchroomd runs', function (): void {
    foreach (cronVectors()['accept'] as $cron) {
        expect(CronGrammar::error($cron))->toBeNull($cron);
    }
});

test('the grammar refuses what launchroomd refuses', function (): void {
    foreach (cronVectors()['refuse'] as $cron) {
        expect(CronGrammar::error($cron))->toBeString($cron);
    }
});

test('time zones are IANA names', function (): void {
    foreach (cronVectors()['timeZones']['valid'] as $zone) {
        expect(CronGrammar::timeZoneError($zone))->toBeNull($zone);
    }

    foreach (cronVectors()['timeZones']['invalid'] as $zone) {
        expect(CronGrammar::timeZoneError($zone))->toBeString($zone);
    }
});

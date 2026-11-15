<?php

declare(strict_types=1);

use App\Data\Domains\DnsCheckData;
use App\Data\Moves\CutoverDnsRowData;
use App\Data\Moves\CutoverImagesData;
use App\Data\Moves\CutoverMissingImageData;
use App\Data\Moves\CutoverServerData;
use App\Enums\Domains\DnsCheckStatus;
use App\Enums\ServerStatus;
use App\Queries\ControlPlaneCutoverChecks;

function cutoverRow(?DnsCheckStatus $status): CutoverDnsRowData
{
    return new CutoverDnsRowData('Apps', '*.apps.example.com', $status instanceof DnsCheckStatus ? new DnsCheckData($status, 'x.apps.example.com', [], [], null, '', now()->toIso8601String()) : null);
}

function cutoverServer(bool $reconnected): CutoverServerData
{
    return new CutoverServerData('srv_1', 'web-1', ServerStatus::Online, null, $reconnected);
}

test('one rule decides which cutover steps passed, for the move and the recovery checklist', function (): void {
    $checks = resolve(ControlPlaneCutoverChecks::class);

    $nothing = $checks->checks([], [], [], null);
    expect($nothing->dnsOk)->toBeTrue()
        ->and($nothing->serversOk)->toBeTrue()
        ->and($nothing->imagesOk)->toBeFalse();

    expect($checks->checks([], [cutoverRow(DnsCheckStatus::Ok), cutoverRow(null)], [], null)->dnsOk)->toBeFalse()
        ->and($checks->checks([], [cutoverRow(DnsCheckStatus::Ok)], [], null)->dnsOk)->toBeTrue()
        ->and($checks->checks([], [], [cutoverServer(true), cutoverServer(false)], null)->serversOk)->toBeFalse()
        ->and($checks->checks([], [], [], new CutoverImagesData(3, 0, []))->imagesOk)->toBeTrue()
        ->and($checks->checks([], [], [], new CutoverImagesData(3, 0, [new CutoverMissingImageData('web', 'dpl_1', 'abc1234')]))->imagesOk)->toBeFalse();
});

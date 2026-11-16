<?php

declare(strict_types=1);

use App\Data\Specs\ChangeItem;
use App\Enums\ChangeEffect;
use App\Enums\ChangeKind;
use App\Enums\PromotionClass;
use App\Support\Changes\ChangeTally;

function tallyItem(string $path): ChangeItem
{
    return new ChangeItem($path, ChangeKind::Changed, ChangeEffect::Restart, PromotionClass::Structural, $path, 'svc_a');
}

it('names one kind of change with its count', function (): void {
    expect(ChangeTally::summary([
        tallyItem('services.svc_a.variables.var_1'),
        tallyItem('services.svc_a.sharedVariables.var_2'),
    ]))->toBe('2 variables');
});

it('names two kinds and counts settings', function (): void {
    expect(ChangeTally::summary([
        tallyItem('services.svc_a.scaling.replicas'),
        tallyItem('services.svc_a.variables.var_1'),
        tallyItem('services.svc_a.deploy.healthcheck'),
    ]))->toBe('1 variable and 2 settings');
});

it('falls back to a total for three or more kinds', function (): void {
    expect(ChangeTally::summary([
        tallyItem('services.svc_a.domains.dom_1'),
        tallyItem('services.svc_a.volumes.vol_1'),
        tallyItem('connections.con_1'),
    ]))->toBe('3 changes');
});

it('leaves a new or removed service to its chip', function (): void {
    expect(ChangeTally::summary([tallyItem('services.svc_a')]))->toBeNull()
        ->and(ChangeTally::summary([]))->toBeNull();
});

<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Services\Moves\InstanceFreeze;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('before the migrations ran there is nothing to freeze', function (): void {
    // One query only: the failed one aborts the test's transaction.
    DB::statement('ALTER TABLE instance_settings RENAME TO instance_settings_hidden');
    app()->forgetScopedInstances();

    expect(InstanceFreeze::active())->toBeFalse();
});

test('before the migrations ran nothing has moved', function (): void {
    DB::statement('ALTER TABLE instance_settings RENAME TO instance_settings_hidden');
    app()->forgetScopedInstances();

    expect(InstanceFreeze::movedTo())->toBeNull();
});

test('other database errors fail closed instead of letting writes through', function (): void {
    app()->scoped('launchroom.instance-settings', static fn (): InstanceSettings => throw new QueryException('pgsql', 'select 1', [], new PDOException('connection refused')));

    expect(fn (): bool => InstanceFreeze::active())->toThrow(QueryException::class);
});

test('a frozen instance is reported', function (): void {
    InstanceFreeze::freeze('Handing over');
    app()->forgetScopedInstances();

    expect(InstanceFreeze::active())->toBeTrue();
});

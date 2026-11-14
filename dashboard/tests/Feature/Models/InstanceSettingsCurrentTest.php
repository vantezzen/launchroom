<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use Illuminate\Support\Facades\DB;

test('reads the row once per request', function (): void {
    InstanceSettings::current();
    DB::enableQueryLog();

    $first = InstanceSettings::current();
    $second = InstanceSettings::current();

    $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'instance_settings'));

    expect($second)->toBe($first)
        ->and($reads)->toBe([]);
});

test('a saved copy replaces the shared one', function (): void {
    $shared = InstanceSettings::current();

    $locked = InstanceSettings::lockedForUpdate();
    $locked->apps_domain = 'apps.example.test';
    $locked->save();

    expect(InstanceSettings::current())->not->toBe($shared)
        ->and(InstanceSettings::current()->apps_domain)->toBe('apps.example.test');
});

test('forgetting the shared copy reads a query builder write', function (): void {
    InstanceSettings::current();
    InstanceSettings::query()->whereKey(InstanceSettings::ID)->update(['apps_domain' => 'raw.example.test']);

    InstanceSettings::forgetCurrent();

    expect(InstanceSettings::current()->apps_domain)->toBe('raw.example.test');
});

test('a queued job reads the row again', function (): void {
    $before = InstanceSettings::current();

    app()->forgetScopedInstances();

    expect(InstanceSettings::current())->not->toBe($before);
});

<?php

declare(strict_types=1);

use App\Data\Promotions\PromotionEntry;
use App\Data\Promotions\PromotionItem;
use App\Data\Promotions\PromotionSnapshot;
use App\Enums\ChangeEffect;
use App\Enums\ChangeKind;
use App\Enums\PromotionClass;
use App\Enums\PromotionEntryType;
use App\Support\Promotions\PromotionDiff;

const PD_API = 'svc_01JAPI0000000000000000000A';
const PD_REDIS = 'svc_01JREDIS00000000000000000R';
const PD_DB = 'svc_01JDB000000000000000000000';

function pdService(string $id, bool $holdsData = false): array
{
    return ["services.{$id}" => new PromotionEntry(PromotionEntryType::Service, $holdsData ? 'database' : 'app', 'app service', PromotionClass::Structural, ChangeEffect::New, $id, display: $id, holdsData: $holdsData)];
}

function pdField(string $service, string $field, string $value, PromotionClass $class = PromotionClass::Structural, ChangeEffect $effect = ChangeEffect::Restart): array
{
    return ["services.{$service}.{$field}" => new PromotionEntry(PromotionEntryType::Field, $value, $field, $class, $effect, $service, $field, $value)];
}

/**
 * @param  array{secret?: bool, generated?: bool, generator?: string|null, exposure?: string, display?: string|null}  $options
 */
function pdVariable(?string $service, string $key, string $value, array $options = []): array
{
    $secret = $options['secret'] ?? false;
    $generated = $options['generated'] ?? false;
    $valueFingerprint = $generated ? 'generated' : 'mac:'.$value;
    $type = $service === null ? PromotionEntryType::SharedVariable : PromotionEntryType::Variable;
    $path = $service === null ? "sharedVariables.{$key}" : "services.{$service}.variables.{$key}";

    return [$path => new PromotionEntry(
        type: $type,
        fingerprint: ($secret ? 'secret' : 'config').'|'.($options['exposure'] ?? 'runtime').'|'.$valueFingerprint,
        label: "variable {$key}",
        promotion: $secret ? PromotionClass::Never : PromotionClass::Structural,
        effect: ($options['exposure'] ?? 'runtime') === 'build' ? ChangeEffect::Rebuild : ChangeEffect::Restart,
        serviceId: $service,
        key: $key,
        display: $secret ? null : ($options['display'] ?? $value),
        secret: $secret,
        generated: $generated,
        generator: $options['generator'] ?? null,
        valueFingerprint: $valueFingerprint,
    )];
}

function pdVolume(string $service, string $path): array
{
    return ["services.{$service}.volumes.{$path}" => new PromotionEntry(PromotionEntryType::Volume, 'mounted', "volume {$path}", PromotionClass::Structural, ChangeEffect::Restart, $service, $path)];
}

function pdUsage(string $service, string $key): array
{
    return ["services.{$service}.sharedVariables.{$key}" => new PromotionEntry(PromotionEntryType::SharedUsage, 'used', "uses shared {$key}", PromotionClass::Structural, ChangeEffect::Restart, $service, $key)];
}

function pdConnection(string $id, string $from, string $to): array
{
    return ["connections.{$id}" => new PromotionEntry(PromotionEntryType::Connection, 'connected', 'connection', PromotionClass::Structural, ChangeEffect::Restart, $from, $id, dependsOn: ["services.{$from}", "services.{$to}"])];
}

function pdTriggers(string $branch): array
{
    return ['environment.triggers' => new PromotionEntry(PromotionEntryType::Triggers, $branch, 'deploy triggers', PromotionClass::EnvironmentSpecific, ChangeEffect::None, display: $branch)];
}

function pdSnap(array ...$parts): PromotionSnapshot
{
    return new PromotionSnapshot(array_merge(...$parts));
}

/**
 * @param  list<PromotionItem>  $items
 * @return array<string, PromotionItem>
 */
function pdByPath(array $items): array
{
    $byPath = [];

    foreach ($items as $item) {
        $byPath[$item->path] = $item;
    }

    return $byPath;
}

$common = static fn (): array => [
    ...pdService(PD_API),
    ...pdField(PD_API, 'deploy.healthcheck', '30'),
    ...pdField(PD_API, 'scaling.replicas', '1', PromotionClass::EnvironmentSpecific),
    ...pdVariable(PD_API, 'APP_NAME', 'shop'),
];

test('identical environments offer nothing, with or without a base', function () use ($common): void {
    $snapshot = pdSnap($common());

    expect(PromotionDiff::between(null, $snapshot, $snapshot))->toBe([])
        ->and(PromotionDiff::between($snapshot, $snapshot, $snapshot))->toBe([]);
});

test('a change in the source since the base is offered and pre-checked when structural', function () use ($common): void {
    $base = pdSnap($common());
    $source = pdSnap($common(), pdField(PD_API, 'deploy.healthcheck', '60'));

    $items = PromotionDiff::between($base, $source, $base);

    expect($items)->toHaveCount(1)
        ->and($items[0]->path)->toBe('services.'.PD_API.'.deploy.healthcheck')
        ->and($items[0]->kind)->toBe(ChangeKind::Changed)
        ->and($items[0]->selected)->toBeTrue()
        ->and($items[0]->conflict)->toBeFalse()
        ->and([$items[0]->before, $items[0]->after, $items[0]->base])->toBe(['30', '60', '30']);
});

test('the target changing the same item differently is a conflict, unchecked, with all three values', function () use ($common): void {
    $base = pdSnap($common());
    $source = pdSnap($common(), pdField(PD_API, 'deploy.healthcheck', '60'));
    $target = pdSnap($common(), pdField(PD_API, 'deploy.healthcheck', '45'));

    [$item] = PromotionDiff::between($base, $source, $target);

    expect($item->conflict)->toBeTrue()
        ->and($item->selectable)->toBeTrue()
        ->and($item->selected)->toBeFalse()
        ->and([$item->before, $item->after, $item->base])->toBe(['45', '60', '30']);
});

test('both sides making the same change, or only the target changing, offers nothing', function () use ($common): void {
    $base = pdSnap($common());
    $changed = pdSnap($common(), pdField(PD_API, 'deploy.healthcheck', '60'));

    expect(PromotionDiff::between($base, $changed, $changed))->toBe([])
        ->and(PromotionDiff::between($base, $base, $changed))->toBe([]);
});

test('without a base every difference is offered and nothing is a conflict', function () use ($common): void {
    $source = pdSnap($common(), pdField(PD_API, 'deploy.healthcheck', '60'), pdVariable(PD_API, 'NEW', 'x'));
    $target = pdSnap($common(), pdVariable(PD_API, 'OLD', 'y'));

    $items = pdByPath(PromotionDiff::between(null, $source, $target));

    expect(array_keys($items))->toEqualCanonicalizing([
        'services.'.PD_API.'.deploy.healthcheck',
        'services.'.PD_API.'.variables.NEW',
        'services.'.PD_API.'.variables.OLD',
    ])
        ->and(array_filter($items, static fn (PromotionItem $item): bool => $item->conflict))->toBe([])
        ->and($items['services.'.PD_API.'.variables.OLD']->kind)->toBe(ChangeKind::Removed)
        ->and($items['services.'.PD_API.'.variables.NEW']->kind)->toBe(ChangeKind::Added);
});

test('environment-specific items are offered unchecked', function () use ($common): void {
    $base = pdSnap($common(), pdTriggers('main'));
    $source = pdSnap($common(), pdField(PD_API, 'scaling.replicas', '2', PromotionClass::EnvironmentSpecific), pdTriggers('develop'));

    $items = pdByPath(PromotionDiff::between($base, $source, $base));

    expect($items['services.'.PD_API.'.scaling.replicas']->selected)->toBeFalse()
        ->and($items['services.'.PD_API.'.scaling.replicas']->promotion)->toBe(PromotionClass::EnvironmentSpecific)
        ->and($items['environment.triggers']->selected)->toBeFalse()
        ->and($items['environment.triggers']->selectable)->toBeTrue();
});

test('a new service brings its settings and volumes; its variables depend on it; its connections follow', function () use ($common): void {
    $base = pdSnap($common());
    $source = pdSnap(
        $common(),
        pdService(PD_REDIS, holdsData: true),
        pdField(PD_REDIS, 'deploy.port', '6379'),
        pdVolume(PD_REDIS, '/data'),
        pdVariable(PD_REDIS, 'REDIS_PASSWORD', 'x', ['secret' => true, 'generated' => true, 'generator' => 'secret(32)']),
        pdVariable(PD_API, 'REDIS_URL', '${{redis.REDIS_URL}}'),
        pdConnection('con_1', PD_API, PD_REDIS),
    );

    $items = pdByPath(PromotionDiff::between($base, $source, $base));
    $service = $items['services.'.PD_REDIS];
    $password = $items['services.'.PD_REDIS.'.variables.REDIS_PASSWORD'];
    $connection = $items['connections.con_1'];

    expect($items)->not->toHaveKey('services.'.PD_REDIS.'.deploy.port')
        ->and($items)->not->toHaveKey('services.'.PD_REDIS.'.volumes./data')
        ->and($service->kind)->toBe(ChangeKind::Added)
        ->and($service->effect)->toBe(ChangeEffect::New)
        ->and($service->selected)->toBeTrue()
        ->and($service->note)->toContain('fresh instance')
        ->and($password->dependsOn)->toBe(['services.'.PD_REDIS])
        ->and($password->needsValue)->toBeFalse()
        ->and($password->note)->toContain('Generated afresh')
        ->and($password->selected)->toBeTrue()
        ->and($items['services.'.PD_API.'.variables.REDIS_URL']->selected)->toBeTrue()
        ->and($connection->selectable)->toBeFalse()
        ->and($connection->dependsOn)->toBe(['services.'.PD_REDIS]);
});

test('a connection between services both environments have is never listed', function () use ($common): void {
    $base = pdSnap($common(), pdService(PD_REDIS));
    $source = pdSnap($common(), pdService(PD_REDIS), pdConnection('con_1', PD_API, PD_REDIS));

    expect(PromotionDiff::between($base, $source, $base))->toBe([]);
});

test('removing a service is offered unchecked, warns about data and lists nothing else of it', function () use ($common): void {
    $base = pdSnap($common(), pdService(PD_DB, holdsData: true), pdVariable(PD_DB, 'PGPASSWORD', 'x', ['secret' => true]), pdVolume(PD_DB, '/var/lib/postgresql'));
    $source = pdSnap($common());

    $items = PromotionDiff::between($base, $source, $base);

    expect($items)->toHaveCount(1)
        ->and($items[0]->kind)->toBe(ChangeKind::Removed)
        ->and($items[0]->effect)->toBe(ChangeEffect::Remove)
        ->and($items[0]->selected)->toBeFalse()
        ->and($items[0]->warning)->toContain('Holds data');
});

test('items of a service the target removed on purpose are not offered', function () use ($common): void {
    $base = pdSnap($common(), pdService(PD_REDIS), pdField(PD_REDIS, 'deploy.port', '6379'));
    $source = pdSnap($common(), pdService(PD_REDIS), pdField(PD_REDIS, 'deploy.port', '6380'));
    $target = pdSnap($common());

    expect(PromotionDiff::between($base, $source, $target))->toBe([]);
});

test('a new secret needs a value for the target and may be copied', function () use ($common): void {
    $base = pdSnap($common());
    $source = pdSnap($common(), pdVariable(PD_API, 'STRIPE_SECRET', 'sk_live', ['secret' => true]));

    [$item] = PromotionDiff::between($base, $source, $base);

    expect($item->promotion)->toBe(PromotionClass::Never)
        ->and($item->needsValue)->toBeTrue()
        ->and($item->acceptsValue)->toBeTrue()
        ->and($item->canCopyValue)->toBeTrue()
        ->and($item->selected)->toBeTrue()
        ->and($item->after)->toBeNull()
        ->and($item->secret)->toBeTrue();
});

test('a changed secret value is offered unchecked and needs a value; type changes keep the value', function () use ($common): void {
    $base = pdSnap($common(), pdVariable(PD_API, 'TOKEN', 'a', ['secret' => true]), pdVariable(PD_API, 'KEY', 'k'));
    $source = pdSnap($common(), pdVariable(PD_API, 'TOKEN', 'b', ['secret' => true]), pdVariable(PD_API, 'KEY', 'k', ['secret' => true]));

    $items = pdByPath(PromotionDiff::between($base, $source, $base));
    $token = $items['services.'.PD_API.'.variables.TOKEN'];
    $key = $items['services.'.PD_API.'.variables.KEY'];

    expect($token->promotion)->toBe(PromotionClass::Never)
        ->and($token->selected)->toBeFalse()
        ->and($token->needsValue)->toBeTrue()
        ->and($key->promotion)->toBe(PromotionClass::Structural)
        ->and($key->selected)->toBeTrue()
        ->and($key->needsValue)->toBeFalse()
        ->and($key->acceptsValue)->toBeTrue();
});

test('generated values that changed are never promoted; config values are, with their values shown', function () use ($common): void {
    $base = pdSnap($common(), pdVariable(PD_API, 'SESSION', 'a', ['secret' => true, 'generated' => true, 'generator' => 'secret(32)', 'exposure' => 'runtime']));
    $source = pdSnap(
        pdService(PD_API),
        pdField(PD_API, 'deploy.healthcheck', '30'),
        pdField(PD_API, 'scaling.replicas', '1', PromotionClass::EnvironmentSpecific),
        pdVariable(PD_API, 'APP_NAME', 'store'),
        pdVariable(PD_API, 'SESSION', 'a', ['secret' => true, 'generated' => true, 'generator' => 'secret(32)', 'exposure' => 'build']),
    );

    $items = pdByPath(PromotionDiff::between($base, $source, $base));

    expect($items['services.'.PD_API.'.variables.APP_NAME']->before)->toBe('shop')
        ->and($items['services.'.PD_API.'.variables.APP_NAME']->after)->toBe('store')
        ->and($items['services.'.PD_API.'.variables.APP_NAME']->selected)->toBeTrue()
        // Exposure changed (not the value): structural, the value stays.
        ->and($items['services.'.PD_API.'.variables.SESSION']->promotion)->toBe(PromotionClass::Structural);

    $regenerated = pdSnap($common(), pdVariable(PD_API, 'SESSION', 'b', ['generated' => true, 'generator' => 'secret(32)']));
    $baseConfig = pdSnap($common(), pdVariable(PD_API, 'SESSION', 'a', ['generated' => true, 'generator' => 'secret(32)']));

    // Generated values have one fingerprint whatever their value: nothing to offer.
    expect(PromotionDiff::between($baseConfig, $regenerated, $baseConfig))->toBe([]);
});

test('removed variables are offered checked; volumes are added but never removed', function () use ($common): void {
    $base = pdSnap($common(), pdVolume(PD_API, '/cache'));
    $source = pdSnap(pdService(PD_API), pdField(PD_API, 'deploy.healthcheck', '30'), pdField(PD_API, 'scaling.replicas', '1', PromotionClass::EnvironmentSpecific), pdVolume(PD_API, '/uploads'));

    $items = pdByPath(PromotionDiff::between($base, $source, $base));

    expect($items['services.'.PD_API.'.variables.APP_NAME']->kind)->toBe(ChangeKind::Removed)
        ->and($items['services.'.PD_API.'.variables.APP_NAME']->selected)->toBeTrue()
        ->and($items['services.'.PD_API.'.volumes./uploads']->selected)->toBeTrue()
        ->and($items['services.'.PD_API.'.volumes./cache']->selectable)->toBeFalse();
});

test('a shared variable opt-in depends on the shared variable when the target lacks it', function () use ($common): void {
    $base = pdSnap($common());
    $source = pdSnap($common(), pdVariable(null, 'SENTRY_DSN', 'https://x'), pdUsage(PD_API, 'SENTRY_DSN'));

    $items = pdByPath(PromotionDiff::between($base, $source, $base));

    expect($items['services.'.PD_API.'.sharedVariables.SENTRY_DSN']->dependsOn)->toBe(['sharedVariables.SENTRY_DSN'])
        ->and($items['sharedVariables.SENTRY_DSN']->kind)->toBe(ChangeKind::Added);
});

test('conflicts: removed in the source but changed in the target, and added differently on both sides', function () use ($common): void {
    $base = pdSnap($common());
    $source = pdSnap(pdService(PD_API), pdField(PD_API, 'deploy.healthcheck', '30'), pdField(PD_API, 'scaling.replicas', '1', PromotionClass::EnvironmentSpecific), pdVariable(PD_API, 'NEW', 'a'));
    $target = pdSnap($common(), pdVariable(PD_API, 'APP_NAME', 'changed'), pdVariable(PD_API, 'NEW', 'b'));

    $items = pdByPath(PromotionDiff::between($base, $source, $target));

    expect($items['services.'.PD_API.'.variables.APP_NAME']->conflict)->toBeTrue()
        ->and($items['services.'.PD_API.'.variables.APP_NAME']->kind)->toBe(ChangeKind::Removed)
        ->and($items['services.'.PD_API.'.variables.NEW']->conflict)->toBeTrue()
        ->and($items['services.'.PD_API.'.variables.NEW']->kind)->toBe(ChangeKind::Changed)
        ->and($items['services.'.PD_API.'.variables.NEW']->selected)->toBeFalse();
});

test('offered() counts what can be selected; project-level items come last', function () use ($common): void {
    $base = pdSnap($common(), pdVolume(PD_API, '/cache'));
    $source = pdSnap($common(), pdService(PD_REDIS), pdVariable(null, 'A', '1'));

    $items = PromotionDiff::between($base, $source, $base);

    expect(PromotionDiff::offered($items))->toBe(2)
        ->and(end($items)->path)->toBe('sharedVariables.A');
});

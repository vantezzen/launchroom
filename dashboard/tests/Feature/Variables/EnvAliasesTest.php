<?php

declare(strict_types=1);

use App\Data\Canvas\DetectedPlanData;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Specs\EnvironmentSpec;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Variables\SystemVariables;

/*
| Framework aliases (BuildSummary.envAliases, OD12/G47): framework variables
| set to system values per environment, underneath the user's variables.
| Workers and crons resolve against their app's domains; a preview gets
| its own; no domain means nothing is set. HOST only where a preset asks;
| WEB_CONCURRENCY from the replica's limits (G79).
*/

const EA_WEB = 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9A1';
const EA_QUEUE = 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9A2';

/**
 * @param  array<string, array<string, mixed>>  $webDomains  hostname => domain
 * @param  array<string, mixed>  $scaling
 */
function eaSpec(array $webDomains, string $kind = 'production', string $slug = 'production', array $scaling = []): EnvironmentSpec
{
    $service = static fn (string $id, string $slug, string $kind, ?string $parent, array $domains, array $deploy = []): array => [
        'id' => $id, 'instanceId' => 'sin'.substr($id, 3), 'slug' => $slug, 'name' => $slug, 'kind' => $kind,
        'parentId' => $parent, 'source' => ['type' => 'git', 'repositoryId' => 1, 'fullName' => 'acme/shop'], 'icon' => null, 'enabled' => true,
        'branchOverride' => null, 'build' => [], 'deploy' => $deploy, 'scaling' => $scaling, 'domains' => $domains,
    ];

    return EnvironmentSpec::from([
        'environment' => ['id' => 'env_01J9Z8X7W6V5T4S3R2Q1P0N9E1', 'slug' => $slug, 'name' => $slug, 'kind' => $kind, 'projectId' => 'prj_01J9Z8X7W6V5T4S3R2Q1P0N9P1', 'projectSlug' => 'shop'],
        'services' => [
            EA_WEB => $service(EA_WEB, 'web', 'app', null, $webDomains),
            EA_QUEUE => $service(EA_QUEUE, 'queue', 'worker', EA_WEB, [], ['startCommand' => ['php', 'artisan', 'queue:work']]),
        ],
    ]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function eaDomains(string ...$hosts): array
{
    $domains = [];

    foreach ($hosts as $i => $host) {
        $custom = ! str_ends_with($host, '.apps.example.com');
        $domains[$host] = ['id' => 'dom_01J9Z8X7W6V5T4S3R2Q1P0N9D'.$i, 'hostname' => $host, 'kind' => $custom ? 'custom' : 'generated', 'redirectTo' => null];
    }

    return $domains;
}

/**
 * @param  list<array{name: string, from: string}>  $aliases
 * @param  array<string, mixed>  $extra
 */
function eaSummary(array $aliases, array $extra = []): BuildSummary
{
    return BuildSummary::from(['builder' => 'railpack', 'builderReason' => 'preset', 'provider' => 'php', 'framework' => 'laravel', 'port' => 8080, 'envAliases' => $aliases, ...$extra]);
}

/**
 * @return array<string, string>
 */
function eaEnv(EnvironmentSpec $spec, string $service, BuildSummary $summary, ?string $subnet = null): array
{
    $own = $spec->service($service);
    expect($own)->not->toBeNull();

    return EffectiveDeploy::from($own, $summary, $spec, static fn (): ?string => $subnet)->envDefaults;
}

test('an alias resolves to the app’s address, custom domain first', function (): void {
    $spec = eaSpec(eaDomains('web-production.apps.example.com', 'shop.example.com'));
    $env = eaEnv($spec, EA_WEB, eaSummary([['name' => 'APP_URL', 'from' => 'PUBLIC_URL'], ['name' => 'PHX_HOST', 'from' => 'DOMAIN'], ['name' => 'APP_PORT', 'from' => 'PORT']]));

    expect($env)->toMatchArray([
        'APP_URL' => 'https://shop.example.com',
        'PHX_HOST' => 'shop.example.com',
        'APP_PORT' => '8080',
        'PORT' => '8080',
    ]);
});

test('lists, allowed hosts and trusted proxies', function (): void {
    $spec = eaSpec(eaDomains('web-production.apps.example.com', 'shop.example.com'));
    $env = eaEnv($spec, EA_WEB, eaSummary([
        ['name' => 'DJANGO_ALLOWED_HOSTS', 'from' => 'ALLOWED_HOSTS_LIST'],
        ['name' => 'CSRF_TRUSTED_ORIGINS', 'from' => 'PUBLIC_URLS'],
        ['name' => 'FORWARDED_ALLOW_IPS', 'from' => 'TRUSTED_PROXY_CIDRS'],
        ['name' => 'HOSTS', 'from' => 'DOMAINS'],
    ]), '10.210.4.0/24');

    expect($env)->toMatchArray([
        'DJANGO_ALLOWED_HOSTS' => 'shop.example.com,web-production.apps.example.com,web,localhost',
        'CSRF_TRUSTED_ORIGINS' => 'https://shop.example.com,https://web-production.apps.example.com',
        'FORWARDED_ALLOW_IPS' => '10.210.4.1/32,100.64.0.0/10',
        'HOSTS' => 'shop.example.com,web-production.apps.example.com',
    ]);
});

test('a worker resolves against its app’s domains and gets PUBLIC_URL and DOMAIN too', function (): void {
    $spec = eaSpec(eaDomains('shop.example.com'));
    $queue = $spec->service(EA_QUEUE);
    expect($queue)->not->toBeNull();

    expect(eaEnv($spec, EA_QUEUE, eaSummary([['name' => 'APP_URL', 'from' => 'PUBLIC_URL']])))->toMatchArray(['APP_URL' => 'https://shop.example.com'])
        ->and(SystemVariables::for($spec->environment, $queue, null, $spec->service(EA_WEB)))->toMatchArray([
            'DOMAIN' => 'shop.example.com',
            'PUBLIC_URL' => 'https://shop.example.com',
            'DOMAINS' => 'shop.example.com',
            'LAUNCHROOM_SERVICE' => 'queue',
            'PRIVATE_HOST' => 'queue',
        ]);
});

test('a preview resolves the same image against its own domain', function (): void {
    $summary = eaSummary([['name' => 'APP_URL', 'from' => 'PUBLIC_URL']]);

    expect(eaEnv(eaSpec(eaDomains('shop.example.com')), EA_WEB, $summary)['APP_URL'])->toBe('https://shop.example.com')
        ->and(eaEnv(eaSpec(eaDomains('web-pr-7.apps.example.com'), 'preview', 'pr-7'), EA_WEB, $summary)['APP_URL'])->toBe('https://web-pr-7.apps.example.com');
});

test('without a domain nothing is set, so the framework keeps its default', function (): void {
    $env = eaEnv(eaSpec([]), EA_WEB, eaSummary([['name' => 'APP_URL', 'from' => 'PUBLIC_URL'], ['name' => 'PHX_HOST', 'from' => 'DOMAIN']]));

    expect($env)->not->toHaveKey('APP_URL')
        ->and($env)->not->toHaveKey('PHX_HOST');

    $spec = eaSpec([]);
    $web = $spec->service(EA_WEB);
    expect($web)->not->toBeNull();
    expect(SystemVariables::for($spec->environment, $web, 8080))->not->toHaveKeys(['DOMAIN', 'PUBLIC_URL', 'DOMAINS', 'PUBLIC_URLS']);
});

test('a variable the user set wins over the alias', function (): void {
    $env = eaEnv(eaSpec(eaDomains('shop.example.com')), EA_WEB, eaSummary([['name' => 'APP_URL', 'from' => 'PUBLIC_URL']]));
    // The workload's env: defaults underneath the deployment's snapshot (NodeStateContent).
    $workload = [...$env, ...['APP_URL' => 'https://www.shop.example.com']];

    expect($workload['APP_URL'])->toBe('https://www.shop.example.com');
});

test('HOST is set only where a preset asks for it', function (): void {
    $spec = eaSpec(eaDomains('shop.example.com'));
    $web = $spec->service(EA_WEB);
    expect($web)->not->toBeNull();

    $node = EffectiveDeploy::from($web, BuildSummary::from(['builder' => 'railpack', 'builderReason' => 'preset', 'provider' => 'node', 'presets' => [['name' => 'node-server', 'version' => 2]]]))->envDefaults;
    $laravel = EffectiveDeploy::from($web, eaSummary([]))->envDefaults;

    expect(SystemVariables::for($spec->environment, $web, 8080))->not->toHaveKeys(['HOST', 'HOSTNAME'])
        ->and($node)->toMatchArray(['HOST' => '0.0.0.0', 'HOSTNAME' => '0.0.0.0'])
        ->and($laravel)->not->toHaveKey('HOST');
});

test('WEB_CONCURRENCY follows the replica’s CPU and memory for Python and Ruby web servers', function (array $scaling, string $provider, ?string $expected): void {
    $spec = eaSpec([], scaling: $scaling);
    $web = $spec->service(EA_WEB);
    expect($web)->not->toBeNull();
    $env = EffectiveDeploy::from($web, BuildSummary::from(['builder' => 'railpack', 'builderReason' => 'preset', 'provider' => $provider]))->envDefaults;

    expect($env['WEB_CONCURRENCY'] ?? null)->toBe($expected);
})->with([
    'default 1 CPU, 512 MB' => [[], 'python', '2'],
    '4 CPUs, 4 GB' => [['cpus' => 4, 'memoryMb' => 4096], 'ruby', '8'],
    'memory bound' => [['cpus' => 4, 'memoryMb' => 512], 'python', '2'],
    'small' => [['cpus' => 0.25, 'memoryMb' => 256], 'python', '1'],
    'not for node' => [[], 'node', null],
]);

test('the plan names the aliases and leaves them out of "referenced but not defined"', function (): void {
    $plan = DetectedPlanData::fromSummary(eaSummary([['name' => 'APP_URL', 'from' => 'PUBLIC_URL']], ['envReferences' => ['APP_URL', 'MAIL_HOST']]), []);

    expect($plan->envAliases)->toBe(['APP_URL from PUBLIC_URL'])
        ->and($plan->missingVariables)->toBe(['MAIL_HOST']);
});

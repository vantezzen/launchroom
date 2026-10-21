<?php

declare(strict_types=1);

use App\Data\ConfigAsCode\ConfigExcerptLineData;
use App\Data\ConfigAsCode\ConfigFileProblemData;
use App\Data\Services\HealthcheckSettings;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\HealthcheckType;
use App\Enums\PlacementStrategy;
use App\Enums\ServiceKind;
use App\Support\ConfigAsCode\ConfigCheck;
use App\Support\ConfigAsCode\ConfigContext;
use App\Support\ConfigAsCode\ConfigFileValidator;
use App\Support\ConfigAsCode\ConfigSchema;
use App\Support\Slug;

function cfvCheck(array|string $file, ?ConfigContext $context = null): ConfigCheck
{
    $json = is_string($file) ? $file : json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return new ConfigFileValidator(new ConfigSchema)->check($json, $context ?? new ConfigContext([], ['production', 'staging']));
}

/**
 * @return list<string>
 */
function cfvMessages(array|string $file, ?ConfigContext $context = null): array
{
    return array_map(static fn (ConfigFileProblemData $problem): string => ($problem->path ?? '').': '.$problem->message.($problem->hint === null ? '' : ' | '.$problem->hint), cfvCheck($file, $context)->errors);
}

test('a full example file is valid', function (): void {
    $check = cfvCheck([
        '$schema' => ConfigSchema::ID,
        'version' => 1,
        'services' => [
            'web' => [
                'kind' => 'app', 'rootDirectory' => 'apps/web',
                'build' => ['buildCommand' => 'npm run build', 'watchPaths' => ['apps/web']],
                'deploy' => ['port' => 3000, 'startCommand' => ['npm', 'start'], 'healthcheck' => ['path' => '/health', 'timeoutSeconds' => 5]],
                'scaling' => ['replicas' => 2, 'cpus' => 0.5, 'placement' => ['labels' => ['region' => 'eu'], 'strategy' => 'pack']],
                'domains' => ['shop.example.com', ['hostname' => 'www.shop.example.com', 'redirectTo' => 'shop.example.com']],
                'variables' => ['API_URL' => '${{api.PUBLIC_URL}}', 'STRIPE_SECRET_KEY' => ['secret' => true, 'description' => 'Stripe live key'], 'APP_KEY' => '${{ secret(32) }}'],
                'volumes' => ['/data'],
                'connections' => ['api', ['to' => 'postgres', 'style' => 'laravel']],
            ],
            'api' => ['kind' => 'image', 'image' => 'ghcr.io/acme/api:1.2', 'deploy' => ['port' => 8000]],
            'queue' => ['kind' => 'worker', 'of' => 'web', 'deploy' => ['startCommand' => ['php', 'artisan', 'queue:work']]],
            'postgres' => ['kind' => 'database', 'engine' => 'postgres', 'version' => '18'],
        ],
        'environments' => [
            'staging' => ['services' => ['web' => ['scaling' => ['replicas' => 1, 'placement' => null], 'domains' => ['staging.example.com']]]],
            'previews' => ['services' => ['web' => ['variables' => ['API_URL' => null, 'LOG_LEVEL' => 'debug']]]],
        ],
    ]);

    expect($check->errors)->toBe([])
        ->and($check->valid())->toBeTrue()
        ->and($check->file?->forEnvironment('staging', false)['web']->config['scaling'])->toBe(['replicas' => 1, 'cpus' => 0.5])
        ->and($check->file?->forEnvironment('pr-3', true)['web']->config['variables'])->toHaveKeys(['STRIPE_SECRET_KEY', 'LOG_LEVEL'])->not->toHaveKey('API_URL');
});

test('unknown keys are refused with a suggestion and point at the key', function (): void {
    $check = cfvCheck("{\n  \"version\": 1,\n  \"services\": {\n    \"web\": {\n      \"kind\": \"app\",\n      \"build\": { \"buildComand\": \"npm run build\" }\n    }\n  }\n}");
    $problem = $check->errors[0];

    expect($check->valid())->toBeFalse()
        ->and($problem->message)->toBe('"buildComand" isn\'t a setting in services.web.build.')
        ->and($problem->hint)->toBe('Did you mean "buildCommand"?')
        ->and([$problem->line, $problem->column])->toBe([6, 18])
        ->and($problem->path)->toBe('services.web.build.buildComand')
        ->and(array_map(static fn (ConfigExcerptLineData $line): int => $line->number, $problem->excerpt))->toBe([4, 5, 6, 7, 8]);
});

test('schema problems are explained in plain words', function (array $file, string $expected): void {
    expect(implode("\n", cfvMessages($file)))->toContain($expected);
})->with([
    'missing version' => [['services' => []], 'The file needs "version". | Add "version": 1.'],
    'future version' => [['version' => 2, 'services' => []], 'version: This dashboard reads version 1'],
    'missing kind' => [['version' => 1, 'services' => ['web' => new stdClass]], 'services.web needs "kind".'],
    'bad kind' => [['version' => 1, 'services' => ['web' => ['kind' => 'ap']]], '"kind" must be one of: app, image, database, worker, cron. | Did you mean "app"?'],
    'string command' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'deploy' => ['startCommand' => 'npm run start']]]], 'Commands are lists of arguments (never run through a shell): ["npm", "run", "start"]'],
    'quoted port' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'deploy' => ['port' => '3000']]]], '"port" should be a whole number. | Remove the quotes: 3000.'],
    'port out of range' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'deploy' => ['port' => 70000]]]], '"port" should be at most 65535.'],
    'bad slug' => [['version' => 1, 'services' => ['My App' => ['kind' => 'app']]], '"My App" can\'t be a service name.'],
    'reserved slug' => [['version' => 1, 'services' => ['shared' => ['kind' => 'app']]], 'can\'t be a service name'],
    'secret with a value' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['KEY' => ['secret' => true, 'value' => 'x']]]]], '"value" isn\'t a setting in services.web.variables.KEY.'],
    'number as variable' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['PORT' => 3000]]]], 'should be text in double quotes or an object'],
    'domain with scheme' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'domains' => ['https://shop.example.com']]]], 'Write the domain alone'],
]);

test('rules the schema cannot express', function (array $file, string $expected, ?ConfigContext $context = null): void {
    expect(implode("\n", cfvMessages($file, $context)))->toContain($expected);
})->with([
    'image needs a reference' => [['version' => 1, 'services' => ['umami' => ['kind' => 'image']]], 'umami needs "image".'],
    'database fields on an app' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'engine' => 'postgres']]], '"engine" doesn\'t apply to an app service.'],
    'deploy on a database' => [['version' => 1, 'services' => ['db' => ['kind' => 'database', 'engine' => 'postgres', 'version' => '18', 'deploy' => ['port' => 1]]]], 'Databases are run by their engine driver'],
    'two database replicas' => [['version' => 1, 'services' => ['db' => ['kind' => 'database', 'engine' => 'postgres', 'version' => '18', 'scaling' => ['replicas' => 2]]]], 'Databases run a single replica.'],
    'worker of nothing' => [['version' => 1, 'services' => ['queue' => ['kind' => 'worker', 'of' => 'api']]], 'There is no service "api".'],
    'worker of a database' => [['version' => 1, 'services' => ['db' => ['kind' => 'database', 'engine' => 'postgres', 'version' => '18'], 'queue' => ['kind' => 'worker', 'of' => 'db']]], 'db is a database: workers and crons run the image of an app.'],
    'connection typo' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'connections' => ['postgress']], 'postgres' => ['kind' => 'database', 'engine' => 'postgres', 'version' => '18']]], 'Did you mean "postgres"?'],
    'public database' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'connections' => [['to' => 'db', 'mode' => 'public']]], 'db' => ['kind' => 'database', 'engine' => 'postgres', 'version' => '18']]], 'Databases are only reachable on the private network.'],
    'unknown environment' => [['version' => 1, 'services' => ['web' => ['kind' => 'app']], 'environments' => ['stagin' => ['services' => ['web' => ['enabled' => false]]]]], 'There is no environment "stagin" in this project. | Did you mean "staging"?'],
    'override of an unknown service' => [['version' => 1, 'services' => ['web' => ['kind' => 'app']], 'environments' => ['staging' => ['services' => ['api' => ['enabled' => false]]]]], '"api" isn\'t in services'],
    'kind change' => [['version' => 1, 'services' => ['web' => ['kind' => 'worker', 'of' => 'web']]], 'web is already an app service; its kind can\'t change.', new ConfigContext(['web' => ['kind' => ServiceKind::App, 'engine' => null, 'version' => null]], ['production'])],
    'engine change' => [['version' => 1, 'services' => ['db' => ['kind' => 'database', 'engine' => 'mysql', 'version' => '8']]], 'db runs postgres; the engine can\'t change.', new ConfigContext(['db' => ['kind' => ServiceKind::Database, 'engine' => 'postgres', 'version' => '18']], ['production'])],
    'unknown expression' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['X' => '${{ nope() }}']]]], 'X has an expression launchroom doesn\'t know'],
    'browser secret' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['NEXT_PUBLIC_KEY' => ['secret' => true]]]]], 'baked into the browser bundle'],
    'volume in /proc' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'volumes' => ['/proc/x']]]], 'can\'t be a volume'],
    'root directory escapes' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'rootDirectory' => '../etc']]], 'leaves the repository'],
    'domain twice' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'domains' => ['a.example.com', 'A.example.com']]]], 'A.example.com is listed twice.'],
    'schedule on an app' => [['version' => 1, 'services' => ['web' => ['kind' => 'app', 'deploy' => ['schedule' => ['cron' => '0 3 * * *']]]]], '"schedule" only applies to cron services.'],
    'cron out of range' => [['version' => 1, 'services' => ['web' => ['kind' => 'app'], 'nightly' => ['kind' => 'cron', 'of' => 'web', 'deploy' => ['startCommand' => ['true'], 'schedule' => ['cron' => '0 25 * * *']]]]], 'Hour 25 is outside 0-23.'],
    'unknown time zone' => [['version' => 1, 'services' => ['web' => ['kind' => 'app'], 'nightly' => ['kind' => 'cron', 'of' => 'web', 'deploy' => ['startCommand' => ['true'], 'schedule' => ['cron' => '0 3 * * *', 'timeZone' => 'Berlin']]]]], '"Berlin" isn\'t a time zone.'],
]);

test('schedules, entrypoints and capabilities are valid deploy settings', function (): void {
    $check = cfvCheck(['version' => 1, 'services' => [
        'web' => ['kind' => 'app', 'deploy' => ['entrypoint' => ['/docker-entrypoint.sh'], 'capabilities' => ['NET_BIND_SERVICE']]],
        'nightly' => ['kind' => 'cron', 'of' => 'web', 'deploy' => ['startCommand' => ['php', 'artisan', 'report'], 'schedule' => ['cron' => '0 3 * * 1-5', 'timeZone' => 'Europe/Berlin']]],
    ]]);

    expect($check->errors)->toBe([])
        ->and(cfvMessages(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'deploy' => ['capabilities' => ['SYS_ADMIN']]]]]))->not->toBe([]);
});

test('secret values are refused and never stored in the excerpt', function (): void {
    $key = 'sk_live_'.str_repeat('a1B2', 6);
    $check = cfvCheck("{\n  \"version\": 1,\n  \"services\": {\n    \"web\": { \"kind\": \"app\", \"variables\": { \"STRIPE\": \"{$key}\", \"DB\": \"postgres://u:\${{postgres.PASSWORD}}@db/app\" } }\n  }\n}");

    expect($check->errors)->toHaveCount(1)
        ->and($check->errors[0]->message)->toContain('STRIPE looks like a secret')
        ->and(json_encode($check->errors[0]->toArray()))->not->toContain($key)
        ->and($check->errors[0]->excerpt[2]->text)->toContain('"STRIPE": "••••••"');
});

test('keys that usually hold secrets warn when they have a literal value', function (): void {
    $check = cfvCheck(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['SESSION_SECRET' => 'abc', 'API_TOKEN' => '${{api.TOKEN}}']]]]);

    expect($check->valid())->toBeTrue()
        ->and($check->warnings)->toHaveCount(1)
        ->and($check->warnings[0]->message)->toContain('SESSION_SECRET usually holds a secret');
});

test('the schema matches the settings Data classes', function (): void {
    $schema = json_decode((string) file_get_contents(ConfigSchema::path()), true);
    $defs = $schema['$defs'];

    expect($defs['build']['properties']['builder']['enum'])->toBe(['auto', 'dockerfile', 'railpack', 'static'])
        ->and($defs['healthcheck']['properties']['type']['enum'])->toBe(array_map(static fn (HealthcheckType $case) => $case->value, HealthcheckType::cases()))
        ->and($defs['placement']['properties']['strategy']['enum'])->toBe(array_map(static fn (PlacementStrategy $case) => $case->value, PlacementStrategy::cases()))
        ->and($defs['service']['properties']['kind']['enum'])->toBe(array_map(static fn (ServiceKind $case) => $case->value, ServiceKind::cases()))
        ->and($defs['service']['properties']['engine']['enum'])->toBe(array_map(static fn (DatabaseEngine $case) => $case->value, DatabaseEngine::cases()))
        ->and($defs['connection']['anyOf'][1]['properties']['style']['enum'])->toBe(array_map(static fn (ConnectionStyle $case) => $case->value, ConnectionStyle::cases()))
        ->and($defs['healthcheck']['properties']['path']['pattern'])->toBe(str_replace('\\/', '/', substr(HealthcheckSettings::PATH_PATTERN, 1, -2)))
        ->and($defs['slug']['pattern'])->toBe(substr(Slug::PATTERN, 1, -2))
        ->and($defs['slug']['not']['enum'])->toBe(Slug::RESERVED)
        ->and($defs['deploy']['properties']['port']['maximum'])->toBe(65535)
        ->and($defs['scaling']['properties']['replicas']['maximum'])->toBe(64);
});

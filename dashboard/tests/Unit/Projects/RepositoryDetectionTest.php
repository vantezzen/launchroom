<?php

declare(strict_types=1);

use App\Enums\DatabaseEngine;
use App\Support\Projects\DatabaseSuggestions;
use App\Support\Projects\RepositoryLayout;

test('a single app is the repository root with its .env.example', function (): void {
    $apps = RepositoryLayout::apps(['composer.json', 'package.json', 'app/Models/User.php', '.env.example', 'database/seeders/x.php'], 'shop');

    expect($apps)->toBe([
        ['rootDirectory' => '', 'name' => 'shop', 'runtime' => 'php', 'selected' => true, 'envExample' => '.env.example'],
    ]);
});

test('monorepo apps are listed; libraries, tooling and dependencies are not', function (): void {
    $apps = RepositoryLayout::apps([
        'package.json', 'pnpm-workspace.yaml',
        'apps/web/package.json', 'apps/web/.env.example',
        'apps/api/composer.json', 'apps/api/Dockerfile',
        'apps/docs/package.json',
        'packages/ui/package.json',
        'node_modules/react/package.json',
        'apps/web/node_modules/x/package.json',
        '.github/actions/x/package.json',
        'tools/codegen/package.json',
    ], 'shop');

    expect(array_column($apps, 'rootDirectory'))->toBe(['apps/api', 'apps/docs', 'apps/web'])
        ->and(array_column($apps, 'runtime'))->toBe(['php', 'node', 'node'])
        ->and(array_column($apps, 'selected'))->toBe([true, false, true])
        ->and($apps[2]['envExample'])->toBe('apps/web/.env.example');
});

test('top-level app folders count; a lone docs site keeps the root as the app', function (): void {
    expect(array_column(RepositoryLayout::apps(['frontend/package.json', 'backend/go.mod', 'README.md'], 'x'), 'rootDirectory'))->toBe(['backend', 'frontend'])
        ->and(array_column(RepositoryLayout::apps(['composer.json', 'docs/package.json'], 'x'), 'rootDirectory'))->toBe(['', 'docs'])
        ->and(RepositoryLayout::apps(['README.md'], 'x'))->toBe([
            ['rootDirectory' => '', 'name' => 'x', 'runtime' => 'unknown', 'selected' => true, 'envExample' => null],
        ]);
});

test('.env.example keys are parsed without keeping more than the value of selectors', function (): void {
    $variables = DatabaseSuggestions::parseEnvExample("# comment\nexport APP_KEY=\nDB_CONNECTION=\"pgsql\" # db\nREDIS_HOST=127.0.0.1\n  not a line\n");

    expect(array_keys($variables))->toBe(['APP_KEY', 'DB_CONNECTION', 'REDIS_HOST'])
        ->and($variables['DB_CONNECTION'])->toBe('pgsql');
});

test('databases are suggested only from unmistakable variables', function (array $env, array $engines): void {
    expect(array_map(static fn (array $s): string => $s['engine']->value, DatabaseSuggestions::fromVariables($env)))->toBe($engines);
})->with([
    'laravel pgsql + redis' => [['DB_CONNECTION' => 'pgsql', 'REDIS_HOST' => '127.0.0.1'], ['postgres', 'redis']],
    'laravel mysql' => [['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1'], ['mysql']],
    'laravel sqlite default' => [['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'x'], []],
    'prisma postgres url' => [['DATABASE_URL' => 'postgresql://u:p@localhost/db'], ['postgres']],
    'mysql url' => [['DATABASE_URL' => 'mysql://u:p@localhost/db'], ['mysql']],
    'sqlite url' => [['DATABASE_URL' => 'file:./dev.db'], []],
    'valkey' => [['VALKEY_URL' => 'redis://x'], ['valkey']],
    'redis queue' => [['QUEUE_CONNECTION' => 'redis'], ['redis']],
    'nothing' => [['APP_NAME' => 'x', 'STRIPE_KEY' => ''], []],
]);

test('suggestions say why', function (): void {
    expect(DatabaseSuggestions::fromVariables(['DATABASE_URL' => 'postgres://x'])[0])
        ->toBe(['engine' => DatabaseEngine::Postgres, 'reason' => 'DATABASE_URL in .env.example']);
});

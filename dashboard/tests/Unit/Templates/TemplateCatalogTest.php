<?php

declare(strict_types=1);

use App\Data\Templates\TemplateSummaryData;
use App\Enums\ServiceKind;
use App\Services\Blueprints\EngineVersions;
use App\Services\Templates\TemplateCatalog;
use App\Support\Compose\ComposeTranslation;
use App\Support\Templates\TemplateCompiler;
use App\Support\Templates\TemplateDefinition;
use App\Support\Templates\TemplateInvalid;

function tplEngines(): array
{
    return resolve(EngineVersions::class)->all();
}

function tplParse(string $yaml, string $id = 'demo'): TemplateDefinition
{
    return TemplateCompiler::parse($yaml, $id);
}

const TPL_DEMO = <<<'YAML'
schema: launchroom.template/v1
id: demo
name: Demo
tagline: A demo.
description: A demo template.
category: analytics
logo: umami
inputs:
  - {id: domain, label: Domain, type: domain, optional: true}
  - {id: email, label: Admin email, type: email}
services:
  web:
    image: ghcr.io/umami-software/umami:3.4.0@sha256:85909afc45bdcda1917394594a087421fdbb05610fded0fa9f6fb861abb2f367
    ports: ["3000"]
    environment:
      DATABASE_URL: ${service.db.url}
      DB_HOST: ${service.db.host}:${service.db.port}
      SECRET: ${generate:password:40}
      KEY: ${generate:base64:32}
      ID: ${generate:uuid}
      ADMIN: ${input.email}
      URL: ${self.public_url}
    depends_on: [db]
    x-launchroom: {name: Web, logo: umami, domain: "${input.domain}"}
  db:
    x-launchroom: {name: Postgres, database: {engine: postgres, version: "17"}}
YAML;

test('every shipped template is valid (make lint-templates)', function (): void {
    $results = resolve(TemplateCatalog::class)->lint();

    expect($results)->not->toBeEmpty()
        ->and(array_filter($results))->toBe([]);
});

test('the catalog lists every template with a picture of what it creates', function (): void {
    $summaries = resolve(TemplateCatalog::class)->summaries();
    $ids = array_map(static fn (TemplateSummaryData $summary): string => $summary->id, $summaries);

    expect($ids)->toContain('umami', 'uptime-kuma', 'n8n', 'ghost', 'gitea', 'versitygw', 'meilisearch', 'pocketbase', 'metabase', 'plausible', 'nextjs-postgres', 'laravel-mysql-redis', 'django-postgres-celery', 'rails-postgres-sidekiq')
        ->and($ids[0])->toBe('django-postgres-celery');

    foreach ($summaries as $summary) {
        expect($summary->preview->services)->not->toBeEmpty()
            ->and(json_encode($summary->toArray()))->not->toContain('secret(');
    }
});

test('expressions become generators and references', function (): void {
    $blueprint = TemplateCompiler::compile(tplParse(TPL_DEMO), ['email' => 'ops@example.com', 'domain' => 'Stats.Example.com'], tplEngines())->blueprint;
    $web = $blueprint->service('web');
    $variables = [];

    foreach ($web->variables as $variable) {
        $variables[$variable->key] = [$variable->value, $variable->secret];
    }

    expect($variables)->toBe([
        'DATABASE_URL' => ['${{db.DATABASE_URL}}', false],
        'DB_HOST' => ['${{db.PGHOST}}:${{db.PGPORT}}', false],
        'SECRET' => ['${{ secret(40) }}', true],
        'KEY' => ['${{ secret(32, "base64") }}', true],
        'ID' => ['${{ uuid() }}', true],
        'ADMIN' => ['ops@example.com', false],
        'URL' => ['${{PUBLIC_URL}}', false],
    ])
        ->and($web->name)->toBe('Web')
        ->and($web->domain)->toBe('stats.example.com')
        ->and($blueprint->service('db')->kind)->toBe(ServiceKind::Database)
        ->and($blueprint->service('db')->name)->toBe('Postgres');
});

test('inputs are validated by type', function (array $inputs, string $problem): void {
    expect(fn (): ComposeTranslation => TemplateCompiler::compile(tplParse(TPL_DEMO), $inputs, tplEngines()))->toThrow(TemplateInvalid::class, $problem);
})->with([
    [[], 'Admin email is required'],
    [['email' => 'not-an-email'], 'enter an email address'],
    [['email' => 'a@b.co', 'domain' => 'under_score.example.com'], 'enter a domain'],
]);

test('templates that the allow-list wouldn’t run are invalid', function (string $from, string $to, string $problem): void {
    $problems = TemplateCompiler::lint(tplParse(str_replace($from, $to, TPL_DEMO)), tplEngines());

    expect(implode("\n", $problems))->toContain($problem);
})->with([
    'unpinned image' => ['@sha256:85909afc45bdcda1917394594a087421fdbb05610fded0fa9f6fb861abb2f367', '', 'pinned by digest'],
    'refused key' => ['    depends_on: [db]', "    depends_on: [db]\n    privileged: true", 'privileged is refused'],
    'ignored key' => ['    depends_on: [db]', "    depends_on: [db]\n    restart: always", 'always restarts'],
    'unknown expression' => ['${generate:uuid}', '${generate:nonsense}', 'isn’t a template expression'],
    'unknown service' => ['${service.db.url}', '${service.cache.url}', 'isn’t a template expression'],
    'expression in a command' => ['    depends_on: [db]', "    depends_on: [db]\n    command: [\"run\", \"\${generate:password:20}\"]", 'only allowed in environment values'],
    'unknown logo' => ['logo: umami', 'logo: brandnew', 'isn’t a known glyph'],
    'unused input' => ['ADMIN: ${input.email}', 'ADMIN: x', 'never used'],
]);

test('the file must follow the schema', function (string $from, string $to, string $problem): void {
    expect(fn (): TemplateDefinition => tplParse(str_replace($from, $to, TPL_DEMO)))->toThrow(TemplateInvalid::class, $problem);
})->with([
    ['schema: launchroom.template/v1', 'schema: other', 'schema must be'],
    ['id: demo', 'id: other', 'id must be'],
    ['category: analytics', 'category: games', 'Unknown category'],
    ['tagline: A demo.', 'slogan: A demo.', 'Unknown key'],
]);

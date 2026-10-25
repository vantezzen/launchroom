<?php

declare(strict_types=1);

use App\Data\Blueprints\BlueprintConnection;
use App\Data\Blueprints\BlueprintNote;
use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\BlueprintVariable;
use App\Data\Blueprints\ComposeInputData;
use App\Data\Blueprints\SkippedServiceData;
use App\Data\Services\ConfigFile;
use App\Enums\BlueprintNoteKind;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\HealthcheckType;
use App\Enums\ServiceKind;
use App\Services\Blueprints\EngineVersions;
use App\Support\Blueprints\BlueprintValidator;
use App\Support\Compose\ComposeRejected;
use App\Support\Compose\ComposeTranslation;
use App\Support\Compose\ComposeTranslator;
use App\Support\Compose\TranslationOptions;

function bpCompose(string $fixtureOrYaml, array $options = []): ComposeTranslation
{
    $path = base_path('tests/Fixtures/compose/'.$fixtureOrYaml);
    $yaml = is_file($path) ? (string) file_get_contents($path) : $fixtureOrYaml;

    return ComposeTranslator::translate($yaml, new TranslationOptions(...[
        'repository' => true,
        'engines' => resolve(EngineVersions::class)->all(),
        ...$options,
    ]));
}

function bpService(ComposeTranslation $translation, string $key): BlueprintService
{
    $service = $translation->blueprint->service($key);
    expect($service)->not->toBeNull("No service {$key}");

    return $service;
}

/**
 * @return list<string>
 */
function bpNotes(BlueprintService $service, BlueprintNoteKind $kind): array
{
    return array_values(array_map(static fn (BlueprintNote $note): string => $note->message, array_filter($service->notes, static fn (BlueprintNote $note): bool => $note->kind === $kind)));
}

function bpVariable(BlueprintService $service, string $key): ?string
{
    foreach ($service->variables as $variable) {
        if ($variable->key === $key) {
            return $variable->value;
        }
    }

    return null;
}

test('every fixture translates into a valid blueprint', function (string $fixture): void {
    $translation = bpCompose($fixture);

    expect(BlueprintValidator::errors($translation->blueprint))->toBe([]);
})->with(array_map(basename(...), glob(__DIR__.'/../../Fixtures/compose/*.yml') ?: []));

test('a Node API with Postgres and Redis becomes an app, a worker and two managed databases', function (): void {
    $translation = bpCompose('node-api.yml');
    $api = bpService($translation, 'api');
    $queue = bpService($translation, 'queue');

    expect($translation->blueprint->name)->toBe('shop')
        ->and($api->kind)->toBe(ServiceKind::App)
        ->and($api->build?->context)->toBe('api')
        ->and($api->build?->dockerfile)->toBe('Dockerfile')
        ->and($api->port)->toBe(3000)
        ->and($api->public)->toBeTrue()
        ->and($api->startCommand)->toBe(['node', 'dist/server.js'])
        ->and($api->healthcheck?->type)->toBe(HealthcheckType::Http)
        ->and($api->healthcheck?->path)->toBe('/healthz')
        ->and($api->healthcheck?->intervalSeconds)->toBe(10)
        ->and($api->healthcheck?->startPeriodSeconds)->toBe(20)
        ->and($api->healthcheck?->failureThreshold)->toBe(5)
        ->and($api->scaling?->replicas)->toBe(2)
        ->and($api->scaling?->cpus)->toBe(0.5)
        ->and($api->scaling?->memoryMb)->toBe(512)
        ->and($queue->kind)->toBe(ServiceKind::Worker)
        ->and($queue->parent)->toBe('api')
        ->and($queue->startCommand)->toBe(['node', 'dist/worker.js'])
        ->and(bpService($translation, 'db')->database?->engine)->toBe(DatabaseEngine::Postgres)
        ->and(bpService($translation, 'db')->database?->version)->toBe('16')
        ->and(bpService($translation, 'cache')->database?->engine)->toBe(DatabaseEngine::Redis);

    // Credentials the app repeated now point at the generated ones (scheme and query kept).
    expect(bpVariable($api, 'DATABASE_URL'))->toBe('postgres://${{db.PGUSER}}:${{db.PGPASSWORD}}@${{db.PGHOST}}:${{db.PGPORT}}/${{db.PGDATABASE}}?sslmode=disable')
        ->and(bpVariable($api, 'REDIS_URL'))->toBe('redis://default:${{cache.REDIS_PASSWORD}}@${{cache.REDIS_HOST}}:${{cache.REDIS_PORT}}/0')
        ->and(bpVariable($api, 'SESSION_SECRET'))->toBe('${{shared.SESSION_SECRET}}')
        ->and(bpVariable($api, 'PUBLIC_HOST'))->toBe('shop.example.com')
        ->and(array_map(static fn (BlueprintConnection $c): string => $c->to, $api->connections))->toBe(['db', 'cache']);

    // Only the secret that is still used becomes a shared, generated variable.
    expect(array_map(static fn (BlueprintVariable $v): array => [$v->key, $v->value, $v->secret], $translation->blueprint->sharedVariables))
        ->toBe([['SESSION_SECRET', '${{ secret(32) }}', true]])
        ->and(array_map(static fn (ComposeInputData $input): string => $input->name.':'.$input->status, $translation->inputs))->toBe(['SESSION_SECRET:generated']);
});

test('the notes point at their lines', function (): void {
    $api = bpService(bpCompose('node-api.yml'), 'api');
    $byField = [];

    foreach ($api->notes as $note) {
        $byField[$note->field] = $note->line;
    }

    expect($byField['services.api.ports.0'])->toBe(11)
        ->and($byField['services.api.restart'])->toBe(29)
        ->and($byField['services.api.deploy.replicas'])->toBe(31);
});

test('WordPress keeps MariaDB as a container unless a managed database is chosen', function (): void {
    $translation = bpCompose('wordpress.yml');
    $db = bpService($translation, 'db');

    expect($db->kind)->toBe(ServiceKind::Image)
        ->and($db->public)->toBeFalse()
        ->and($db->port)->toBe(3306)
        ->and($db->volumes)->toBe(['/var/lib/mysql'])
        ->and($db->capabilities)->toContain('SETUID')
        ->and($db->healthcheck?->type)->toBe(HealthcheckType::Tcp)
        ->and($translation->choices['db']->engine)->toBe(DatabaseEngine::MySql)
        ->and($translation->choices['db']->managed)->toBeFalse()
        ->and(bpNotes(bpService($translation, 'wordpress'), BlueprintNoteKind::Refused)[0])->toContain('./uploads.ini');

    $managed = bpCompose('wordpress.yml', ['databases' => ['db' => 'managed']]);

    expect(bpService($managed, 'db')->kind)->toBe(ServiceKind::Database)
        ->and(bpService($managed, 'db')->database?->engine)->toBe(DatabaseEngine::MySql)
        ->and(bpVariable(bpService($managed, 'wordpress'), 'WORDPRESS_DB_PASSWORD'))->toBe('${{db.MYSQL_PASSWORD}}')
        ->and(bpVariable(bpService($managed, 'wordpress'), 'WORDPRESS_DB_USER'))->toBe('${{db.MYSQL_USER}}')
        ->and(bpVariable(bpService($managed, 'wordpress'), 'WORDPRESS_DB_HOST'))->toBe('${{db.MYSQLHOST}}');
});

test('Postgres can stay a plain container too', function (): void {
    $translation = bpCompose('node-api.yml', ['databases' => ['db' => 'container']]);
    $db = bpService($translation, 'db');

    expect($db->kind)->toBe(ServiceKind::Image)
        ->and($db->image)->toBe('postgres:16-alpine')
        ->and($db->volumes)->toBe(['/var/lib/postgresql/data'])
        ->and(bpVariable($db, 'POSTGRES_PASSWORD'))->toBe('${{shared.POSTGRES_PASSWORD}}')
        ->and(bpVariable(bpService($translation, 'api'), 'DATABASE_URL'))->toBe('postgres://shop:${{shared.POSTGRES_PASSWORD}}@db:5432/shop?sslmode=disable');
});

test('anchors, merge keys, env files and profiles (Django + Celery)', function (): void {
    $translation = bpCompose('django-celery.yml', ['files' => ['.env' => "DEBUG=0\n"]]);

    expect(array_map(static fn (BlueprintService $s): string => $s->key.':'.$s->kind->value, $translation->blueprint->services))
        ->toBe(['web:app', 'celery:worker', 'beat:worker', 'postgres:database', 'broker:database'])
        ->and($translation->skipped[0]->name)->toBe('flower')
        ->and(bpService($translation, 'broker')->database?->engine)->toBe(DatabaseEngine::Valkey)
        ->and(bpVariable(bpService($translation, 'web'), 'DEBUG'))->toBe('0')
        ->and(bpVariable(bpService($translation, 'celery'), 'DATABASE_URL'))->toStartWith('postgresql+psycopg://${{postgres.PGUSER}}')
        ->and(bpService($translation, 'beat')->startCommand)->toBe(['celery', '-A', 'config', 'beat', '-l', 'info'])
        ->and(bpService($translation, 'web')->healthcheck?->path)->toBe('/health/');

    $withoutEnv = bpCompose('django-celery.yml');
    expect($withoutEnv->missingFiles)->toBe(['.env']);
});

test('Laravel: Laravel-style connections, MySQL 8.0 becomes 8.4, workers share the build', function (): void {
    $translation = bpCompose('laravel.yml');
    $app = bpService($translation, 'app');

    expect($app->port)->toBe(8080)
        ->and($app->build?->dockerfile)->toBe('docker/Dockerfile')
        ->and(array_map(static fn (BlueprintConnection $c): string => $c->style->value, $app->connections))->toBe([ConnectionStyle::Laravel->value, ConnectionStyle::Laravel->value])
        ->and(bpVariable($app, 'DB_PASSWORD'))->toBe('${{mysql.MYSQL_PASSWORD}}')
        ->and(bpVariable($app, 'DB_HOST'))->toBe('${{mysql.MYSQLHOST}}')
        ->and(bpVariable($app, 'MAIL_MAILER'))->toBe('log')
        ->and(bpService($translation, 'mysql')->database?->version)->toBe('8.4')
        ->and(bpService($translation, 'queue')->kind)->toBe(ServiceKind::Worker);
});

test('builds need a repository: pasted files skip them and say so', function (): void {
    $translation = bpCompose('laravel.yml', ['repository' => false]);

    expect(array_map(static fn (SkippedServiceData $s): string => $s->name, $translation->skipped))->toBe(['app', 'queue'])
        ->and($translation->skipped[0]->needsRepository)->toBeTrue()
        ->and($translation->blueprint->service('mysql'))->not->toBeNull();
});

test('host access is refused line by line, the rest still imports', function (): void {
    $translation = bpCompose('media-server.yml');
    $jellyfin = bpService($translation, 'jellyfin');
    $refused = implode("\n", bpNotes($jellyfin, BlueprintNoteKind::Refused));

    expect($refused)->toContain('network_mode: host is refused')
        ->toContain('devices is refused')
        ->toContain('group_add is refused')
        ->toContain('extra_hosts is refused')
        ->toContain('/srv/media is a folder on the server')
        ->and($jellyfin->volumes)->toBe(['/config', '/cache'])
        ->and($translation->skipped[0]->reason)->toContain('lscr.io');
});

test('privileged, pid, capabilities, security options and the Docker socket never reach a blueprint', function (): void {
    $translation = bpCompose(<<<'YAML'
    services:
      agent:
        image: alpine:3.22
        privileged: true
        pid: host
        ipc: host
        cap_add: [SYS_ADMIN, NET_ADMIN, CHOWN, CAP_SETUID]
        security_opt: [seccomp:unconfined]
        sysctls: {net.ipv4.ip_forward: 1}
        devices: [/dev/fuse]
        volumes:
          - /var/run/docker.sock:/var/run/docker.sock
          - /:/host
          - /etc/localtime:/etc/localtime:ro
        ports: ["53:53/udp", "2222:22", "8000-8010:8000-8010"]
    YAML);
    $agent = bpService($translation, 'agent');

    expect($agent->capabilities)->toBe(['CHOWN', 'SETUID'])
        ->and($agent->volumes)->toBe([])
        ->and($agent->port)->toBeNull()
        ->and(bpNotes($agent, BlueprintNoteKind::Refused))->toHaveCount(14);
});

test('bind mounts of folders become volumes only when asked to', function (): void {
    $yaml = "services:\n  app:\n    image: nginx:1.29\n    volumes: ['./data:/data']\n";

    expect(bpService(bpCompose($yaml), 'app')->volumes)->toBe(['/data'])
        ->and(bpCompose($yaml)->choices['app']->bindMounts)->toBe(1)
        ->and(bpService(bpCompose($yaml, ['convertBindMounts' => false]), 'app')->volumes)->toBe([])
        ->and(bpNotes(bpService(bpCompose($yaml, ['convertBindMounts' => false]), 'app'), BlueprintNoteKind::Refused)[0])->toContain('Convert to volumes');
});

test('a volume belongs to one service', function (): void {
    $translation = bpCompose("services:\n  a:\n    image: nginx:1.29\n    volumes: [shared:/data]\n  b:\n    image: nginx:1.29\n    volumes: [shared:/data]\nvolumes:\n  shared:\n    driver_opts: {type: none, o: bind, device: /srv}\n");

    expect(bpService($translation, 'a')->volumes)->toBe(['/data'])
        ->and(bpService($translation, 'b')->volumes)->toBe([])
        ->and($translation->notes[0]->kind)->toBe(BlueprintNoteKind::Refused)
        ->and($translation->notes[0]->message)->toContain('driver_opts');
});

test('string commands with shell syntax are refused; lists stay argv', function (): void {
    $translation = bpCompose("services:\n  a:\n    image: node:22\n    command: npm run migrate && npm start\n  b:\n    image: node:22\n    command: ['sh', '-c', 'npm run migrate && npm start']\n");

    expect(bpService($translation, 'a')->startCommand)->toBeNull()
        ->and(bpNotes(bpService($translation, 'a'), BlueprintNoteKind::Refused)[0])->toContain('Write it as a list')
        ->and(bpService($translation, 'b')->startCommand)->toBe(['sh', '-c', 'npm run migrate && npm start']);
});

test('services, entrypoints, host names and Swarm settings', function (): void {
    $translation = bpCompose('shell-commands.yml');
    $api = bpService($translation, 'api');
    $backend = bpService($translation, 'backend-api');

    expect($api->entrypoint)->toBe(['/usr/local/bin/docker-entrypoint.sh'])
        ->and($api->healthcheck?->type)->toBe(HealthcheckType::Tcp)
        ->and($api->healthcheck?->intervalSeconds)->toBe(90)
        ->and(bpVariable($api, 'API_URL'))->toBe('http://backend-api:4000')
        ->and($backend->public)->toBeFalse()
        ->and($backend->port)->toBe(4000)
        ->and($backend->scaling?->replicas)->toBe(3)
        ->and($backend->image)->toEndWith('@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef')
        ->and(bpNotes($backend, BlueprintNoteKind::Refused))->toHaveCount(2);
});

test('proxies and their labels are explained', function (): void {
    $translation = bpCompose('traefik-stack.yml');

    expect(bpNotes(bpService($translation, 'traefik'), BlueprintNoteKind::Warning)[0])->toContain('already routes domains')
        ->and(bpNotes(bpService($translation, 'traefik'), BlueprintNoteKind::Refused)[0])->toContain('Docker socket')
        ->and(bpService($translation, 'app')->public)->toBeTrue()
        ->and(implode(' ', bpNotes(bpService($translation, 'app'), BlueprintNoteKind::Warning)))->toContain('notes.example.com');
});

test('interpolation uses the user’s .env and shared variables', function (): void {
    $translation = bpCompose('monitoring.yml', ['values' => ['GRAFANA_PASSWORD' => 'from-env']]);

    expect($translation->blueprint->sharedVariables[0]->value)->toBe('from-env')
        ->and($translation->blueprint->sharedVariables[0]->secret)->toBeTrue()
        ->and($translation->inputs[0]->status)->toBe('provided');
});

test('required values nobody set are listed, not guessed', function (): void {
    $translation = bpCompose("services:\n  a:\n    image: nginx:1.29\n    environment:\n      SITE: \${SITE_NAME:?name your site}\n");

    expect($translation->inputs[0]->toArray())->toMatchArray(['name' => 'SITE_NAME', 'status' => 'missing', 'required' => true, 'message' => 'name your site'])
        ->and($translation->notes[0]->kind)->toBe(BlueprintNoteKind::Warning);
});

test('only Docker Hub and GitHub images, and only valid ones', function (): void {
    $translation = bpCompose("services:\n  a:\n    image: quay.io/org/app:1\n  b:\n    image: 'Not An Image'\n  c:\n    image: ghcr.io/org/app:1\n");

    expect(array_map(static fn (SkippedServiceData $s): string => $s->name, $translation->skipped))->toBe(['a', 'b'])
        ->and($translation->blueprint->service('c'))->not->toBeNull();
});

test('a file without services is rejected', function (string $yaml): void {
    expect(fn (): ComposeTranslation => bpCompose($yaml))->toThrow(ComposeRejected::class);
})->with(["version: '3'\n", "services: []\n", "services:\n  a: just-a-string\n"]);

test('MongoDB and ClickHouse images become managed databases and consumers use their credentials (D47)', function (): void {
    $translation = bpCompose('mongo-clickhouse.yml');
    $mongo = bpService($translation, 'mongo');
    $analytics = bpService($translation, 'analytics');
    $api = bpService($translation, 'api');

    expect($mongo->kind)->toBe(ServiceKind::Database)
        ->and($mongo->database?->engine)->toBe(DatabaseEngine::MongoDb)
        ->and($mongo->database?->version)->toBe('7.0')
        ->and($analytics->kind)->toBe(ServiceKind::Database)
        ->and($analytics->database?->engine)->toBe(DatabaseEngine::ClickHouse)
        // 24.8 isn't offered: the oldest offered at least as new.
        ->and($analytics->database?->version)->toBe('26.3')
        ->and(bpVariable($api, 'MONGO_URL'))->toBe('mongodb://${{mongo.MONGOUSER}}:${{mongo.MONGOPASSWORD}}@${{mongo.MONGOHOST}}:${{mongo.MONGOPORT}}/${{mongo.MONGODATABASE}}?authSource=admin')
        ->and(bpVariable($api, 'MONGO_PASSWORD'))->toBe('${{mongo.MONGOPASSWORD}}')
        ->and(bpVariable($api, 'CLICKHOUSE_URL'))->toBe('http://${{analytics.CLICKHOUSE_USER}}:${{analytics.CLICKHOUSE_PASSWORD}}@${{analytics.CLICKHOUSE_HOST}}:${{analytics.CLICKHOUSE_PORT}}/${{analytics.CLICKHOUSE_DATABASE}}')
        ->and(bpVariable($api, 'CLICKHOUSE_NATIVE'))->toContain(':${{analytics.CLICKHOUSE_NATIVE_PORT}}/')
        ->and(bpVariable($api, 'CLICKHOUSE_HOST'))->toBe('${{analytics.CLICKHOUSE_HOST}}');

    $kept = bpCompose('mongo-clickhouse.yml', ['databases' => ['mongo' => 'container', 'analytics' => 'container']]);

    expect(bpService($kept, 'mongo')->kind)->toBe(ServiceKind::Image)
        ->and(bpService($kept, 'mongo')->port)->toBe(27017)
        ->and(bpService($kept, 'analytics')->port)->toBe(8123);
});

test('configs with inline content become read-only config files (D65)', function (): void {
    $translation = bpCompose(<<<'YAML'
        services:
          db:
            image: supabase/postgres:17.6.1.136
            expose: ["5432"]
            volumes: [data:/var/lib/postgresql/data]
            configs:
              - source: roles
                target: /docker-entrypoint-initdb.d/init-scripts/99-roles.sql
              - source: settings
                target: /var/lib/postgresql/data/extra.conf
              - source: from_file
              - source: roles
                target: /etc/roles.sql
                mode: 0400
              - nope
        configs:
          roles:
            content: |
              \set pgpass `echo "$$POSTGRES_PASSWORD"`
              ALTER USER authenticator WITH PASSWORD :'pgpass';
          settings:
            content: "max_connections = 50"
          from_file:
            file: ./config.json
        volumes:
          data:
        YAML);
    $db = bpService($translation, 'db');

    expect(array_map(static fn (ConfigFile $file): string => $file->path, $db->files))->toBe(['/docker-entrypoint-initdb.d/init-scripts/99-roles.sql', '/etc/roles.sql'])
        // Compose interpolation: $$ is a literal $.
        ->and($db->files[0]->content)->toContain('echo "$POSTGRES_PASSWORD"')
        ->and(bpNotes($db, BlueprintNoteKind::Refused))->toHaveCount(3)
        ->and(implode(' ', bpNotes($db, BlueprintNoteKind::Refused)))->toContain('inside the volume')
        ->and(bpNotes($db, BlueprintNoteKind::Ignored)[0] ?? '')->toContain('mode is ignored')
        ->and(implode(' ', array_map(static fn (BlueprintNote $note): string => $note->message, $translation->notes)))->toContain('Config from_file is read from a file')
        // A private non-HTTP port stays the service's port (TCP health, reachable as db:5432).
        ->and($db->port)->toBe(5432)
        ->and($db->public)->toBeFalse()
        ->and(BlueprintValidator::errors($translation->blueprint))->toBe([]);
});

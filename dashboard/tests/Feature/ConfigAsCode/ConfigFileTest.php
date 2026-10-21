<?php

declare(strict_types=1);

use App\Actions\ConfigAsCode\ApplyConfigFile;
use App\Actions\ConfigAsCode\DryRunConfigFile;
use App\Actions\ConfigAsCode\ExportEnvironmentConfig;
use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Domains\AddDomain;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Services\AddServiceVolume;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Environments\EnvironmentTriggers;
use App\Data\Git\CommitData;
use App\Data\Services\AddVolumeData;
use App\Data\Services\BuildSettings;
use App\Data\Services\CreateServiceData;
use App\Data\Services\DeploySettings;
use App\Data\Services\GitSource;
use App\Data\Services\HealthcheckSettings;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Specs\ChangeItem;
use App\Data\Specs\StagedChanges;
use App\Data\Variables\VariableInput;
use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Enums\ConfigAsCode\ConfigTrust;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\Git\GitEventSource;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
use App\Events\Git\GitPushReceived;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Git\GitHubInstallation;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Variable;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\ConfigAsCode\ConfigApplyOutcome;
use App\Services\ConfigAsCode\ConfigExporter;
use App\Support\Changes\SpecDiff;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

const CF_SHA = '4444444444444444444444444444444444444444';

beforeEach(function (): void {
    Projects::useTestKek();
    Queue::fake();
    $GLOBALS['cf_file'] = null;
    $GLOBALS['cf_reads'] = [];
    // Before GitHubFake::setUp(): the first matching fake wins.
    Http::fake([
        'https://api.github.com/repos/octo-org/web/contents/*' => static function (Request $request) {
            $GLOBALS['cf_reads'][] = $request->url();

            return $GLOBALS['cf_file'] === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($GLOBALS['cf_file'], 200);
        },
        'https://api.github.com/*' => Http::response(['id' => 1, 'sha' => CF_SHA, 'token' => 'ghs_test', 'expires_at' => now()->addHour()->toIso8601String()], 201),
    ]);
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    Projects::server($this->team);
    $this->installation = GitHubFake::installation(GitHubFake::app($this->team));
    GitHubFake::repository($this->installation, 555, 'octo-org/web');

    $this->project = Projects::create($this->team);
    $this->source = ['type' => 'git', 'repositoryId' => 555, 'fullName' => 'octo-org/web', 'installationId' => $this->installation->id];
    $this->web = resolve(CreateService::class)->handle($this->project, CreateServiceData::from(['name' => 'web', 'kind' => ServiceKind::App, 'source' => $this->source, 'port' => 3000]), null, AuditActor::system());
    $this->production = $this->project->production()->sole();
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
});

function cfFile(array $file): void
{
    $GLOBALS['cf_file'] = json_encode(['version' => 1, ...$file], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function cfPush(Team $team, string $ref = 'refs/heads/main', ?array $files = null): void
{
    $installation = GitHubInstallation::query()->where('team_id', $team->id)->firstOrFail();
    event(new GitPushReceived($installation, 555, $ref, str_repeat('0', 40), CF_SHA, new CommitData(CF_SHA, 'ship config', 'Ada', 'ada@example.com', 'ada'), $files, false, GitEventSource::Webhook));
}

function cfInstance(Environment $environment, Service $service): ServiceInstance
{
    return ServiceInstance::query()->where('environment_id', $environment->id)->where('service_id', $service->id)->sole();
}

function cfStaged(Environment $environment): StagedChanges
{
    $environment->refresh()->load('lastAppliedRevision');

    return SpecDiff::between($environment->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($environment));
}

function cfApply(Environment $environment, string $content, ConfigTrust $trust = ConfigTrust::Trusted): ConfigApplyOutcome
{
    return resolve(ApplyConfigFile::class)->handle($environment, $content, 'launchroom.json', GitSource::from(['repositoryId' => 555, 'fullName' => 'octo-org/web', 'installationId' => GitHubInstallation::query()->value('id')]), CF_SHA, $trust, AuditActor::system());
}

test('export → import of an environment is a round trip without a single change', function (): void {
    $postgres = Projects::postgres($this->project);
    $worker = resolve(CreateService::class)->handle($this->project, CreateServiceData::from(['name' => 'Queue', 'slug' => 'queue', 'kind' => ServiceKind::Worker, 'parentServiceId' => $this->web->public_id, 'startCommand' => ['node', 'worker.js']]), null, AuditActor::system());
    $cron = resolve(CreateService::class)->handle($this->project, CreateServiceData::from(['name' => 'Nightly', 'slug' => 'nightly', 'kind' => ServiceKind::Cron, 'parentServiceId' => $this->web->public_id, 'startCommand' => ['node', 'report.js']]), null, AuditActor::system());
    $cronInstance = cfInstance($this->production, $cron);
    resolve(UpdateServiceSettings::class)->handle($cronInstance, UpdateServiceSettingsData::from(['deploy' => [...$cronInstance->deploy->toArray(), 'schedule' => ['cron' => '30 3 * * 1-5', 'timeZone' => 'Europe/Berlin']]]));
    $image = Projects::image($this->project, 'umami', 'ghcr.io/umami-software/umami:postgresql-latest', 3000);
    $instance = cfInstance($this->production, $this->web);
    resolve(UpdateServiceSettings::class)->handle($instance, new UpdateServiceSettingsData(
        build: new BuildSettings(builder: 'railpack', buildCommand: 'npm run build', watchPaths: ['apps/web']),
        deploy: new DeploySettings(port: 3000, startCommand: ['npm', 'start'], releaseCommand: ['npm', 'run', 'migrate'], healthcheck: new HealthcheckSettings(path: '/health', timeoutSeconds: 5), entrypoint: ['/usr/bin/tini', '--'], capabilities: ['NET_BIND_SERVICE']),
        scaling: new ScalingSettings(replicas: 2, cpus: 0.5, memoryMb: 1024),
        branchOverride: 'release',
    ));
    resolve(CreateVariable::class)->handle($this->project, $this->web, VariableInput::from(['key' => 'LOG_LEVEL', 'value' => 'info', 'type' => 'config', 'targets' => [['environmentId' => $this->production->public_id]]]), null, AuditActor::system());
    resolve(CreateVariable::class)->handle($this->project, $this->web, VariableInput::from(['key' => 'STRIPE_SECRET_KEY', 'value' => 'sk_live_abcdefghijklmnop', 'type' => 'secret', 'targets' => [['environmentId' => $this->production->public_id]]]), null, AuditActor::system());
    resolve(CreateVariable::class)->handle($this->project, $this->web, VariableInput::from(['key' => 'APP_KEY', 'value' => '${{ secret(32) }}', 'type' => 'secret', 'targets' => [['environmentId' => $this->production->public_id]]]), null, AuditActor::system());
    resolve(CreateConnection::class)->handle($this->web, $postgres, ConnectionMode::Private, ConnectionStyle::Laravel, null, AuditActor::system());
    resolve(AddDomain::class)->handle($instance, 'shop.example.com', AuditActor::system());
    resolve(AddServiceVolume::class)->handle($instance, new AddVolumeData('/data'), AuditActor::system());

    $export = resolve(ExportEnvironmentConfig::class)->handle($this->production, null, AuditActor::system());
    $file = json_decode($export->content, true);

    expect($export->content)->not->toContain('sk_live_abcdefghijklmnop')
        ->and($file['services']['web'])->toMatchArray([
            'kind' => 'app',
            'branch' => 'release',
            'build' => ['builder' => 'railpack', 'buildCommand' => 'npm run build', 'watchPaths' => ['apps/web']],
            'deploy' => ['port' => 3000, 'startCommand' => ['npm', 'start'], 'releaseCommand' => ['npm', 'run', 'migrate'], 'entrypoint' => ['/usr/bin/tini', '--'], 'capabilities' => ['NET_BIND_SERVICE'], 'healthcheck' => ['path' => '/health', 'timeoutSeconds' => 5]],
            'scaling' => ['replicas' => 2, 'cpus' => 0.5, 'memoryMb' => 1024],
            'variables' => ['APP_KEY' => '${{ secret(32) }}', 'LOG_LEVEL' => 'info', 'STRIPE_SECRET_KEY' => ['secret' => true]],
            'domains' => ['shop.example.com'],
            'volumes' => ['/data'],
            'connections' => [['to' => 'postgres', 'style' => 'laravel']],
        ])
        ->and($file['services']['postgres'])->toBe(['kind' => 'database', 'engine' => 'postgres', 'version' => '18'])
        ->and($file['services']['queue'])->toMatchArray(['kind' => 'worker', 'name' => 'Queue', 'of' => 'web', 'deploy' => ['startCommand' => ['node', 'worker.js']]])
        ->and($file['services']['nightly'])->toMatchArray(['kind' => 'cron', 'of' => 'web', 'deploy' => ['startCommand' => ['node', 'report.js'], 'schedule' => ['cron' => '30 3 * * 1-5', 'timeZone' => 'Europe/Berlin']]])
        ->and($file['services']['umami'])->toMatchArray(['kind' => 'image', 'image' => 'ghcr.io/umami-software/umami:postgresql-latest'])
        ->and($export->content)->toStartWith("{\n  \"\$schema\": \"https://launchroom.vantezzen.io/schema/launchroom.v1.json\",");

    $before = resolve(CurrentEnvironmentSpec::class)->get($this->production);
    $outcome = cfApply($this->production, $export->content);
    $after = resolve(CurrentEnvironmentSpec::class)->get($this->production);

    expect($outcome->status)->toBe(ConfigFileStatus::Unchanged)
        ->and($outcome->changed)->toBeFalse()
        ->and(SpecDiff::between($before, $after)->items)->toBe([])
        ->and($outcome->record->managed->service($this->web->public_id)?->fields)->toContain('build.buildCommand', 'deploy.healthcheck.path', 'deploy.entrypoint', 'deploy.capabilities', 'scaling.replicas', 'branch', 'connections')
        ->and($outcome->record->managed->service($cron->public_id)?->fields)->toContain('deploy.schedule')
        ->and($outcome->record->managed->service($this->web->public_id)?->variables)->toBe(['APP_KEY', 'LOG_LEVEL', 'STRIPE_SECRET_KEY'])
        ->and($outcome->record->managed->service($this->web->public_id)?->domains)->toBe(['shop.example.com'])
        ->and(ConfigExporter::encode(resolve(ConfigExporter::class)->export($this->project, $after)))->toBe($export->content);
});

test('a revision exports as it was deployed', function (): void {
    resolve(UpdateServiceSettings::class)->handle(cfInstance($this->production, $this->web), new UpdateServiceSettingsData(scaling: new ScalingSettings(replicas: 3)));
    $revision = EnvironmentRevision::query()->where('environment_id', $this->production->id)->sole();

    $current = json_decode(resolve(ExportEnvironmentConfig::class)->handle($this->production, null, AuditActor::system())->content, true);
    $deployed = resolve(ExportEnvironmentConfig::class)->handle($this->production, $revision, AuditActor::system());

    expect($current['services']['web']['scaling'])->toBe(['replicas' => 3])
        ->and(json_decode($deployed->content, true)['services']['web'])->not->toHaveKey('scaling')
        ->and($deployed->revision)->toBe(1);
});

test('a push deploys the file with its commit when nothing else is staged', function (): void {
    cfFile(['services' => [
        'web' => ['kind' => 'app', 'build' => ['buildCommand' => 'pnpm build'], 'variables' => ['LOG_LEVEL' => 'debug']],
        'redis' => ['kind' => 'database', 'engine' => 'valkey', 'version' => '8'],
    ]]);

    cfPush($this->team);

    $instance = cfInstance($this->production, $this->web);
    $revision = $this->production->refresh()->lastAppliedRevision;
    $deployments = Deployment::query()->where('revision_id', $revision?->id)->with('serviceInstance.service')->get();
    $web = $deployments->first(fn (Deployment $deployment): bool => $deployment->serviceInstance->service_id === $this->web->id);

    expect($instance->build->buildCommand)->toBe('pnpm build')
        ->and($revision?->message)->toBe('launchroom.json · 4444444')
        ->and($revision?->spec->serviceBySlug('redis'))->not->toBeNull()
        ->and($deployments)->toHaveCount(2)
        ->and($web?->commit_sha)->toBe(CF_SHA)
        ->and($web?->commit_message)->toBe('ship config')
        ->and(Deployment::query()->where('commit_sha', CF_SHA)->count())->toBe(1)
        ->and(cfStaged($this->production)->isEmpty())->toBeTrue()
        ->and(EnvironmentConfigFile::query()->sole()->status)->toBe(ConfigFileStatus::Applied)
        ->and($GLOBALS['cf_reads'][0])->toContain('contents/launchroom.json')->toContain('ref='.CF_SHA);
});

test('a push with an invalid file fails with the problems and stages nothing', function (): void {
    $GLOBALS['cf_file'] = "{\n  \"version\": 1,\n  \"services\": {\n    \"web\": { \"kind\": \"app\", \"deploy\": { \"port\": \"3000\" } }\n  }\n}\n";

    cfPush($this->team);

    $deployment = Deployment::query()->where('commit_sha', CF_SHA)->sole();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_class)->toBe(DeploymentFailureClass::ConfigError)
        ->and($deployment->failure_message)->toBe('launchroom.json has a problem: "port" should be a whole number. (line 4, column 49)')
        ->and($deployment->config_errors['errors'][0])->toMatchArray(['line' => 4, 'column' => 49, 'path' => 'services.web.deploy.port', 'hint' => 'Remove the quotes: 3000.'])
        ->and($deployment->config_errors['commitSha'])->toBe(CF_SHA)
        ->and(cfStaged($this->production)->isEmpty())->toBeTrue()
        ->and(EnvironmentConfigFile::query()->sole()->status)->toBe(ConfigFileStatus::Invalid);
});

test('a push never ships someone\'s staged dashboard edit: the file\'s changes wait with it', function (): void {
    resolve(UpdateServiceSettings::class)->handle(cfInstance($this->production, $this->web), new UpdateServiceSettingsData(scaling: new ScalingSettings(replicas: 2)));
    cfFile(['services' => ['web' => ['kind' => 'app', 'build' => ['buildCommand' => 'pnpm build']]]]);

    cfPush($this->team);

    $deployment = Deployment::query()->where('commit_sha', CF_SHA)->sole();
    $paths = array_map(static fn (ChangeItem $item): string => $item->path, cfStaged($this->production)->items);

    expect($deployment->revision_id)->toBe(EnvironmentRevision::query()->where('environment_id', $this->production->id)->value('id'))
        ->and($paths)->toContain('services.'.$this->web->public_id.'.build.buildCommand', 'services.'.$this->web->public_id.'.scaling.replicas')
        ->and(EnvironmentConfigFile::query()->sole()->status)->toBe(ConfigFileStatus::Staged);
});

test('without a file the push deploys as before and nothing is managed', function (): void {
    cfPush($this->team);

    expect(Deployment::query()->where('commit_sha', CF_SHA)->count())->toBe(1)
        ->and(EnvironmentConfigFile::query()->sole()->status)->toBe(ConfigFileStatus::Missing)
        ->and(EnvironmentConfigFile::query()->sole()->managed->services)->toBe([]);
});

test('a project that turned the file off is never read', function (): void {
    $settings = $this->project->settings;
    $settings->configFile->enabled = false;
    $this->project->forceFill(['settings' => $settings])->save();
    cfFile(['services' => ['web' => ['kind' => 'app', 'build' => ['buildCommand' => 'pnpm build']]]]);

    cfPush($this->team);

    expect($GLOBALS['cf_reads'])->toBe([])
        ->and(EnvironmentConfigFile::query()->count())->toBe(0);
});

test('a change of launchroom.json alone deploys services whose watch paths it misses', function (): void {
    resolve(UpdateServiceSettings::class)->handle(cfInstance($this->production, $this->web), new UpdateServiceSettingsData(build: new BuildSettings(watchPaths: ['apps/web'])));
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    cfFile(['services' => ['web' => ['kind' => 'app', 'deploy' => ['port' => 4000]]]]);

    cfPush($this->team, files: ['launchroom.json']);

    expect(cfInstance($this->production, $this->web)->deploy->port)->toBe(4000)
        ->and($this->production->refresh()->lastAppliedRevision?->message)->toBe('launchroom.json · 4444444');
});

test('required secrets must exist in the environment', function (): void {
    $content = json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['STRIPE_SECRET_KEY' => ['secret' => true]]]]]);
    $outcome = cfApply($this->production, (string) $content);

    expect($outcome->invalid())->toBeTrue()
        ->and($outcome->errors[0]->message)->toBe('web needs the secret STRIPE_SECRET_KEY, but it isn\'t set in production.');

    resolve(CreateVariable::class)->handle($this->project, $this->web, VariableInput::from(['key' => 'STRIPE_SECRET_KEY', 'value' => 'whatever', 'type' => 'secret', 'targets' => [['environmentId' => $this->production->public_id]]]), null, AuditActor::system());

    expect(cfApply($this->production, (string) $content)->invalid())->toBeFalse();
});

test('the file can\'t overwrite a secret with a config value', function (): void {
    resolve(CreateVariable::class)->handle($this->project, $this->web, VariableInput::from(['key' => 'TOKEN', 'value' => 'abc', 'type' => 'secret', 'targets' => [['environmentId' => $this->production->public_id]]]), null, AuditActor::system());

    $outcome = cfApply($this->production, (string) json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['TOKEN' => 'plain']]]]));

    expect($outcome->invalid())->toBeTrue()
        ->and($outcome->errors[0]->message)->toContain('TOKEN is a secret in production');
});

test('variables and domains the file managed before are removed when they leave it; settings are only unmanaged', function (): void {
    cfApply($this->production, (string) json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['A' => '1', 'B' => '2'], 'domains' => ['a.example.com'], 'scaling' => ['replicas' => 2]]]]));
    expect(Variable::query()->where('service_id', $this->web->id)->pluck('key')->sort()->values()->all())->toBe(['A', 'B'])
        ->and(Domain::query()->where('hostname', 'a.example.com')->exists())->toBeTrue();

    cfApply($this->production, (string) json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['A' => '1']]]]));

    expect(Variable::query()->where('service_id', $this->web->id)->pluck('key')->all())->toBe(['A'])
        ->and(Domain::query()->where('hostname', 'a.example.com')->exists())->toBeFalse()
        ->and(cfInstance($this->production, $this->web)->scaling->replicas)->toBe(2)
        ->and(EnvironmentConfigFile::query()->sole()->managed->service($this->web->public_id)?->fields)->not->toContain('scaling.replicas');
});

test('environment overrides apply to their environment only', function (): void {
    $staging = resolve(CreateEnvironment::class)->handle($this->project, new CreateEnvironmentData('staging', triggers: new EnvironmentTriggers(branch: 'develop')), null, AuditActor::system());
    $content = (string) json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'scaling' => ['replicas' => 3], 'variables' => ['MODE' => 'prod']]], 'environments' => ['staging' => ['services' => ['web' => ['scaling' => ['replicas' => 1], 'variables' => ['MODE' => 'staging']]]]]]);

    cfApply($this->production, $content);
    cfApply($staging, $content);

    expect(cfInstance($this->production, $this->web)->scaling->replicas)->toBe(3)
        ->and(cfInstance($staging, $this->web)->scaling->replicas)->toBe(1)
        ->and(resolve(CurrentEnvironmentSpec::class)->get($staging)->serviceBySlug('web')?->variables)->toHaveCount(1);
});

test('name and image are shared, so only production\'s file changes them', function (): void {
    $staging = resolve(CreateEnvironment::class)->handle($this->project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    $outcome = cfApply($staging, (string) json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'name' => 'Storefront']]]));

    expect($this->web->refresh()->name)->toBe('web')
        ->and($outcome->record->ignored[0]['message'])->toContain('name is shared by every environment and follows production');

    cfApply($this->production, (string) json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'name' => 'Storefront']]]));

    expect($this->web->refresh()->name)->toBe('Storefront');
});

test('preview and fork files can\'t change domains, placement, scaling beyond the caps or add services', function (ConfigTrust $trust): void {
    $staging = resolve(CreateEnvironment::class)->handle($this->project, new CreateEnvironmentData('staging'), null, AuditActor::system());
    $outcome = cfApply($staging, (string) json_encode(['version' => 1, 'services' => [
        'web' => ['kind' => 'app', 'domains' => ['evil.example.com'], 'scaling' => ['replicas' => 8, 'cpus' => 16, 'placement' => ['labels' => ['gpu' => 'yes']]], 'variables' => ['STRIPE_SECRET_KEY' => ['secret' => true], 'LOG' => 'debug'], 'deploy' => ['port' => 8080], 'volumes' => ['/data']],
        'miner' => ['kind' => 'image', 'image' => 'evil/miner:latest'],
    ]]), $trust);
    $instance = cfInstance($staging, $this->web);
    $ignored = array_column($outcome->record->ignored, 'message');

    expect($outcome->invalid())->toBeFalse()
        ->and(Domain::query()->where('hostname', 'evil.example.com')->exists())->toBeFalse()
        ->and($instance->scaling->replicas)->toBe(1)
        ->and($instance->scaling->cpus)->toBe(0.5)
        ->and($instance->scaling->placementLabels)->toBe([])
        ->and($instance->deploy->port)->toBe(8080)
        ->and(implode("\n", $ignored))->toContain('domains are ignored in previews', 'placement is ignored', 'previews run 1 replica');

    if ($trust === ConfigTrust::Fork) {
        expect(Service::query()->where('slug', 'miner')->exists())->toBeFalse()
            ->and($instance->volumes()->count())->toBe(0)
            ->and(implode("\n", $ignored))->toContain('new services are ignored for pull requests from forks');
    } else {
        expect(Service::query()->where('slug', 'miner')->exists())->toBeTrue();
    }
})->with([ConfigTrust::Preview, ConfigTrust::Fork]);

test('new services are created in this environment only, wired as on the canvas', function (): void {
    cfApply($this->production, (string) json_encode(['version' => 1, 'services' => [
        'web' => ['kind' => 'app', 'connections' => ['postgres']],
        'postgres' => ['kind' => 'database', 'engine' => 'postgres', 'version' => '18'],
        'jobs' => ['kind' => 'worker', 'of' => 'web', 'deploy' => ['startCommand' => ['node', 'jobs.js']]],
        'admin' => ['kind' => 'app', 'rootDirectory' => 'apps/admin', 'deploy' => ['port' => 4000]],
    ]]));

    $admin = Service::query()->where('slug', 'admin')->sole();
    $staged = cfStaged($this->production);

    expect($admin->source)->toBeInstanceOf(GitSource::class)
        ->and($admin->source->rootDirectory)->toBe('apps/admin')
        ->and($admin->source->fullName)->toBe('octo-org/web')
        ->and(Service::query()->where('slug', 'jobs')->sole()->parent_service_id)->toBe($this->web->id)
        ->and(Variable::query()->where('service_id', $this->web->id)->whereNotNull('connection_id')->pluck('key')->all())->toContain('DATABASE_URL')
        ->and(array_map(static fn (ChangeItem $item): string => $item->label, $staged->items))->not->toBeEmpty();
});

test('a dry run lists the changes and writes nothing', function (): void {
    $before = resolve(CurrentEnvironmentSpec::class)->get($this->production);
    $result = resolve(DryRunConfigFile::class)->handle($this->production, (string) json_encode(['version' => 1, 'services' => [
        'web' => ['kind' => 'app', 'build' => ['buildCommand' => 'make'], 'domains' => ['new.example.com']],
        'cache' => ['kind' => 'database', 'engine' => 'valkey', 'version' => '8'],
    ]]), diff: true);

    expect($result->valid)->toBeTrue()
        ->and(array_map(static fn (ChangeItem $item): string => $item->label, $result->changes))->toContain('Domain new.example.com (applies right away)')
        ->and(count($result->changes))->toBeGreaterThanOrEqual(3)
        ->and(SpecDiff::between($before, resolve(CurrentEnvironmentSpec::class)->get($this->production))->items)->toBe([])
        ->and(Service::query()->where('slug', 'cache')->exists())->toBeFalse()
        ->and(Domain::query()->where('hostname', 'new.example.com')->exists())->toBeFalse()
        ->and(EnvironmentConfigFile::query()->count())->toBe(0);
});

test('a generated secret in the file is generated once and kept', function (): void {
    $content = (string) json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'app', 'variables' => ['APP_KEY' => '${{ secret(32) }}']]]]);
    cfApply($this->production, $content);
    $first = Variable::query()->where('key', 'APP_KEY')->sole();

    cfApply($this->production, $content);
    $again = Variable::query()->where('key', 'APP_KEY')->sole();

    expect($first->type)->toBe(VariableType::Secret)
        ->and($again->version)->toBe($first->version)
        ->and($again->generator)->toBe('secret(32)');
});

test('every dashboard serves the schema publicly', function (): void {
    $response = $this->get('/schema/launchroom.v1.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/schema+json')
        ->assertHeader('Access-Control-Allow-Origin', '*');

    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class)
        ->and(json_decode((string) file_get_contents($response->baseResponse->getFile()->getPathname()), true)['$id'])->toBe('https://launchroom.vantezzen.io/schema/launchroom.v1.json');
});

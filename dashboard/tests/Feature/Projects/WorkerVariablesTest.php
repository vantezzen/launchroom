<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Services\CreateService;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DeleteVariable;
use App\Actions\Variables\UpdateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Services\CreateServiceData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableRowData;
use App\Data\Variables\VariableTargetData;
use App\Data\Variables\VariableUpdate;
use App\Enums\ChangeEffect;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\Variable;
use App\Queries\CurrentEnvironmentSpec;
use App\Queries\ServiceVariablesView;
use App\Services\Promotions\PromotionPlanner;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Changes\SpecDiff;
use Tests\Support\Projects;

/*
| Workers and crons run their app's code, so they get the app's own runtime
| variables too (docs/06 §3.2): below their own variables, above the app's
| connections, resolved as the app resolves them, never copied.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
});

/**
 * @return array{Team, Project, Environment, Service, Service, Service}
 */
function wvProject(): array
{
    $team = Team::factory()->create();
    $project = Projects::create($team);
    $app = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'api',
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
    ]), null, AuditActor::system());
    $worker = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'queue', 'kind' => ServiceKind::Worker, 'parentServiceId' => $app->public_id, 'startCommand' => ['node', 'worker.js']]), null, AuditActor::system());
    $postgres = Projects::postgres($project);

    return [$team, $project, $project->production()->sole(), $app, $worker, $postgres];
}

function wvVar(Project $project, Service $service, Environment $environment, string $key, string $value, VariableType $type = VariableType::Config, ?VariableExposure $exposure = null): Variable
{
    return resolve(CreateVariable::class)->handle($project, $service, VariableInput::from([
        'key' => $key, 'value' => $value, 'type' => $type, 'exposureOverride' => $exposure,
        'targets' => [VariableTargetData::environment($environment->public_id)],
    ]), null, AuditActor::system());
}

/**
 * @return array<string, string>
 */
function wvEnv(Project $project, Environment $environment, Service $service): array
{
    $spec = resolve(CurrentEnvironmentSpec::class)->get($environment);

    return resolve(SpecVariableResolver::class)->resolve($project, $spec, $spec->services[$service->public_id])->all();
}

test('a worker gets its app\'s own variables, and its own variable with the same key wins', function (): void {
    [, $project, $production, $app, $worker] = wvProject();
    wvVar($project, $app, $production, 'MAIL_HOST', 'smtp.example.com');
    wvVar($project, $app, $production, 'QUEUE_CONNECTION', 'database');
    wvVar($project, $worker, $production, 'QUEUE_CONNECTION', 'redis');

    $env = wvEnv($project, $production, $worker);

    expect($env['MAIL_HOST'])->toBe('smtp.example.com')
        ->and($env['QUEUE_CONNECTION'])->toBe('redis')
        ->and($env['LAUNCHROOM_SERVICE'])->toBe('queue')
        ->and(wvEnv($project, $production, $app)['QUEUE_CONNECTION'])->toBe('database');

    $all = resolve(SpecVariableResolver::class)->resolveAll($project, resolve(CurrentEnvironmentSpec::class)->get($production));
    expect($all[$worker->public_id]->all()['MAIL_HOST'])->toBe('smtp.example.com');
});

test('the app\'s own variable wins over its connection and over system values; the worker\'s own connection wins over both', function (): void {
    [, $project, $production, $app, $worker, $postgres] = wvProject();
    resolve(CreateConnection::class)->handle($app, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    wvVar($project, $app, $production, 'DATABASE_URL', 'postgresql://override@db/app');
    wvVar($project, $app, $production, 'LAUNCHROOM_SERVICE', 'from-api');

    $env = wvEnv($project, $production, $worker);
    expect($env['DATABASE_URL'])->toBe('postgresql://override@db/app')
        ->and($env['LAUNCHROOM_SERVICE'])->toBe('from-api');

    $reports = Projects::postgres($project, 'reports');
    resolve(CreateConnection::class)->handle($worker, $reports, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

    expect(wvEnv($project, $production, $worker)['DATABASE_URL'])->toContain('@reports:5432');
});

test('references in the app\'s variables resolve as they do for the app', function (): void {
    [, $project, $production, $app, $worker, $postgres] = wvProject();
    wvVar($project, $app, $production, 'APP_NAME', 'Shop');
    wvVar($project, $app, $production, 'MAIL_FROM_NAME', '${{APP_NAME}} mailer');
    wvVar($project, $app, $production, 'SERVICE_LABEL', '${{LAUNCHROOM_SERVICE}}');
    wvVar($project, $app, $production, 'DB_PASS', '${{postgres.POSTGRES_PASSWORD}}');
    // The worker's own APP_NAME doesn't change what the app's reference resolves to.
    wvVar($project, $worker, $production, 'APP_NAME', 'Worker');

    $env = wvEnv($project, $production, $worker);
    $appEnv = wvEnv($project, $production, $app);

    expect($env['MAIL_FROM_NAME'])->toBe('Shop mailer')
        ->and($env['SERVICE_LABEL'])->toBe('api')
        ->and($env['DB_PASS'])->toBe($appEnv['DB_PASS'])
        ->and($env['DB_PASS'])->not->toBe('')
        ->and($env['APP_NAME'])->toBe('Worker');
});

test('secrets stay secrets: tainted, masked in Variables, never in the row data', function (): void {
    [$team, $project, $production, $app, $worker] = wvProject();
    wvVar($project, $app, $production, 'STRIPE_SECRET', 'sk_live_abcdef123456', VariableType::Secret);
    wvVar($project, $worker, $production, 'STRIPE_COPY', '${{STRIPE_SECRET}}');

    $spec = resolve(CurrentEnvironmentSpec::class)->get($production);
    $taint = resolve(SpecVariableResolver::class)->taint($project, $spec);

    expect(wvEnv($project, $production, $worker)['STRIPE_SECRET'])->toBe('sk_live_abcdef123456')
        ->and($taint->isTainted('queue', 'STRIPE_SECRET'))->toBeTrue()
        ->and($taint->isTainted('queue', 'STRIPE_COPY'))->toBeTrue();

    $view = resolve(ServiceVariablesView::class)->get($production, $worker, memberOf($team, TeamRole::Developer));
    $rows = collect($view->variables)->keyBy(static fn (VariableRowData $row): string => $row->key);

    expect($rows['STRIPE_SECRET']->value)->toBeNull()
        ->and($rows['STRIPE_SECRET']->type)->toBe(VariableType::Secret)
        ->and($rows['STRIPE_SECRET']->inheritedFrom)->toBe('api')
        ->and($rows['STRIPE_COPY']->preview)->not->toContain('sk_live')
        ->and(json_encode($view->toArray()))->not->toContain('sk_live_abcdef123456');
});

test('only runtime variables reach the worker; build keys stay unchanged', function (): void {
    [, $project, $production, $app, $worker] = wvProject();
    wvVar($project, $app, $production, 'BUILD_ONLY', 'yes', exposure: VariableExposure::Build);
    wvVar($project, $app, $production, 'BOTH', 'yes', exposure: VariableExposure::Both);

    $env = wvEnv($project, $production, $worker);
    $spec = resolve(CurrentEnvironmentSpec::class)->get($production);

    expect($env)->not->toHaveKey('BUILD_ONLY')
        ->and($env['BOTH'])->toBe('yes')
        ->and($spec->services[$worker->public_id]->variables)->toBe([]);
});

test('changing the app\'s variables restarts its worker with the next deploy, and the change plan lists it', function (): void {
    [, $project, $production, $app, $worker] = wvProject();
    $before = resolve(CurrentEnvironmentSpec::class)->get($production);

    $mail = wvVar($project, $app, $production, 'MAIL_HOST', 'smtp.example.com');
    $added = resolve(CurrentEnvironmentSpec::class)->get($production);
    $diff = SpecDiff::between($before, $added);

    expect($diff->effectFor($worker->public_id))->toBe(ChangeEffect::Restart)
        ->and(SpecDiff::between($added, $added)->effectFor($worker->public_id))->toBe(ChangeEffect::None);

    resolve(UpdateVariable::class)->handle($mail, VariableUpdate::from(['value' => 'smtp2.example.com']), null, AuditActor::system());
    $changed = resolve(CurrentEnvironmentSpec::class)->get($production);
    expect(SpecDiff::between($added, $changed)->effectFor($worker->public_id))->toBe(ChangeEffect::Restart);

    // A build-only change doesn't touch the worker.
    wvVar($project, $app, $production, 'SENTRY_AUTH_TOKEN', 'token', VariableType::Secret, VariableExposure::Build);
    expect(SpecDiff::between($changed, resolve(CurrentEnvironmentSpec::class)->get($production))->effectFor($worker->public_id))->toBe(ChangeEffect::None);

    // Overridden by the worker: the app's value no longer matters to it.
    $overridden = resolve(CurrentEnvironmentSpec::class)->get($production);
    wvVar($project, $worker, $production, 'MAIL_HOST', 'smtp.worker.example.com');
    $own = resolve(CurrentEnvironmentSpec::class)->get($production);
    resolve(UpdateVariable::class)->handle($mail->refresh(), VariableUpdate::from(['value' => 'smtp3.example.com']), null, AuditActor::system());
    expect(SpecDiff::between($own, resolve(CurrentEnvironmentSpec::class)->get($production))->effectFor($worker->public_id))->toBe(ChangeEffect::None)
        ->and(SpecDiff::between($overridden, $own)->effectFor($worker->public_id))->toBe(ChangeEffect::Restart);

    resolve(DeleteVariable::class)->handle($mail->refresh(), AuditActor::system());
    expect(wvEnv($project, $production, $worker)['MAIL_HOST'])->toBe('smtp.worker.example.com');

    // The staged changes list the worker with its effect (StagedChangesView groups every service with one).
    expect($diff->services[$worker->public_id] ?? null)->toBe(ChangeEffect::Restart);
});

test('Variables lists the app\'s variables read-only on the worker and names the processes on the app', function (): void {
    [$team, $project, $production, $app, $worker] = wvProject();
    $cron = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'scheduler', 'kind' => ServiceKind::Cron, 'parentServiceId' => $app->public_id, 'startCommand' => ['node', 'cron.js']]), null, AuditActor::system());
    wvVar($project, $app, $production, 'MAIL_HOST', 'smtp.example.com');
    wvVar($project, $app, $production, 'APP_URL_COPY', '${{PUBLIC_URL}}');
    wvVar($project, $app, $production, 'QUEUE_CONNECTION', 'database');
    wvVar($project, $worker, $production, 'QUEUE_CONNECTION', 'redis');
    $user = memberOf($team, TeamRole::Developer);

    $view = resolve(ServiceVariablesView::class)->get($production, $worker, $user);
    $rows = collect($view->variables)->groupBy(static fn (VariableRowData $row): string => $row->key);

    expect($rows['MAIL_HOST'])->toHaveCount(1)
        ->and($rows['MAIL_HOST'][0]->inheritedFrom)->toBe('api')
        ->and($rows['MAIL_HOST'][0]->connection)->toBeNull()
        ->and($rows['MAIL_HOST'][0]->canEdit)->toBeFalse()
        ->and($rows['MAIL_HOST'][0]->overridden)->toBeFalse()
        ->and($rows['MAIL_HOST'][0]->targets)->toBe([])
        ->and($rows['MAIL_HOST'][0]->value)->toBe('smtp.example.com')
        // Own row first, then the app's, which it overrides.
        ->and($rows['QUEUE_CONNECTION'])->toHaveCount(2)
        ->and($rows['QUEUE_CONNECTION'][0]->inheritedFrom)->toBeNull()
        ->and($rows['QUEUE_CONNECTION'][0]->canEdit)->toBeTrue()
        ->and($rows['QUEUE_CONNECTION'][1]->inheritedFrom)->toBe('api')
        ->and($rows['QUEUE_CONNECTION'][1]->overridden)->toBeTrue()
        ->and($view->usedBy)->toBe([]);

    $appView = resolve(ServiceVariablesView::class)->get($production, $app, $user);
    expect($appView->usedBy)->toBe(['queue', 'scheduler'])
        ->and(collect($appView->variables)->pluck('inheritedFrom')->filter()->all())->toBe([])
        ->and($cron->public_id)->not->toBe('');
});

test('environment copies and promotions don\'t copy the app\'s variables to the worker', function (): void {
    [, $project, $production, $app, $worker] = wvProject();
    wvVar($project, $app, $production, 'MAIL_HOST', 'smtp.example.com');

    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    expect(Variable::query()->where('service_id', $worker->id)->count())->toBe(0)
        ->and(wvEnv($project, $staging, $worker)['MAIL_HOST'])->toBe('smtp.example.com');

    $items = resolve(PromotionPlanner::class)->plan($staging, $production)->items;
    expect(collect($items)->filter(static fn ($item): bool => str_starts_with($item->path, "services.{$worker->public_id}.variables."))->all())->toBe([]);
});

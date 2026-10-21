<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\CreateService;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Services\CreateServiceData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\AuditAction;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;

const SP_SHA = '4015b57a143aec5156fd1444a017a32137a3fd0f';

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    Http::fake([
        'http://git-fixtures:8080/*' => static function (): PromiseInterface {
            $pkt = static fn (string $line): string => sprintf('%04x', strlen($line) + 4).$line;

            return Http::response($pkt("# service=git-upload-pack\n").'0000'.$pkt(SP_SHA." HEAD\0multi_ack\n").$pkt(SP_SHA." refs/heads/main\n").'0000');
        },
    ]);

    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    [$this->server] = Projects::server($this->team);
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->web = resolve(CreateService::class)->handle($this->project, CreateServiceData::from([
        'name' => 'web',
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
    ]), null, AuditActor::system());
    $this->settings = fn (string $as = 'developer') => $this->actingAs($this->{$as})->get(route('projects.services.show', [$this->project, $this->production, $this->web, 'settings']));
});

function spCompletePlan(Server $server, array $summary): void
{
    $task = DaemonTask::query()->where('type', DaemonTaskType::Plan->value)->latest('id')->firstOrFail();
    resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['plan']]));
    $task->refresh();

    resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt,
        'outcome' => 'succeeded', 'plan' => ['durationMs' => 800, 'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', ...$summary]],
    ]));
}

test('the Build section shows the plan preview while it runs and what it detected', function (): void {
    ($this->settings)()->assertInertia(fn ($page) => $page
        ->where('panel.settings.plan.status', 'planning')
        ->where('panel.settings.plan.commitSha', SP_SHA)
        ->where('panel.settings.plan.branch', 'main')
        ->where('panel.settings.detected', null)
        ->where('panel.settings.defaultRailpackVersion', config('launchroom.builds.railpack_version'))
        ->where('panel.settings.build.disabledDecisions', []));

    resolve(CreateVariable::class)->handle($this->project, $this->web, VariableInput::from([
        'key' => 'DATABASE_URL', 'value' => 'postgres://x', 'type' => VariableType::Config, 'targets' => [VariableTargetData::environment($this->production->public_id)],
    ]), null, AuditActor::system());

    spCompletePlan($this->server, [
        'framework' => 'laravel', 'provider' => 'php', 'port' => 8080, 'healthPath' => '/up', 'releaseCommand' => ['php', 'artisan', 'migrate', '--force'],
        'presets' => [['name' => 'laravel', 'version' => 1, 'decisions' => [['id' => 'run-migrations', 'title' => 'Run migrations on release', 'detail' => 'php artisan migrate --force']]]],
        'envReferences' => ['APP_KEY', 'DATABASE_URL', 'PORT', 'LAUNCHROOM_GIT_COMMIT_SHA', 'LOG_CHANNEL'],
        'runtimeEnv' => ['LOG_CHANNEL' => 'stderr'],
    ]);

    ($this->settings)('viewer')->assertInertia(fn ($page) => $page
        ->where('panel.canUpdate', false)
        ->where('panel.settings.plan.status', 'ready')
        ->where('panel.settings.detected.framework', 'Laravel')
        ->where('panel.settings.detected.port', 8080)
        ->where('panel.settings.detected.releaseCommand', 'php artisan migrate --force')
        ->where('panel.settings.detected.presets.0.decisions.0.id', 'run-migrations')
        // Defined, provided by the platform or set by the preset: not missing.
        ->where('panel.settings.detected.missingVariables', ['APP_KEY'])
        ->where('panel.settings.detected.runtimeVariableCount', 1));

    // The canvas node names the framework from the preview.
    $this->actingAs($this->viewer)->get(route('projects.canvas', [$this->project, $this->production]))
        ->assertInertia(fn ($page) => $page->where('nodes.0.logo', 'laravel')->where('nodes.0.subtitle', 'Laravel'));
});

test('removed preset decisions are saved, validated and sent to builds', function (): void {
    $url = route('projects.services.settings', [$this->project, $this->production, $this->web]);

    $this->actingAs($this->viewer)->patch($url, ['build' => ['disabledDecisions' => ['run-migrations']]])->assertForbidden();
    $this->actingAs($this->developer)->patch($url, ['build' => ['disabledDecisions' => ['Not A Slug']]])->assertSessionHasErrors('build.disabledDecisions.0');
    $this->actingAs($this->developer)->patch($url, ['build' => ['disabledDecisions' => array_fill(0, 65, 'x')]])->assertSessionHasErrors('build.disabledDecisions');
    $this->actingAs($this->developer)->patch($url, ['build' => ['disabledDecisions' => ['run-migrations']]])->assertRedirect();

    // Build inputs changed: a new plan runs with the decision removed; the outdated one is cancelled.
    $plans = DaemonTask::query()->where('type', DaemonTaskType::Plan->value)->orderBy('id')->get();
    expect($plans)->toHaveCount(2)
        ->and($plans[0]->status)->toBe(DaemonTaskStatus::Cancelled)
        ->and($plans[1]->spec->builder->disabledDecisions)->toBe(['run-migrations']);
    $this->actingAs($this->developer)->get(route('projects.services.show', [$this->project, $this->production, $this->web, 'settings']))
        ->assertInertia(fn ($page) => $page->where('panel.settings.plan.status', 'planning'));

    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $build = DaemonTask::query()->where('type', DaemonTaskType::Build->value)->sole();
    expect($build->spec->builder->disabledDecisions)->toBe(['run-migrations']);
});

test('detection can be re-run by members who can edit', function (): void {
    $url = route('projects.services.replan', [$this->project, $this->production, $this->web]);
    spCompletePlan($this->server, ['framework' => 'express']);

    $this->actingAs($this->viewer)->post($url)->assertForbidden();
    $this->actingAs($this->developer)->post($url)->assertRedirect();

    expect(DaemonTask::query()->where('type', DaemonTaskType::Plan->value)->count())->toBe(2);

    $image = Projects::image($this->project, 'docs');
    $this->actingAs($this->developer)->post(route('projects.services.replan', [$this->project, $this->production, $image]))->assertStatus(409);
});

test('renaming keeps the slug, is staged and audited', function (): void {
    $url = route('projects.services.rename', [$this->project, $this->production, $this->web]);

    $this->actingAs($this->viewer)->patch($url, ['name' => 'Storefront'])->assertForbidden();
    $this->actingAs($this->developer)->patch($url, ['name' => ''])->assertSessionHasErrors('name');
    $this->actingAs($this->developer)->patch($url, ['name' => str_repeat('a', 64)])->assertSessionHasErrors('name');
    $this->actingAs($this->developer)->patch($url, ['name' => 'Storefront'])->assertRedirect();

    $service = Service::query()->whereKey($this->web->id)->sole();
    expect($service->name)->toBe('Storefront')
        ->and($service->slug)->toBe('web')
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceRenamed->value)->sole()->meta)->toMatchArray(['from' => 'web', 'to' => 'Storefront']);

    $other = Projects::create(Team::factory()->create(), 'Other');
    $this->actingAs($this->developer)->patch(route('projects.services.rename', [$other, $other->production()->sole(), $this->web]), ['name' => 'x'])->assertNotFound();
});

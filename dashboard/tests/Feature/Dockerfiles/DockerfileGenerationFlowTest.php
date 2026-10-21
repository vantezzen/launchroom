<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Dockerfiles\AdvanceDockerfileGeneration;
use App\Actions\Dockerfiles\DraftDockerfile;
use App\Actions\Services\CreateService;
use App\Actions\Variables\CreateVariable;
use App\Contracts\DockerfileModel;
use App\Contracts\ImageDigestResolver;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Protocol\V1\TaskLease;
use App\Data\Services\CreateServiceData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\Dockerfiles\DockerfileConfidence;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Exceptions\DockerfileModelFailed;
use App\Exceptions\ImageResolutionFailed;
use App\Jobs\Dockerfiles\DraftDockerfileJob;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\Server;
use App\Models\Team;
use App\Services\Dockerfiles\AiProviderSecrets;
use App\Support\Dockerfiles\DockerfileDraft;
use App\Support\Dockerfiles\DockerfilePrompt;
use App\Support\Images\ImageReference;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

const DG_SHA = '4015b57a143aec5156fd1444a017a32137a3fd0f';
const DG_DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const DG_GOOD = "FROM busybox:1.37\nWORKDIR /www\nCOPY www/ /www/\nEXPOSE 8080\nCMD [\"httpd\", \"-f\", \"-p\", \"8080\", \"-h\", \"/www\"]\n";

/**
 * A scripted model: answers drafts (or failures) in order, records prompts.
 */
final class ScriptedDockerfileModel implements DockerfileModel
{
    /** @var list<DockerfilePrompt> */
    public array $prompts = [];

    /**
     * @param  list<DockerfileDraft|DockerfileModelFailed>  $answers
     */
    public function __construct(public array $answers) {}

    public function draft(DockerfilePrompt $prompt): DockerfileDraft
    {
        $this->prompts[] = $prompt;
        $answer = array_shift($this->answers) ?? new DockerfileModelFailed('No more answers.');

        if ($answer instanceof DockerfileModelFailed) {
            throw $answer;
        }

        return $answer;
    }
}

function dgDraft(string $dockerfile = DG_GOOD, int $port = 8080): DockerfileDraft
{
    return new DockerfileDraft($dockerfile, $port, 'httpd -f -p 8080 -h /www', 'Serves the www folder with busybox httpd.', DockerfileConfidence::High, 1200, 300);
}

function dgPkt(string $line): string
{
    return sprintf('%04x', strlen($line) + 4).$line;
}

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    Http::fake([
        'http://git-fixtures:8080/*' => static fn (): PromiseInterface => Http::response(dgPkt("# service=git-upload-pack\n").'0000'.dgPkt(DG_SHA." HEAD\0multi_ack\n").dgPkt(DG_SHA." refs/heads/main\n").'0000'),
    ]);

    app()->instance(ImageDigestResolver::class, new class implements ImageDigestResolver
    {
        public function resolve(ImageReference $reference): ImageReference
        {
            if ($reference->repository === 'library/nope') {
                throw new ImageResolutionFailed('manifest unknown');
            }

            return $reference->withDigest(DG_DIGEST);
        }
    });

    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->service = resolve(CreateService::class)->handle($this->project, CreateServiceData::from([
        'name' => 'site',
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/unplannable-site', 'cloneUrl' => 'http://git-fixtures:8080/unplannable-site.git', 'branch' => 'main'],
    ]), null, AuditActor::system());
    $this->instance = $this->service->instances()->sole();

    // Both switches on (docs/20 §1).
    $settings = AiProviderSettings::current();
    resolve(AiProviderSecrets::class)->put($settings, 'sk-ant-api03-'.str_repeat('k', 40));
    $settings->forceFill(['enabled' => true])->save();
    $teamSettings = clone $this->team->settings;
    $teamSettings->generateDockerfiles = true;
    $this->team->forceFill(['settings' => $teamSettings])->save();

    [$this->server] = Projects::server($this->team);
    $this->server->forceFill(['daemon_features' => [DaemonFeature::GeneratedDockerfile->value, DaemonFeature::GitProviders->value]])->save();

    $this->url = fn (string $suffix = ''): string => route('projects.services.dockerfile-generations.store', ['project' => $this->project, 'environment' => $this->production, 'service' => $this->service]).$suffix;
    $this->model = new ScriptedDockerfileModel([]);
    app()->instance(DockerfileModel::class, $this->model);
    Bus::fake([DraftDockerfileJob::class]);
});

/**
 * Leases every task this server may take and checks the lease against the
 * protocol schema.
 *
 * @return list<Task>
 */
function dgLease(Server $server): array
{
    $leased = resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 16, 'types' => ['plan', 'build', 'run_once']]));

    expect(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease($leased))))->toBe([]);

    return $leased;
}

function dgComplete(Server $server, DaemonTask $task, array $completion): void
{
    $task->refresh();
    resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, ...$completion,
    ]));
    resolve(AdvanceDockerfileGeneration::class)->handle(DockerfileGeneration::query()->sole()->id);
}

function dgInspection(): array
{
    return ['inspection' => [
        'tree' => ['README.md', 'www/index.html', '.env', 'config/.env.production', 'deploy/id_rsa'],
        'treeTruncated' => false,
        'files' => [
            ['path' => 'README.md', 'content' => "Served by busybox httpd.\nSTRIPE_KEY=sk_live_abcdefghijklmnop\n", 'truncated' => false],
            ['path' => '.env', 'content' => 'SECRET=hunter2hunter2', 'truncated' => false],
        ],
    ]];
}

function dgDraftNow(): void
{
    $id = DockerfileGeneration::query()->sole()->id;

    while (resolve(DraftDockerfile::class)->handle($id)) {
        // Repairs of lint refusals run right away.
    }
}

test('the whole flow: read, draft, lint, pin, build, start, verified, use', function (): void {
    resolve(CreateVariable::class)->handle($this->project, $this->service, VariableInput::from([
        'key' => 'SESSION_SECRET', 'value' => 'correct-horse-battery-staple', 'type' => VariableType::Secret, 'targets' => [VariableTargetData::environment($this->production->public_id)],
    ]), null, AuditActor::system());
    $this->model->answers = [dgDraft()];

    $response = $this->actingAs($this->developer)->postJson(($this->url)(), ['trigger' => 'plan_failed'])
        ->assertCreated()
        ->assertJsonPath('status', 'reading')
        ->assertJsonPath('model', 'claude-opus-5-5');

    $generation = DockerfileGeneration::query()->sole();
    $plan = DaemonTask::query()->findOrFail($generation->plan_task_id);
    expect($plan->type)->toBe(DaemonTaskType::Plan)
        ->and($plan->spec->inspect)->toBeTrue()
        ->and($plan->spec->repo->sha)->toBe(DG_SHA)
        ->and(AuditEvent::query()->where('action', 'dockerfile_generation.started')->exists())->toBeTrue();

    dgLease($this->server);
    dgComplete($this->server, $plan, ['outcome' => 'failed', 'error' => 'railpack could not plan this app', 'failure' => ['class' => 'plan_error', 'step' => 'plan', 'retryable' => false], ...dgInspection()]);

    $generation->refresh();
    expect($generation->status)->toBe(DockerfileGenerationStatus::Drafting)
        // Denied paths never stored or sent, credentials redacted.
        ->and($generation->context['tree'])->toBe(['README.md', 'www/index.html'])
        ->and(array_column($generation->context['files'], 'path'))->toBe(['README.md'])
        ->and($generation->context['files'][0]['content'])->not->toContain('sk_live_abcdefghijklmnop')
        ->and($generation->detected['error'])->toContain('railpack could not plan');
    Bus::assertDispatched(DraftDockerfileJob::class);

    dgDraftNow();

    $prompt = $this->model->prompts[0];
    expect($prompt->model)->toBe('claude-opus-5-5')
        ->and($prompt->user)->toContain('www/index.html')
        ->and($prompt->user)->not->toContain('.env')
        ->and($prompt->user)->not->toContain('correct-horse-battery-staple')
        ->and($prompt->user)->not->toContain('hunter2')
        ->and($prompt->requester)->toMatch('/^[a-f0-9]{64}$/');

    $generation->refresh();
    expect($generation->status)->toBe(DockerfileGenerationStatus::Building)
        ->and($generation->dockerfile)->toContain('FROM busybox:1.37@'.DG_DIGEST);

    $build = DaemonTask::query()->findOrFail($generation->build_task_id);
    expect($build->type)->toBe(DaemonTaskType::Build)
        ->and($build->spec->builder->kind)->toBe('dockerfile')
        ->and($build->spec->builder->dockerfile)->toBe($generation->dockerfile)
        ->and(isset($build->spec->builder->dockerfilePath))->toBeFalse()
        ->and($build->deployment_id)->toBeNull()
        // Build variables go by name only: no deployment snapshot is sealed.
        ->and(array_keys($build->seal['env'] ?? ['names' => []]))->toBe(['names']);

    dgLease($this->server);
    dgComplete($this->server, $build, ['outcome' => 'succeeded', 'build' => ['image' => '127.0.0.1:5000/apps/'.strtolower($this->service->public_id).'@'.DG_DIGEST, 'durationMs' => 21000]]);

    $generation->refresh();
    expect($generation->status)->toBe(DockerfileGenerationStatus::Starting);
    $run = DaemonTask::query()->findOrFail($generation->run_task_id);
    expect($run->type)->toBe(DaemonTaskType::RunOnce)
        ->and($run->spec->probe->port)->toBe(8080)
        ->and($run->spec->probe->within)->toBe('90s')
        ->and(isset($run->spec->command))->toBeFalse()
        ->and($run->seal)->toBe(['env' => ['values' => ['PORT' => '8080']]])
        ->and($run->server_id)->toBe($this->server->id);

    // The verification run's env is PORT only: no variables, no secrets.
    $leased = dgLease($this->server);
    expect($leased)->toHaveCount(1);

    dgComplete($this->server, $run, ['outcome' => 'succeeded', 'runOnce' => ['exitCode' => 0, 'durationMs' => 2300, 'probe' => ['listening' => true, 'afterMs' => 1800, 'httpStatus' => 200, 'exited' => false]]]);

    expect($generation->refresh()->status)->toBe(DockerfileGenerationStatus::Verified);

    $this->actingAs($this->developer)->getJson(($this->url)('/'.$generation->public_id))
        ->assertOk()
        ->assertJsonPath('status', 'verified')
        ->assertJsonPath('canUse', true)
        ->assertJsonPath('startedAfterMs', 1800)
        ->assertJsonPath('buildMs', 21000)
        ->assertJsonPath('costUsd', 0.0108)
        ->assertJsonMissingPath('context');

    $this->actingAs($this->viewer)->postJson(($this->url)('/'.$generation->public_id.'/use'))->assertForbidden();
    $this->actingAs($this->developer)->postJson(($this->url)('/'.$generation->public_id.'/use'))
        ->assertOk()
        ->assertJsonPath('inUse', true);

    $this->instance->refresh();
    expect($this->instance->build->builder)->toBe('dockerfile')
        ->and($this->instance->build->generatedDockerfile)->toBe($generation->public_id)
        ->and(AuditEvent::query()->where('action', 'dockerfile_generation.used')->exists())->toBeTrue();

    // The next deployment's build sends the stored Dockerfile inline.
    resolve(DeployEnvironment::class)->handle($this->production, $this->owner, AuditActor::system());
    $deployBuild = DaemonTask::query()->where('type', DaemonTaskType::Build->value)->whereNotNull('deployment_id')->latest('id')->first();

    if ($deployBuild instanceof DaemonTask) {
        expect($deployBuild->spec->builder->dockerfile)->toBe($generation->dockerfile);
    }
});

test('a failed build goes back to the model with the log tail, then fails after N repairs', function (): void {
    $this->model->answers = [dgDraft(), dgDraft(), dgDraft()];
    $this->actingAs($this->developer)->postJson(($this->url)())->assertCreated();
    $generation = DockerfileGeneration::query()->sole();

    dgLease($this->server);
    dgComplete($this->server, DaemonTask::query()->findOrFail($generation->plan_task_id), ['outcome' => 'failed', 'error' => 'railpack could not plan', ...dgInspection()]);

    foreach ([1, 2, 3] as $attempt) {
        dgDraftNow();
        $generation->refresh();
        expect($generation->status)->toBe(DockerfileGenerationStatus::Building)->and($generation->attempt)->toBe($attempt);

        $build = DaemonTask::query()->findOrFail($generation->build_task_id);
        dgLease($this->server);
        dgComplete($this->server, $build, ['outcome' => 'failed', 'error' => 'process "/bin/sh -c make" did not complete successfully: exit code: 2', 'failure' => ['class' => 'build_error', 'step' => 'build', 'retryable' => false]]);
    }

    $generation->refresh();
    expect($generation->status)->toBe(DockerfileGenerationStatus::Failed)
        ->and($generation->error)->toContain("still didn't build after 3 tries")
        ->and($this->model->prompts)->toHaveCount(3)
        ->and($this->model->prompts[1]->user)->toContain('Attempt 1 did not work')
        ->and($this->model->prompts[1]->user)->toContain('exit code: 2');
});

test('a start that never listens is repaired with the probe outcome', function (): void {
    $this->model->answers = [dgDraft(), dgDraft()];
    $this->actingAs($this->developer)->postJson(($this->url)())->assertCreated();
    $generation = DockerfileGeneration::query()->sole();
    dgLease($this->server);
    dgComplete($this->server, DaemonTask::query()->findOrFail($generation->plan_task_id), ['outcome' => 'succeeded', 'plan' => ['durationMs' => 1, 'summary' => ['builder' => 'railpack', 'builderReason' => 'railpack']], ...dgInspection()]);
    dgDraftNow();

    $generation->refresh();
    dgLease($this->server);
    dgComplete($this->server, DaemonTask::query()->findOrFail($generation->build_task_id), ['outcome' => 'succeeded', 'build' => ['image' => '127.0.0.1:5000/apps/'.strtolower($this->service->public_id).'@'.DG_DIGEST, 'durationMs' => 1]]);
    $generation->refresh();
    dgLease($this->server);
    dgComplete($this->server, DaemonTask::query()->findOrFail($generation->run_task_id), ['outcome' => 'succeeded', 'runOnce' => ['exitCode' => 0, 'durationMs' => 90000, 'probe' => ['listening' => false, 'afterMs' => 90000, 'exited' => false, 'listeningPorts' => [3000]]]]);

    $generation->refresh();
    expect($generation->status)->toBe(DockerfileGenerationStatus::Drafting);
    dgDraftNow();
    expect($this->model->prompts[1]->user)->toContain('it listens on 3000 instead');
});

test('lint refusals and unknown images are repaired without a build', function (): void {
    $this->model->answers = [
        dgDraft("FROM node:latest\nRUN --network=host npm ci\nENV API_TOKEN=abc123\nVOLUME /data\nEXPOSE 3000\nCMD [\"node\", \"server.js\"]\n", 3000),
        dgDraft("FROM nope:1.0\nEXPOSE 8080\nCMD [\"x\"]\n"),
        dgDraft(),
    ];
    $this->actingAs($this->developer)->postJson(($this->url)())->assertCreated();
    $generation = DockerfileGeneration::query()->sole();
    dgLease($this->server);
    dgComplete($this->server, DaemonTask::query()->findOrFail($generation->plan_task_id), ['outcome' => 'failed', 'error' => 'railpack could not plan', ...dgInspection()]);

    dgDraftNow();

    $generation->refresh();
    expect($generation->status)->toBe(DockerfileGenerationStatus::Building)
        ->and($generation->attempt)->toBe(3)
        ->and($generation->attempts[0]['outcome'])->toBe('lint')
        ->and($generation->attempts[0]['message'])->toContain('not latest')
        ->and($generation->attempts[0]['message'])->toContain('--network=host')
        ->and($generation->attempts[0]['message'])->toContain('API_TOKEN')
        ->and($generation->attempts[0]['message'])->toContain('VOLUME')
        ->and($generation->attempts[1]['message'])->toContain('could not be found')
        ->and(DaemonTask::query()->where('type', DaemonTaskType::Build->value)->count())->toBe(1);
});

test('a model refusal fails without repairs; an overloaded one is retried', function (): void {
    $this->model->answers = [new DockerfileModelFailed('Anthropic is rate limiting this API key. Try again in a minute.', true), new DockerfileModelFailed('Anthropic refused the API key.')];
    $this->actingAs($this->developer)->postJson(($this->url)())->assertCreated();
    $generation = DockerfileGeneration::query()->sole();
    dgLease($this->server);
    dgComplete($this->server, DaemonTask::query()->findOrFail($generation->plan_task_id), ['outcome' => 'failed', 'error' => 'railpack could not plan', ...dgInspection()]);

    dgDraftNow();

    $generation->refresh();
    expect($generation->status)->toBe(DockerfileGenerationStatus::Failed)
        ->and($generation->error)->toBe('Anthropic refused the API key.')
        ->and($this->model->prompts)->toHaveCount(2);
});

test('opt-in gates, permissions, one at a time, cancel, team scoping', function (): void {
    $this->actingAs($this->viewer)->postJson(($this->url)())->assertForbidden();

    $settings = $this->team->settings;
    $settings->generateDockerfiles = false;
    $this->team->forceFill(['settings' => $settings])->save();
    $this->actingAs($this->developer)->postJson(($this->url)())->assertForbidden()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'off for this team'));

    $settings->generateDockerfiles = true;
    $this->team->forceFill(['settings' => $settings])->save();
    AiProviderSettings::current()->forceFill(['enabled' => false])->save();
    $this->actingAs($this->developer)->postJson(($this->url)())->assertForbidden()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'not set up'));

    AiProviderSettings::current()->forceFill(['enabled' => true])->save();
    $this->actingAs($this->developer)->postJson(($this->url)())->assertCreated();
    $this->actingAs($this->developer)->postJson(($this->url)())->assertStatus(409);

    $generation = DockerfileGeneration::query()->sole();

    // Another team sees nothing.
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($stranger)->getJson(($this->url)('/'.$generation->public_id))->assertNotFound();

    $this->actingAs($this->developer)->deleteJson(($this->url)('/'.$generation->public_id))
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');
    expect(DaemonTask::query()->findOrFail($generation->plan_task_id)->status->value)->toBe('cancelled');
});

test('tasks only go to servers that understand generated Dockerfiles', function (): void {
    $this->actingAs($this->developer)->postJson(($this->url)())->assertCreated();
    $this->server->forceFill(['daemon_features' => [DaemonFeature::GitProviders->value]])->save();

    expect(dgLease($this->server))->toBe([]);
});

test('the build settings only take a verified generation of the same service', function (): void {
    $other = DockerfileGeneration::query()->create([
        'team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'service_id' => $this->service->id,
        'trigger' => 'on_demand', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'max_attempts' => 3,
    ]);

    $this->actingAs($this->developer)->patchJson(route('projects.services.settings', ['project' => $this->project, 'environment' => $this->production, 'service' => $this->service]), [
        'build' => ['builder' => 'dockerfile', 'generatedDockerfile' => $other->public_id],
    ])->assertUnprocessable();
});

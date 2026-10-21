<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CopyEnvironmentIntent;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Environments\DeleteEnvironment;
use App\Actions\Environments\DiscardChanges;
use App\Actions\Environments\UpdateEnvironment;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CopyEnvironmentOptions;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Environments\DeleteEnvironmentData;
use App\Data\Environments\EnvironmentTriggers;
use App\Data\Environments\UpdateEnvironmentData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\DeploySettings;
use App\Data\Services\HealthcheckSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Data\Variables\VariableUpdate;
use App\Enums\AuditAction;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\EnvironmentKind;
use App\Enums\PromotionKind;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Variable;
use App\Models\Volume;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Promotions\PromotionPlanner;
use App\Services\Variables\VariableStore;
use App\Support\Changes\SpecDiff;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Queue::fake();
});

/**
 * @param  list<Environment>  $environments
 */
function envVar(Project $project, ?Service $service, string $key, string $value, array $environments, VariableType $type = VariableType::Config): Variable
{
    return resolve(CreateVariable::class)->handle($project, $service, VariableInput::from([
        'key' => $key,
        'value' => $value,
        'type' => $type,
        'targets' => array_map(static fn (Environment $environment): VariableTargetData => VariableTargetData::environment($environment->public_id), $environments),
    ]), null, AuditActor::system());
}

function envValue(Project $project, Environment $environment, ?Service $service, string $key): ?string
{
    $row = Variable::query()->where('project_id', $project->id)->where('service_id', $service?->id)->where('key', $key)
        ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $environment->id))->first();

    return $row === null ? null : resolve(VariableStore::class)->plaintext($project, $row);
}

function envCreate(Project $project, string $name, ?CopyEnvironmentOptions $options = null): Environment
{
    return resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData($name), null, AuditActor::system(), $options ?? new CopyEnvironmentOptions);
}

/**
 * @return array{Project, Environment, Service, Service}
 */
function envProject(): array
{
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team, 'shop');
    $postgres = Projects::postgres($project);
    $web = Projects::image($project, 'web');
    resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

    return [$project, $project->production()->sole(), $postgres, $web];
}

test('a new environment copies services, settings, volumes and variables, with fresh credentials and its own rows', function (): void {
    [$project, $production, $postgres, $web] = envProject();
    $instance = ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $web->id)->sole();
    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['deploy' => new DeploySettings(port: 80, healthcheck: new HealthcheckSettings(path: '/health'))]));
    Volume::query()->create(['team_id' => $project->team_id, 'service_instance_id' => $instance->id, 'mount_path' => '/uploads']);

    envVar($project, $web, 'APP_NAME', 'shop', [$production]);
    envVar($project, $web, 'STRIPE_SECRET', 'sk_live_1', [$production], VariableType::Secret);
    envVar($project, $web, 'SESSION_KEY', '${{ secret(16) }}', [$production], VariableType::Secret);
    $shared = envVar($project, null, 'SENTRY_DSN', 'https://sentry.example/1', [$production]);
    $shared->usedBy()->attach($web->id);

    $staging = envCreate($project, 'Staging');
    $stagingWeb = ServiceInstance::query()->where('environment_id', $staging->id)->where('service_id', $web->id)->sole();

    expect($staging->kind)->toBe(EnvironmentKind::Persistent)
        ->and($staging->triggers->branch)->toBeNull()
        ->and($stagingWeb->deploy->healthcheck->path)->toBe('/health')
        ->and($stagingWeb->volumes()->pluck('mount_path')->all())->toBe(['/uploads'])
        ->and(envValue($project, $staging, $web, 'APP_NAME'))->toBe('shop')
        ->and(envValue($project, $staging, $web, 'STRIPE_SECRET'))->toBe('sk_live_1')
        // Generated values are generated afresh, database credentials too.
        ->and(envValue($project, $staging, $web, 'SESSION_KEY'))->toHaveLength(16)
        ->and(envValue($project, $staging, $web, 'SESSION_KEY'))->not->toBe(envValue($project, $production, $web, 'SESSION_KEY'))
        ->and(envValue($project, $staging, $postgres, 'POSTGRES_PASSWORD'))->not->toBe(envValue($project, $production, $postgres, 'POSTGRES_PASSWORD'))
        ->and(envValue($project, $staging, null, 'SENTRY_DSN'))->toBe('https://sentry.example/1')
        // Each environment has its own rows: editing one doesn't touch the other.
        ->and(Variable::query()->where('key', 'APP_NAME')->count())->toBe(2)
        ->and(Variable::query()->where('key', 'SENTRY_DSN')->whereHas('targets', fn (Builder $q) => $q->where('environment_id', $staging->id))->sole()->usedBy()->pluck('services.id')->all())->toBe([$web->id]);

    $created = EnvironmentPromotion::query()->sole();

    expect($created->kind)->toBe(PromotionKind::Created)
        ->and($created->from_environment_id)->toBe($production->id)
        ->and($created->to_environment_id)->toBe($staging->id)
        ->and(json_encode($created->getAttributes()))->not->toContain('sk_live_1');

    // Right after the copy there is nothing to promote, in either direction.
    $planner = resolve(PromotionPlanner::class);
    $selectable = static fn (array $items): array => array_values(array_filter($items, static fn ($item): bool => $item->selectable));

    expect($selectable($planner->plan($staging, $production)->items))->toBe([])
        ->and($selectable($planner->plan($production, $staging)->items))->toBe([]);
});

test('copy options: no secrets, a subset of services, and never production secrets into previews under the policy', function (): void {
    [$project, $production, $postgres, $web] = envProject();
    envVar($project, $web, 'STRIPE_SECRET', 'sk_live_1', [$production], VariableType::Secret);
    envVar($project, $web, 'APP_NAME', 'shop', [$production]);

    $qa = envCreate($project, 'qa', new CopyEnvironmentOptions(secrets: false, serviceIds: [$web->public_id], recordBase: false));

    expect(ServiceInstance::query()->where('environment_id', $qa->id)->pluck('service_id')->all())->toBe([$web->id])
        ->and(envValue($project, $qa, $web, 'STRIPE_SECRET'))->toBeNull()
        ->and(envValue($project, $qa, $web, 'APP_NAME'))->toBe('shop')
        ->and(EnvironmentPromotion::query()->count())->toBe(0);

    $project->forceFill(['settings' => ['productionSecretsMustDiffer' => true]])->save();
    $preview = Environment::query()->create([
        'team_id' => $project->team_id, 'project_id' => $project->id, 'name' => 'pr-1', 'slug' => 'pr-1',
        'kind' => EnvironmentKind::Preview, 'base_environment_id' => $production->id, 'network_subnet' => '10.231.9.0/24',
    ]);
    resolve(CopyEnvironmentIntent::class)->handle($production, $preview);

    expect(envValue($project, $preview, $web, 'STRIPE_SECRET'))->toBeNull()
        ->and(envValue($project, $preview, $web, 'APP_NAME'))->toBe('shop');

    // Not through staging either: staging copied production's value, and a
    // preview based on staging gets no secret equal to production's (M6).
    $staging = envCreate($project, 'staging');
    envVar($project, $web, 'STAGING_ONLY', 'sk_test_own', [$staging], VariableType::Secret);
    expect(envValue($project, $staging, $web, 'STRIPE_SECRET'))->toBe('sk_live_1');
    $fromStaging = Environment::query()->create([
        'team_id' => $project->team_id, 'project_id' => $project->id, 'name' => 'pr-2', 'slug' => 'pr-2',
        'kind' => EnvironmentKind::Preview, 'base_environment_id' => $staging->id, 'network_subnet' => '10.231.10.0/24',
    ]);
    resolve(CopyEnvironmentIntent::class)->handle($staging, $fromStaging);

    expect(envValue($project, $fromStaging, $web, 'STRIPE_SECRET'))->toBeNull()
        ->and(envValue($project, $fromStaging, $web, 'STAGING_ONLY'))->toBe('sk_test_own');
});

test('rename and triggers: globs by grammar, previews have none, audited, staged with effect none', function (): void {
    [$project, $production] = envProject();
    $staging = envCreate($project, 'staging');
    resolve(DeployEnvironment::class)->handle($staging, null, AuditActor::system());

    resolve(UpdateEnvironment::class)->handle($staging, UpdateEnvironmentData::from(['name' => 'Pre-production', 'triggers' => ['branch' => 'release/*', 'tagPattern' => ' ']]), AuditActor::system());
    $staging->refresh();

    expect($staging->name)->toBe('Pre-production')
        ->and($staging->slug)->toBe('staging')
        ->and($staging->triggers->branch)->toBe('release/*')
        ->and($staging->triggers->tagPattern)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::EnvironmentUpdated->value)->exists())->toBeTrue()
        ->and(collect(SpecDiff::between($staging->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($staging))->items)->pluck('path')->all())
        ->toEqualCanonicalizing(['environment.name', 'environment.triggers'])
        ->and(fn () => resolve(UpdateEnvironment::class)->handle($production, UpdateEnvironmentData::from(['name' => 'pre-production']), AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'already has this name');

    // Production may track tags only.
    resolve(UpdateEnvironment::class)->handle($production, UpdateEnvironmentData::from(['triggers' => ['branch' => null, 'tagPattern' => 'v*']]), AuditActor::system());

    expect($production->refresh()->triggers->branch)->toBeNull()
        ->and($production->triggers->tagPattern)->toBe('v*');
});

test('triggers are validated over HTTP', function (): void {
    [$project] = envProject();
    $staging = envCreate($project, 'staging');
    $user = memberOf($project->team, TeamRole::Developer);

    $this->actingAs($user)
        ->patch("/p/{$project->public_id}/environments/{$staging->public_id}", ['triggers' => ['branch' => 'main; rm -rf', 'tagPattern' => null]])
        ->assertSessionHasErrors('triggers.branch');

    $this->actingAs($user)
        ->patch("/p/{$project->public_id}/environments/{$staging->public_id}", ['name' => 'QA', 'triggers' => ['branch' => 'develop', 'tagPattern' => 'v*']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($staging->refresh()->triggers->branch)->toBe('develop');

    $this->actingAs(memberOf($project->team, TeamRole::Viewer))
        ->patch("/p/{$project->public_id}/environments/{$staging->public_id}", ['name' => 'nope'])
        ->assertForbidden();
});

test('deleting an environment: typed name, never production, rows that only applied there go', function (): void {
    [$project, $production, $postgres, $web] = envProject();
    $staging = envCreate($project, 'staging');
    $both = envVar($project, $web, 'BOTH', 'x', [$production, $staging]);
    $worker = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'cache', 'kind' => ServiceKind::Image, 'source' => ['type' => 'image', 'reference' => 'redis@'.Projects::DIGEST], 'port' => 6379,
    ]), null, AuditActor::system(), $staging);

    expect(fn () => resolve(DeleteEnvironment::class)->handle($production, new DeleteEnvironmentData('production'), AuditActor::system()))->toThrow(DomainRuleViolation::class)
        ->and(fn () => resolve(DeleteEnvironment::class)->handle($staging, new DeleteEnvironmentData('Staging'), AuditActor::system()))->toThrow(DomainRuleViolation::class, 'Type staging');

    resolve(DeleteEnvironment::class)->handle($staging, new DeleteEnvironmentData('staging'), AuditActor::system());

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeFalse()
        ->and(Variable::query()->whereKey($both->id)->sole()->targets()->pluck('environment_id')->all())->toBe([$production->id])
        // Staging's own copies (and its database credentials) are gone with it.
        ->and(Variable::query()->where('service_id', $postgres->id)->where('key', 'POSTGRES_PASSWORD')->count())->toBe(1)
        ->and(Service::query()->whereKey($worker->id)->exists())->toBeFalse()
        ->and(Service::query()->whereKey($web->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::EnvironmentDeleted->value)->exists())->toBeTrue();
});

test('deleting over HTTP needs a recent password and the delete ability', function (): void {
    [$project] = envProject();
    $staging = envCreate($project, 'staging');
    $developer = memberOf($project->team, TeamRole::Developer);

    $this->actingAs(memberOf($project->team, TeamRole::Viewer))->withSession(passwordConfirmed())
        ->delete("/p/{$project->public_id}/environments/{$staging->public_id}", ['confirmName' => 'staging'])
        ->assertForbidden();

    $this->actingAs($developer)->withSession(passwordConfirmed())
        ->delete("/p/{$project->public_id}/environments/{$staging->public_id}", ['confirmName' => 'staging'])
        ->assertRedirect("/p/{$project->public_id}/settings/environments");

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeFalse();
});

test('resume auto-deploy after a rollback', function (): void {
    [$project, $production] = envProject();
    $production->forceFill(['auto_deploy_paused' => true])->save();

    $this->actingAs(memberOf($project->team, TeamRole::Viewer))
        ->post("/p/{$project->public_id}/environments/{$production->public_id}/resume")
        ->assertForbidden();

    $this->actingAs(memberOf($project->team, TeamRole::Developer))
        ->post("/p/{$project->public_id}/environments/{$production->public_id}/resume")
        ->assertRedirect();

    expect($production->refresh()->auto_deploy_paused)->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::AutoDeployResumed->value)->exists())->toBeTrue();
});

test('revert to revision N restores its intent as staged changes with new value versions', function (): void {
    [$project, $production, , $web] = envProject();
    $variable = envVar($project, $web, 'MODE', 'one', [$production]);
    $first = resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system())->revision;

    resolve(UpdateVariable::class)->handle($variable, VariableUpdate::from(['value' => 'two']), null, AuditActor::system());
    envVar($project, $web, 'EXTRA', 'x', [$production]);
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());

    $this->actingAs(memberOf($project->team, TeamRole::Developer))
        ->post("/p/{$project->public_id}/{$production->public_id}/revisions/{$first->public_id}/revert")
        ->assertRedirect("/p/{$project->public_id}/{$production->public_id}/changes");

    $variable->refresh();
    $staged = SpecDiff::between($production->refresh()->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($production));

    expect(envValue($project, $production, $web, 'MODE'))->toBe('one')
        ->and($variable->version)->toBe(3)
        ->and(envValue($project, $production, $web, 'EXTRA'))->toBeNull()
        ->and($staged->items)->toHaveCount(2)
        ->and(AuditEvent::query()->where('action', AuditAction::EnvironmentReverted->value)->exists())->toBeTrue();

    // A revision of another environment is a 404.
    $staging = envCreate($project, 'staging');
    $this->actingAs(memberOf($project->team, TeamRole::Developer))
        ->post("/p/{$project->public_id}/{$staging->public_id}/revisions/{$first->public_id}/revert")
        ->assertNotFound();

    expect(fn () => resolve(DiscardChanges::class)->handle($staging, null, AuditActor::system(), $first))->toThrow(DomainRuleViolation::class);
});

test('the settings page lists environments with triggers, revision and abilities', function (): void {
    [$project, $production] = envProject();
    $staging = envCreate($project, 'staging');
    resolve(UpdateEnvironment::class)->handle($staging, UpdateEnvironmentData::from(['triggers' => new EnvironmentTriggers(branch: 'develop')]), AuditActor::system());

    $this->actingAs(memberOf($project->team, TeamRole::Developer))
        ->get("/p/{$project->public_id}/settings/environments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('projects/settings')
            ->where('environmentSettings.0.id', $production->public_id)
            ->where('environmentSettings.0.canDelete', false)
            ->where('environmentSettings.0.triggers.branch', 'main')
            ->where('environmentSettings.1.name', 'staging')
            ->where('environmentSettings.1.triggers.branch', 'develop')
            ->where('environmentSettings.1.baseName', 'production')
            ->where('environmentSettings.1.canDelete', true)
            ->where('secretsPolicy.project', false));
});

test('the project deployments page filters by a persistent environment', function (): void {
    [$project] = envProject();
    $staging = envCreate($project, 'staging');

    $this->actingAs(memberOf($project->team, TeamRole::Viewer))
        ->get("/p/{$project->public_id}/deployments?environment={$staging->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('environment.id', $staging->public_id)->where('filters.environment', $staging->public_id));
});

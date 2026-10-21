<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Projects\SetSecretsPolicy;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Data\Variables\VariableUpdate;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Variables\ProductionSecretPolicy;
use App\Services\Variables\VariableStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Queue::fake();
});

/**
 * @param  list<VariableTargetData>  $targets
 */
function epVar(Project $project, ?Service $service, string $key, string $value, array $targets, VariableType $type = VariableType::Secret, ?User $user = null): Variable
{
    return resolve(CreateVariable::class)->handle($project, $service, VariableInput::from([
        'key' => $key, 'value' => $value, 'type' => $type, 'targets' => $targets,
    ]), $user, AuditActor::system());
}

function epPreview(Project $project): Environment
{
    return Environment::query()->create([
        'team_id' => $project->team_id, 'project_id' => $project->id, 'name' => 'pr-3', 'slug' => 'pr-3',
        'kind' => EnvironmentKind::Preview, 'base_environment_id' => $project->production()->sole()->id, 'network_subnet' => '10.231.33.0/24',
    ]);
}

test('environment, promotion and staged-deployment abilities by role', function (TeamRole $role, array $expected): void {
    $team = Team::factory()->create();
    $project = Projects::create($team);
    $production = $project->production()->sole();
    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());
    $user = memberOf($team, $role);
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $ready = new Deployment(['team_id' => $team->id]);
    $ready->status = DeploymentStatus::Ready;
    $promotion = EnvironmentPromotion::query()->sole();

    $abilities = [
        'update' => Gate::forUser($user)->allows('update', $staging),
        'delete staging' => Gate::forUser($user)->allows('delete', $staging),
        'delete production' => Gate::forUser($user)->allows('delete', $production),
        'promote' => Gate::forUser($user)->allows('promote', $production),
        'resume' => Gate::forUser($user)->allows('deploy', $production),
        'promote deployment' => Gate::forUser($user)->allows('promote', $ready),
        'view promotion' => Gate::forUser($user)->allows('view', $promotion),
    ];

    expect($abilities)->toBe($expected)
        ->and(Gate::forUser($outsider)->allows('promote', $production))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('view', $promotion))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('update', $staging))->toBeFalse();
})->with([
    'owner' => [TeamRole::Owner, ['update' => true, 'delete staging' => true, 'delete production' => false, 'promote' => true, 'resume' => true, 'promote deployment' => true, 'view promotion' => true]],
    'admin' => [TeamRole::Admin, ['update' => true, 'delete staging' => true, 'delete production' => false, 'promote' => true, 'resume' => true, 'promote deployment' => true, 'view promotion' => true]],
    'developer' => [TeamRole::Developer, ['update' => true, 'delete staging' => true, 'delete production' => false, 'promote' => true, 'resume' => true, 'promote deployment' => true, 'view promotion' => true]],
    'viewer' => [TeamRole::Viewer, ['update' => false, 'delete staging' => false, 'delete production' => false, 'promote' => false, 'resume' => false, 'promote deployment' => false, 'view promotion' => true]],
]);

test('"production secrets must differ from preview": refused on save, on enabling and on deploy', function (): void {
    $team = Team::factory()->create();
    $project = Projects::create($team);
    $web = Projects::image($project, 'web');
    $production = $project->production()->sole();
    $preview = epPreview($project);
    $owner = memberOf($team, TeamRole::Owner);

    epVar($project, $web, 'STRIPE_KEY', 'sk_same', [VariableTargetData::environment($production->public_id)]);
    $previewRow = epVar($project, $web, 'STRIPE_KEY', 'sk_same', [new VariableTargetData(allPreviews: true)]);

    // Turning it on while secrets already violate it is refused, naming the key.
    expect(fn () => resolve(SetSecretsPolicy::class)->handle($project, true, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'STRIPE_KEY (web) has the same value in production and in previews');

    resolve(UpdateVariable::class)->handle($previewRow, VariableUpdate::from(['value' => 'sk_test']), $owner, AuditActor::system());
    resolve(SetSecretsPolicy::class)->handle($project->refresh(), true, AuditActor::system());

    expect($project->refresh()->settings->productionSecretsMustDiffer)->toBeTrue()
        // Saving the same value again is refused…
        ->and(fn () => resolve(UpdateVariable::class)->handle($previewRow->refresh(), VariableUpdate::from(['value' => 'sk_same']), $owner, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'must differ')
        // …so is one row for both.
        ->and(fn (): Variable => epVar($project, $web, 'OTHER', 'x', [VariableTargetData::environment($production->public_id), VariableTargetData::environment($preview->public_id)]))
        ->toThrow(DomainRuleViolation::class, 'must differ');

    // A violation that slipped in (e.g. written before the policy) blocks production deploys.
    $row = Variable::query()->whereKey($previewRow->id)->sole();
    $copy = Variable::query()->where('key', 'STRIPE_KEY')->whereKeyNot($row->id)->sole();
    $row->forceFill(['value' => resolve(ProjectKeyring::class)->encryptVariable($project, $row->public_id, $row->version, 'sk_same')])->save();

    expect(resolve(VariableStore::class)->plaintext($project, $copy))->toBe('sk_same')
        ->and(fn () => resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'must differ');

    // The team setting forces it for every project.
    $other = Projects::create($team, 'Other');
    $team->forceFill(['settings' => [...$team->settings->toArray(), 'productionSecretsMustDiffer' => true]])->save();

    expect(resolve(ProductionSecretPolicy::class)->enabled($other->refresh()))->toBeTrue();
});

test('the secrets policy endpoint needs project management', function (): void {
    $team = Team::factory()->create();
    $project = Projects::create($team);

    $this->actingAs(memberOf($team, TeamRole::Viewer))
        ->put("/p/{$project->public_id}/settings/secrets-policy", ['productionSecretsMustDiffer' => true])
        ->assertForbidden();

    $this->actingAs(memberOf($team, TeamRole::Developer))
        ->put("/p/{$project->public_id}/settings/secrets-policy", ['productionSecretsMustDiffer' => true])
        ->assertRedirect();

    expect($project->refresh()->settings->productionSecretsMustDiffer)->toBeTrue();
});

test('editing a row shared by several environments for one of them splits it', function (): void {
    $team = Team::factory()->create();
    $project = Projects::create($team);
    $web = Projects::image($project, 'web');
    $production = $project->production()->sole();
    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());
    $row = epVar($project, $web, 'LOG_LEVEL', 'info', [VariableTargetData::environment($production->public_id), VariableTargetData::environment($staging->public_id)], VariableType::Config);
    $developer = memberOf($team, TeamRole::Developer);

    $this->actingAs($developer)
        ->patchJson("/p/{$project->public_id}/{$staging->public_id}/-/variables/{$row->public_id}", ['value' => 'debug', 'onlyEnvironment' => $staging->public_id])
        ->assertSuccessful();

    $store = resolve(VariableStore::class);
    $stagingRow = Variable::query()->where('key', 'LOG_LEVEL')->whereKeyNot($row->id)->sole();

    expect($row->targets()->pluck('environment_id')->all())->toBe([$production->id])
        ->and($store->plaintext($project, $row->refresh()))->toBe('info')
        ->and($stagingRow->targets()->pluck('environment_id')->all())->toBe([$staging->id])
        ->and($store->plaintext($project, $stagingRow))->toBe('debug');

    // A row that already applies to one environment only is edited in place.
    $this->actingAs($developer)
        ->patchJson("/p/{$project->public_id}/{$staging->public_id}/-/variables/{$stagingRow->public_id}", ['value' => 'warn', 'onlyEnvironment' => $staging->public_id])
        ->assertSuccessful();

    expect(Variable::query()->where('key', 'LOG_LEVEL')->count())->toBe(2)
        ->and($store->plaintext($project, $stagingRow->refresh()))->toBe('warn');
});

test('adding a service on a canvas adds it to that environment only', function (): void {
    $team = Team::factory()->create();
    $project = Projects::create($team);
    $production = $project->production()->sole();
    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    $this->actingAs(memberOf($team, TeamRole::Developer))
        ->post("/p/{$project->public_id}/{$staging->public_id}/services", [
            'name' => 'cache', 'kind' => 'database', 'engine' => 'redis', 'version' => '7',
        ])
        ->assertRedirect();

    $service = Service::query()->where('slug', 'cache')->sole();

    expect(ServiceInstance::query()->where('service_id', $service->id)->pluck('environment_id')->all())->toBe([$staging->id])
        ->and(ServiceInstance::query()->where('service_id', $service->id)->where('environment_id', $production->id)->exists())->toBeFalse();
});

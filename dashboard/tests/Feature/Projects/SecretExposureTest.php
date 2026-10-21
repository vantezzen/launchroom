<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\ImportVariables;
use App\Actions\Variables\SetSharedVariableUsage;
use App\Actions\Variables\UpdateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Data\Variables\VariableUpdate;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Models\Service;
use App\Models\Team;
use App\Models\Variable;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Builds\BuildTasks;
use App\Services\Variables\VariableStore;
use App\Support\Variables\SecretTaint;
use Tests\Support\Projects;

/**
 * M4 (2026-10 security review): secrets must not reach browser-inlined
 * variables or Docker build args through `${{…}}` references.
 */
beforeEach(fn () => Projects::useTestKek());

function exposureInput(string $key, string $value, Environment $environment, VariableType $type = VariableType::Config, array $extra = []): VariableInput
{
    return VariableInput::from(['key' => $key, 'value' => $value, 'type' => $type, 'targets' => [VariableTargetData::environment($environment->public_id)], ...$extra]);
}

test('a browser-inlined variable cannot reference a secret directly', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $web = Projects::image($project);
    $create = static fn (string $key, string $value, VariableType $type = VariableType::Config) => resolve(CreateVariable::class)
        ->handle($project, $web, exposureInput($key, $value, $production, $type), null, AuditActor::system());

    $create('API_TOKEN', 'tok_live_123456', VariableType::Secret);

    expect(fn () => $create('NEXT_PUBLIC_TOKEN', 'Bearer ${{API_TOKEN}}'))
        ->toThrow(DomainRuleViolation::class, 'web.NEXT_PUBLIC_TOKEN is baked into the public browser bundle, but its value references the secret web.API_TOKEN')
        ->and(Variable::query()->where('key', 'NEXT_PUBLIC_TOKEN')->exists())->toBeFalse();

    // Config references stay allowed.
    $create('API_HOST', 'api.example.com');
    $create('NEXT_PUBLIC_API', 'https://${{API_HOST}}/v1');
    expect(Variable::query()->where('key', 'NEXT_PUBLIC_API')->exists())->toBeTrue();
});

test('a browser-inlined variable cannot reach a secret transitively, across services and shared variables', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    Projects::postgres($project);
    $web = Projects::image($project);
    $create = static fn (?Service $service, string $key, string $value, VariableType $type = VariableType::Config) => resolve(CreateVariable::class)
        ->handle($project, $service, exposureInput($key, $value, $production, $type), null, AuditActor::system());

    // postgres.DATABASE_URL is config that embeds the generated password secret.
    expect(fn () => $create($web, 'VITE_DB', '${{postgres.DATABASE_URL}}'))->toThrow(DomainRuleViolation::class, 'browser bundle');

    // Through a config variable of the same service.
    $create($web, 'DSN', '${{postgres.DATABASE_URL}}');
    expect(fn () => $create($web, 'PUBLIC_DSN', 'dsn=${{DSN}}'))->toThrow(DomainRuleViolation::class, 'references the secret postgres.');

    // Through shared variables (config → secret inside the shared scope).
    $create(null, 'SIGNING_KEY', 'sk_live_abcdef', VariableType::Secret);
    $create(null, 'SIGNING', 'key=${{SIGNING_KEY}}');
    expect(fn () => $create($web, 'REACT_APP_SIGNING', '${{shared.SIGNING}}'))->toThrow(DomainRuleViolation::class, 'shared.SIGNING_KEY');

    // Opting into a shared browser-inlined variable that embeds a secret.
    $shared = resolve(VariableStore::class)->create($project, null, 'GATSBY_KEY', '${{shared.SIGNING_KEY}}', VariableType::Config, [['environment_id' => $production->id, 'all_previews' => false, 'branch_pattern' => null]]);
    expect(fn () => resolve(SetSharedVariableUsage::class)->handle($shared, $web, true))->toThrow(DomainRuleViolation::class, 'GATSBY_KEY');
    expect($shared->usedBy()->count())->toBe(0);
});

test('turning a referenced variable into a secret, or importing such a reference, is refused', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $web = Projects::image($project);
    $create = static fn (string $key, string $value, VariableType $type = VariableType::Config) => resolve(CreateVariable::class)
        ->handle($project, $web, exposureInput($key, $value, $production, $type), null, AuditActor::system());

    $plan = $create('PLAN', 'free');
    $create('NEXT_PUBLIC_PLAN', '${{PLAN}}');

    expect(fn () => resolve(UpdateVariable::class)->handle($plan, VariableUpdate::from(['type' => VariableType::Secret]), null, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'NEXT_PUBLIC_PLAN')
        ->and($plan->refresh()->type)->toBe(VariableType::Config);

    $create('STRIPE_SECRET', 'sk_live_xyz', VariableType::Secret);

    expect(fn () => resolve(ImportVariables::class)->handle($project, $web, "EXPO_PUBLIC_STRIPE=\${{STRIPE_SECRET}}\n", [VariableTargetData::environment($production->public_id)], null, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'EXPO_PUBLIC_STRIPE')
        ->and(Variable::query()->where('key', 'EXPO_PUBLIC_STRIPE')->exists())->toBeFalse();
});

test('a deploy is blocked when a browser-inlined variable embeds a secret (rows that predate the check)', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $web = Projects::image($project);
    $here = [['environment_id' => $production->id, 'all_previews' => false, 'branch_pattern' => null]];
    $store = resolve(VariableStore::class);
    $store->create($project, $web, 'TOKEN', 'secret-token-value', VariableType::Secret, $here);
    $store->create($project, $web, 'NEXT_PUBLIC_TOKEN', '${{TOKEN}}', VariableType::Config, $here);

    expect(fn () => resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'Secrets in public variables: web.NEXT_PUBLIC_TOKEN is baked into the public browser bundle');
});

test('config variables that resolve a secret are never Docker build args', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $postgres = Projects::postgres($project);
    $web = Projects::image($project);
    resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    $create = static fn (string $key, string $value, ?VariableExposure $exposure = null) => resolve(CreateVariable::class)
        ->handle($project, $web, exposureInput($key, $value, $production, VariableType::Config, ['exposureOverride' => $exposure]), null, AuditActor::system());

    // Connection-injected DATABASE_URL (config → postgres secret), exposed to the build.
    $url = Variable::query()->where('service_id', $web->id)->where('key', 'DATABASE_URL')->sole();
    $url->forceFill(['exposure_override' => VariableExposure::Both])->save();
    $create('APP_NAME', 'shop', VariableExposure::Both);
    $create('APP_DSN', 'dsn ${{DATABASE_URL}}', VariableExposure::Build);

    $spec = resolve(CurrentEnvironmentSpec::class)->get($production);
    $service = $spec->services[$web->public_id];
    $args = (fn (): array => $this->buildArgs($spec, $service))->call(resolve(BuildTasks::class));

    expect($args)->toBe(['APP_NAME'])
        ->and(SecretTaint::fromSpec($spec)->taintedKeys('web'))->toContain('DATABASE_URL', 'APP_DSN')
        ->and(SecretTaint::fromSpec($spec)->isTainted('web', 'APP_NAME'))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CopyEnvironmentIntent;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Promotions\PromoteChanges;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Promotions\PromoteChangesData;
use App\Data\Promotions\PromotionItem;
use App\Data\Services\CreateServiceData;
use App\Data\Services\DeploySettings;
use App\Data\Services\HealthcheckSettings;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Data\Variables\VariableUpdate;
use App\Enums\AuditAction;
use App\Enums\ChangeKind;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Enums\PromotionKind;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use App\Queries\CurrentEnvironmentSpec;
use App\Queries\PromotionComparison;
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
function prVar(Project $project, ?Service $service, string $key, string $value, array $environments, VariableType $type = VariableType::Config): Variable
{
    return resolve(CreateVariable::class)->handle($project, $service, VariableInput::from([
        'key' => $key, 'value' => $value, 'type' => $type,
        'targets' => array_map(static fn (Environment $environment): VariableTargetData => VariableTargetData::environment($environment->public_id), $environments),
    ]), null, AuditActor::system());
}

function prRow(Project $project, Environment $environment, ?Service $service, string $key): ?Variable
{
    return Variable::query()->where('project_id', $project->id)->where('service_id', $service?->id)->where('key', $key)
        ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $environment->id))->first();
}

function prValue(Project $project, Environment $environment, ?Service $service, string $key): ?string
{
    $row = prRow($project, $environment, $service, $key);

    return $row instanceof Variable ? resolve(VariableStore::class)->plaintext($project, $row) : null;
}

function prInstance(Environment $environment, Service $service): ServiceInstance
{
    return ServiceInstance::query()->current()->where('environment_id', $environment->id)->where('service_id', $service->id)->sole();
}

/**
 * @return array<string, PromotionItem>
 */
function prItems(Environment $from, Environment $to): array
{
    $items = [];

    foreach (resolve(PromotionPlanner::class)->plan($from, $to)->items as $item) {
        $items[$item->path] = $item;
    }

    return $items;
}

/**
 * The dialog's defaults: every pre-checked item, plus $extra (path => [value?, copyValue?]).
 *
 * @param  array<string, array{value?: string, copyValue?: bool}>  $extra
 * @param  list<string>  $skip
 * @param  list<string>  $images
 */
function prPromote(Project $project, Environment $from, Environment $to, array $extra = [], array $skip = [], array $images = [], ?User $user = null): EnvironmentPromotion
{
    $items = [];

    foreach (prItems($from, $to) as $path => $item) {
        if (($item->selected || isset($extra[$path])) && ! in_array($path, $skip, true)) {
            $items[] = ['path' => $path, ...($extra[$path] ?? [])];
        }
    }

    foreach ($extra as $path => $input) {
        if (! in_array($path, array_column($items, 'path'), true)) {
            $items[] = ['path' => $path, ...$input];
        }
    }

    return resolve(PromoteChanges::class)->handle($project, PromoteChangesData::from([
        'from' => $from->public_id, 'to' => $to->public_id, 'items' => $items, 'images' => $images,
    ]), $user ?? User::factory()->create(), AuditActor::system());
}

/**
 * Production deployed with web + postgres, and staging created from it.
 *
 * @return array{Project, Environment, Environment, Service, Service}
 */
function prProject(): array
{
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team, 'shop');
    $postgres = Projects::postgres($project);
    $web = Projects::image($project, 'web');
    resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    $production = $project->production()->sole();
    prVar($project, $web, 'QUEUE_CONNECTION', 'database', [$production]);
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());

    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    return [$project, $production, $staging, $postgres, $web];
}

test('staging → production: the docs example lands as staged changes in production', function (): void {
    [$project, $production, $staging, , $web] = prProject();

    // In staging: add Redis and connect it, change a variable, add a secret,
    // raise the healthcheck timeout, add a worker, scale up.
    $redis = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'redis', 'kind' => ServiceKind::Database, 'source' => ['type' => 'database', 'engine' => 'redis', 'version' => '7'],
    ]), null, AuditActor::system(), $staging);
    resolve(CreateConnection::class)->handle($web, $redis, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    resolve(UpdateVariable::class)->handle(prRow($project, $staging, $web, 'QUEUE_CONNECTION'), VariableUpdate::from(['value' => 'redis']), null, AuditActor::system());
    prVar($project, $web, 'STRIPE_WEBHOOK_SECRET', 'whsec_staging', [$staging], VariableType::Secret);
    $stagingWeb = prInstance($staging, $web);
    resolve(UpdateServiceSettings::class)->handle($stagingWeb, UpdateServiceSettingsData::from([
        'deploy' => new DeploySettings(port: 80, healthcheck: new HealthcheckSettings(timeoutSeconds: 60)),
        'scaling' => new ScalingSettings(replicas: 2),
    ]));
    $worker = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'mailer', 'kind' => ServiceKind::Image, 'source' => ['type' => 'image', 'reference' => 'mailpit@'.Projects::DIGEST], 'port' => 8025, 'startCommand' => ['mailpit', '--smtp', '0.0.0.0:1025'],
    ]), null, AuditActor::system(), $staging);

    $items = prItems($staging, $production);
    $webPath = "services.{$web->public_id}";

    expect($items["services.{$redis->public_id}"]->kind)->toBe(ChangeKind::Added)
        ->and($items["services.{$redis->public_id}"]->selected)->toBeTrue()
        ->and($items["services.{$worker->public_id}"]->selected)->toBeTrue()
        ->and($items["{$webPath}.variables.QUEUE_CONNECTION"]->before)->toBe('database')
        ->and($items["{$webPath}.variables.QUEUE_CONNECTION"]->after)->toBe('redis')
        ->and($items["{$webPath}.variables.QUEUE_CONNECTION"]->selected)->toBeTrue()
        ->and($items["{$webPath}.variables.STRIPE_WEBHOOK_SECRET"]->needsValue)->toBeTrue()
        ->and($items["{$webPath}.deploy.healthcheck"]->selected)->toBeTrue()
        ->and($items["{$webPath}.scaling.replicas"]->selected)->toBeFalse()
        ->and(collect($items)->first(static fn (PromotionItem $item): bool => str_starts_with($item->path, 'connections.'))?->selectable)->toBeFalse()
        // The connection's REDIS_URL is owned by the connection: it follows the services.
        ->and(collect($items)->keys()->filter(static fn (string $path): bool => str_starts_with($path, "{$webPath}.variables.REDIS"))->all())->toBe([])
        // Redis's own credentials come with the new instance, generated afresh.
        ->and($items["services.{$redis->public_id}.variables.REDIS_PASSWORD"]->dependsOn)->toBe(["services.{$redis->public_id}"]);

    // A missing secret value is refused, with a clear message.
    expect(fn (): EnvironmentPromotion => prPromote($project, $staging, $production))->toThrow(DomainRuleViolation::class, 'enter the value for production');

    $promotion = prPromote($project, $staging, $production, ["{$webPath}.variables.STRIPE_WEBHOOK_SECRET" => ['value' => 'whsec_production']]);

    $prodWeb = prInstance($production, $web);
    $prodRedis = prInstance($production, $redis);

    expect($promotion->kind)->toBe(PromotionKind::Promoted)
        ->and($prodWeb->deploy->healthcheck->timeoutSeconds)->toBe(60)
        ->and($prodWeb->scaling->replicas)->toBe(1)
        ->and(prInstance($production, $worker)->deploy->startCommand)->toBe(['mailpit', '--smtp', '0.0.0.0:1025'])
        ->and($prodRedis->volumes()->count())->toBe(1)
        ->and(prValue($project, $production, $web, 'QUEUE_CONNECTION'))->toBe('redis')
        ->and(prValue($project, $production, $web, 'STRIPE_WEBHOOK_SECRET'))->toBe('whsec_production')
        // Fresh credentials for production's Redis.
        ->and(prValue($project, $production, $redis, 'REDIS_PASSWORD'))->not->toBe(prValue($project, $staging, $redis, 'REDIS_PASSWORD'))
        ->and(prValue($project, $production, $redis, 'REDIS_PASSWORD'))->not->toBeNull()
        ->and(json_encode($promotion->getAttributes()))->not->toContain('whsec_')
        ->and(AuditEvent::query()->where('action', AuditAction::ChangesPromoted->value)->sole()->meta['items'])->toContain("{$webPath}.deploy.healthcheck");

    // Production's staged changes now hold them; nothing went live.
    $staged = SpecDiff::between($production->refresh()->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($production));

    expect($staged->effectFor($redis->public_id)->value)->toBe('new')
        ->and($staged->effectFor($web->public_id)->value)->toBe('restart')
        ->and(Deployment::query()->where('environment_id', $production->id)->count())->toBe(2);

    // The next comparison offers only what was left out.
    expect(array_keys(array_filter(prItems($staging, $production), static fn (PromotionItem $item): bool => $item->selectable)))->toBe(["{$webPath}.scaling.replicas"]);
});

test('conflicts are offered unchecked; selecting one takes the source side', function (): void {
    [$project, $production, $staging, , $web] = prProject();
    resolve(UpdateVariable::class)->handle(prRow($project, $staging, $web, 'QUEUE_CONNECTION'), VariableUpdate::from(['value' => 'redis']), null, AuditActor::system());
    resolve(UpdateVariable::class)->handle(prRow($project, $production, $web, 'QUEUE_CONNECTION'), VariableUpdate::from(['value' => 'sqs']), null, AuditActor::system());

    $path = "services.{$web->public_id}.variables.QUEUE_CONNECTION";
    $item = prItems($staging, $production)[$path];

    expect($item->conflict)->toBeTrue()
        ->and($item->selected)->toBeFalse()
        ->and([$item->before, $item->after, $item->base])->toBe(['sqs', 'redis', null]);

    prPromote($project, $staging, $production, [$path => []]);

    expect(prValue($project, $production, $web, 'QUEUE_CONNECTION'))->toBe('redis');
});

test('the reverse direction works too: production → staging refreshes staging', function (): void {
    [$project, $production, $staging, , $web] = prProject();
    prVar($project, $web, 'FEATURE_FLAG', 'on', [$production]);

    $items = prItems($production, $staging);

    expect(array_keys($items))->toBe(["services.{$web->public_id}.variables.FEATURE_FLAG"]);

    prPromote($project, $production, $staging);

    expect(prValue($project, $staging, $web, 'FEATURE_FLAG'))->toBe('on')
        ->and(prItems($production, $staging))->toBe([])
        ->and(prItems($staging, $production))->toBe([]);
});

test('stale selections, missing dependencies and values on non-secrets are refused', function (): void {
    [$project, $production, $staging, , $web] = prProject();
    $redis = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'redis', 'kind' => ServiceKind::Database, 'source' => ['type' => 'database', 'engine' => 'redis', 'version' => '7'],
    ]), null, AuditActor::system(), $staging);
    prVar($project, $redis, 'MAXMEMORY', '256mb', [$staging]);

    $promote = static fn (array $items) => resolve(PromoteChanges::class)->handle($project, PromoteChangesData::from([
        'from' => $staging->public_id, 'to' => $production->public_id, 'items' => $items,
    ]), User::factory()->create(), AuditActor::system());

    expect(fn () => $promote([['path' => "services.{$web->public_id}.deploy.port"]]))->toThrow(DomainRuleViolation::class, 'changed since you opened')
        ->and(fn () => $promote([['path' => "services.{$redis->public_id}.variables.MAXMEMORY"]]))->toThrow(DomainRuleViolation::class, 'needs')
        ->and(fn () => $promote([['path' => "services.{$redis->public_id}", 'value' => 'x']]))->toThrow(DomainRuleViolation::class, 'only secrets')
        ->and(fn () => $promote([]))->toThrow(DomainRuleViolation::class, 'at least one')
        ->and(fn () => resolve(PromoteChanges::class)->handle($project, PromoteChangesData::from([
            'from' => $staging->public_id, 'to' => $staging->public_id, 'items' => [],
        ]), User::factory()->create(), AuditActor::system()))->toThrow(DomainRuleViolation::class, 'two different');
});

test('a row shared with other environments is split instead of changed for all of them', function (): void {
    [$project, $production, $staging, , $web] = prProject();
    $qa = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('qa'), null, AuditActor::system());
    // One row for production and qa.
    prRow($project, $qa, $web, 'QUEUE_CONNECTION')?->delete();
    $shared = prRow($project, $production, $web, 'QUEUE_CONNECTION');
    $shared?->targets()->create(['environment_id' => $qa->id, 'all_previews' => false]);
    resolve(UpdateVariable::class)->handle(prRow($project, $staging, $web, 'QUEUE_CONNECTION'), VariableUpdate::from(['value' => 'redis']), null, AuditActor::system());

    prPromote($project, $staging, $production);

    expect(prValue($project, $production, $web, 'QUEUE_CONNECTION'))->toBe('redis')
        ->and(prValue($project, $qa, $web, 'QUEUE_CONNECTION'))->toBe('database')
        ->and(prRow($project, $production, $web, 'QUEUE_CONNECTION')?->id)->not->toBe($shared?->id);
});

test('copying a secret value is explicit and blocked between production and previews under the policy', function (): void {
    [$project, $production, $staging, , $web] = prProject();
    prVar($project, $web, 'API_TOKEN', 'tok_staging', [$staging], VariableType::Secret);
    $path = "services.{$web->public_id}.variables.API_TOKEN";

    prPromote($project, $staging, $production, [$path => ['copyValue' => true]]);

    expect(prValue($project, $production, $web, 'API_TOKEN'))->toBe('tok_staging');

    $project->forceFill(['settings' => ['productionSecretsMustDiffer' => true]])->save();
    $production->refresh();
    $preview = Environment::query()->create([
        'team_id' => $project->team_id, 'project_id' => $project->id, 'name' => 'pr-7', 'slug' => 'pr-7',
        'kind' => EnvironmentKind::Preview, 'base_environment_id' => $production->id, 'network_subnet' => '10.231.77.0/24',
    ]);
    resolve(CopyEnvironmentIntent::class)->handle($production, $preview);
    prVar($project, $web, 'PREVIEW_ONLY', 'p', [$preview], VariableType::Secret);

    $item = prItems($preview->refresh(), $production->refresh())["services.{$web->public_id}.variables.PREVIEW_ONLY"];

    expect($item->canCopyValue)->toBeFalse()
        ->and($item->copyBlockedReason)->toContain('must differ')
        ->and(fn (): EnvironmentPromotion => prPromote($project, $preview, $production, ["services.{$web->public_id}.variables.PREVIEW_ONLY" => ['copyValue' => true]]))
        ->toThrow(DomainRuleViolation::class, 'must differ');
});

test('"Also promote images" pins the source commit; the target deploy builds exactly that commit and consumes the pin', function (): void {
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team, 'shop');
    $app = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'web', 'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello'],
    ]), null, AuditActor::system());
    $production = $project->production()->sole();
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());
    resolve(DeployEnvironment::class)->handle($staging, null, AuditActor::system());

    $built = Deployment::query()->where('environment_id', $staging->id)->sole();
    $built->forceFill(['commit_sha' => str_repeat('a', 40), 'image' => '127.0.0.1:5000/apps/x@sha256:'.str_repeat('b', 64), 'status' => DeploymentStatus::Active->value, 'git_ref' => 'refs/heads/main'])->save();
    prInstance($staging, $app)->forceFill(['active_deployment_id' => $built->id])->save();

    $comparison = resolve(PromotionComparison::class)->get($project, $staging, $production);

    expect($comparison->images)->toHaveCount(1)
        ->and($comparison->images[0]->deploymentId)->toBe($built->public_id)
        ->and($comparison->images[0]->sameInputs)->toBeTrue();

    $promotion = prPromote($project, $staging, $production, images: [$app->public_id]);

    expect($promotion->images[$app->public_id]['commitSha'])->toBe(str_repeat('a', 40));

    $result = resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());

    expect($result->deployments)->toHaveCount(1)
        ->and($result->deployments[0]->commit_sha)->toBe(str_repeat('a', 40))
        ->and($result->deployments[0]->trigger)->toBe(DeploymentTrigger::Promote)
        ->and($promotion->refresh()->images_applied_at)->not->toBeNull()
        ->and(fn () => resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'Nothing to deploy');
});

test('the promote page, the switcher hint and the stage endpoint with authorization', function (): void {
    [$project, $production, $staging, , $web] = prProject();
    prVar($project, $web, 'NEW_KEY', 'v', [$staging]);
    $developer = memberOf($project->team, TeamRole::Developer);
    $viewer = memberOf($project->team, TeamRole::Viewer);

    $this->actingAs($viewer)
        ->get("/p/{$project->public_id}/promote?from={$staging->public_id}&to={$production->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('projects/promote')
            ->where('comparison.from.id', $staging->public_id)
            ->where('comparison.to.id', $production->public_id)
            ->where('comparison.offered', 1)
            ->where('comparison.canPromote', false)
            ->where('comparison.items.0.path', "services.{$web->public_id}.variables.NEW_KEY"));

    // Defaults: from = a non-production environment, to = production.
    $this->actingAs($viewer)->get("/p/{$project->public_id}/promote")
        ->assertInertia(fn ($page) => $page->where('comparison.from.id', $staging->public_id)->where('comparison.to.id', $production->public_id));

    $this->actingAs($viewer)->getJson("/p/{$project->public_id}/{$staging->public_id}/-/promotion-hint")
        ->assertOk()
        ->assertJson(['count' => 1, 'to' => ['id' => $production->public_id, 'name' => 'production']]);
    $this->actingAs($viewer)->getJson("/p/{$project->public_id}/{$production->public_id}/-/promotion-hint")
        ->assertJson(['count' => 0]);

    $payload = ['from' => $staging->public_id, 'to' => $production->public_id, 'items' => [['path' => "services.{$web->public_id}.variables.NEW_KEY"]]];

    $this->actingAs($viewer)->post("/p/{$project->public_id}/promote", $payload)->assertForbidden();
    $this->actingAs($developer)->post("/p/{$project->public_id}/promote", $payload)
        ->assertRedirect("/p/{$project->public_id}/{$production->public_id}/changes");

    expect(prValue($project, $production, $web, 'NEW_KEY'))->toBe('v');

    // Another team's project is a 404 or 403, never a promotion.
    $other = Projects::create(Team::factory()->create(), 'other');
    expect($this->actingAs($developer)->post("/p/{$other->public_id}/promote", $payload)->status())->toBeIn([403, 404]);
});

test('project activity lists deploys, the copy and promotions', function (): void {
    [$project, $production, $staging, , $web] = prProject();
    prVar($project, $web, 'NEW_KEY', 'v', [$staging]);
    $this->travel(1)->minutes();
    prPromote($project, $staging, $production);

    $this->actingAs(memberOf($project->team, TeamRole::Viewer))
        ->get("/p/{$project->public_id}/activity")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('projects/activity')
            ->where('entries.0.kind', 'promotion')
            ->where('entries.0.title', 'promoted 1 change from staging to production')
            ->where('entries.0.projectId', $project->public_id)
            ->where('entries', fn ($entries): bool => collect($entries)->pluck('kind')->intersect(['created', 'deploy', 'promotion'])->unique()->count() === 3));

    // The first deploy of web and postgres is one item, grouped by revision.
    $this->actingAs(memberOf($project->team, TeamRole::Viewer))
        ->get("/p/{$project->public_id}/activity?kind=deploys")
        ->assertInertia(fn ($page) => $page
            ->has('entries', 1)
            ->where('entries.0.kind', 'deploy')
            ->where('entries.0.title', 'deployed postgres and web to production')
            ->where('filters.kind', 'deploys'));
});

test('promote and deploy stages and deploys the target in one step', function (): void {
    [$project, $production, $staging, , $web] = prProject();
    prVar($project, $web, 'NEW_KEY', 'v', [$staging]);
    $developer = memberOf($project->team, TeamRole::Developer);
    $before = Deployment::query()->where('environment_id', $production->id)->count();
    $payload = ['from' => $staging->public_id, 'to' => $production->public_id, 'deploy' => true, 'items' => [['path' => "services.{$web->public_id}.variables.NEW_KEY"]]];

    $this->actingAs($developer)
        ->get("/p/{$project->public_id}/promote?from={$staging->public_id}&to={$production->public_id}")
        ->assertInertia(fn ($page) => $page
            ->where('canDeploy', true)
            ->where('cards.from.name', 'staging')
            ->where('cards.to.name', 'production')
            ->where('cards.to.revision', 1));

    $this->actingAs(memberOf($project->team, TeamRole::Viewer))->post("/p/{$project->public_id}/promote", $payload)->assertForbidden();

    $this->actingAs($developer)->post("/p/{$project->public_id}/promote", $payload)
        ->assertRedirect("/p/{$project->public_id}/deployments?environment={$production->public_id}");

    $production->refresh();
    expect(prValue($project, $production, $web, 'NEW_KEY'))->toBe('v')
        // Deployed: nothing left staged, a new revision with the promotion's message, web redeploys.
        ->and(SpecDiff::between($production->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($production))->isEmpty())->toBeTrue()
        ->and($production->lastAppliedRevision?->message)->toBe('Promoted from staging')
        ->and(Deployment::query()->where('environment_id', $production->id)->count())->toBe($before + 1)
        ->and(EnvironmentPromotion::query()->where('to_environment_id', $production->id)->where('kind', PromotionKind::Promoted->value)->count())->toBe(1);
});

test('promote and deploy refused by the deploy leaves nothing staged', function (): void {
    [$project, $production, $staging, , $web] = prProject();
    // A reference that can't resolve in production refuses the deploy.
    prVar($project, $web, 'BROKEN', '${{missing.URL}}', [$staging]);
    $developer = memberOf($project->team, TeamRole::Developer);

    $this->actingAs($developer)->post("/p/{$project->public_id}/promote", [
        'from' => $staging->public_id, 'to' => $production->public_id, 'deploy' => true,
        'items' => [['path' => "services.{$web->public_id}.variables.BROKEN"]],
    ])->assertSessionHasErrors();

    expect(prRow($project, $production, $web, 'BROKEN'))->toBeNull()
        ->and(EnvironmentPromotion::query()->where('to_environment_id', $production->id)->where('kind', PromotionKind::Promoted->value)->exists())->toBeFalse();
});

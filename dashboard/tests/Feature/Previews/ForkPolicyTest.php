<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployCommit;
use App\Actions\Environments\DiscardChanges;
use App\Actions\Previews\ApprovePreview;
use App\Actions\Promotions\PromoteChanges;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Git\CommitData;
use App\Data\Promotions\PromoteChangesData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\Previews\ForkPolicy;
use App\Enums\Previews\PreviewStatus;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Previews\PreviewSettings;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Variable;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Promotions\PromotionPlanner;
use App\Services\Variables\VariableStore;
use Illuminate\Contracts\Database\Query\Builder;
use Tests\Support\PreviewFlow;

beforeEach(function (): void {
    PreviewFlow::setUp();
});

function fpFork(array $overrides = []): array
{
    return ['isFork' => true, 'authorLogin' => 'stranger', 'authorPermission' => 'read', ...$overrides];
}

test('fork pull requests get no preview by default', function (): void {
    [, , , $project] = PreviewFlow::project();

    $preview = PreviewFlow::event($project, overrides: fpFork());

    expect($preview->status)->toBe(PreviewStatus::Skipped)
        ->and($preview->status_reason)->toContain('forks')
        ->and($preview->environment_id)->toBeNull();
});

test('with approval: forks wait for a maintainer, authors with write access start right away', function (): void {
    [, , , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();

    $waiting = PreviewFlow::event($project, overrides: fpFork());
    $trusted = PreviewFlow::event($project, overrides: fpFork(['number' => 8, 'authorPermission' => 'write', 'headRef' => 'x']));

    expect($waiting->status)->toBe(PreviewStatus::AwaitingApproval)
        ->and($waiting->environment_id)->toBeNull()
        ->and($trusted->environment_id)->not->toBeNull()
        // Fork previews never get secrets, even from trusted authors.
        ->and($trusted->environment?->secrets_withheld)->toBeTrue();
});

test('a preview label approves only when applied by someone with write access', function (): void {
    [, , , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();
    PreviewFlow::event($project, overrides: fpFork());

    $preview = PreviewFlow::event($project, 'labeled', fpFork(['labels' => ['preview'], 'label' => 'preview', 'senderLogin' => 'stranger', 'senderPermission' => 'read']));
    expect($preview->status)->toBe(PreviewStatus::AwaitingApproval);

    $preview = PreviewFlow::event($project, 'labeled', fpFork(['labels' => ['preview'], 'label' => 'preview', 'senderLogin' => 'maintainer', 'senderPermission' => 'maintain']));
    expect($preview->environment_id)->not->toBeNull()
        ->and($preview->approved_sha)->toBe(PreviewFlow::SHA_PR_1);

    // New commits from the fork need a new approval; the approved commit keeps running.
    $preview = PreviewFlow::event($project, 'synchronize', fpFork(['headSha' => PreviewFlow::SHA_PR_2, 'labels' => ['preview']]));
    expect($preview->status)->toBe(PreviewStatus::AwaitingApproval)
        ->and($preview->environment_id)->not->toBeNull()
        ->and(Deployment::query()->where('commit_sha', PreviewFlow::SHA_PR_2)->exists())->toBeFalse();
});

test('a developer approves with the button; viewers can’t', function (): void {
    [$team, $server, , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();
    $preview = PreviewFlow::event($project, overrides: fpFork());

    $viewer = memberOf($team, TeamRole::Viewer);
    $this->actingAs($viewer)->post(route('projects.previews.approve', [$project, $preview]), ['headSha' => PreviewFlow::SHA_PR_1])->assertForbidden();

    $developer = memberOf($team, TeamRole::Developer);
    // The approval names the reviewed commit.
    $this->actingAs($developer)->post(route('projects.previews.approve', [$project, $preview]))->assertSessionHasErrors('headSha');
    $this->actingAs($developer)->post(route('projects.previews.approve', [$project, $preview]), ['headSha' => PreviewFlow::SHA_PR_1])->assertRedirect();

    expect($preview->refresh()->approved_by)->toBe($developer->id)
        ->and($preview->environment_id)->not->toBeNull();

    // Approving twice is refused.
    expect(fn () => resolve(ApprovePreview::class)->handle($preview, PreviewFlow::SHA_PR_1, $developer, AuditActor::system()))->toThrow(DomainRuleViolation::class);

    PreviewFlow::run($server);
    expect($preview->refresh()->status)->toBe(PreviewStatus::Running);
});

test('fork previews get no secrets: project secrets resolve empty, their own database credentials stay', function (): void {
    [, $server, $keypair, $project, , , $web] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();
    resolve(CreateVariable::class)->handle($project, $web, VariableInput::from([
        'key' => 'STRIPE_KEY', 'value' => 'sk_live_secret', 'type' => VariableType::Secret, 'targets' => [new VariableTargetData(allPreviews: true)],
    ]), null, AuditActor::system());
    resolve(CreateVariable::class)->handle($project, $web, VariableInput::from([
        'key' => 'FEATURE', 'value' => 'on', 'type' => VariableType::Config, 'targets' => [new VariableTargetData(allPreviews: true)],
    ]), null, AuditActor::system());

    $preview = PreviewFlow::event($project, overrides: fpFork(['authorPermission' => 'admin']));
    PreviewFlow::run($server);
    $deployment = Deployment::query()->where('environment_id', $preview->environment_id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->latest('id')->sole();
    $env = resolve(ProjectKeyring::class)->decryptSnapshot($project, $deployment->public_id, (string) $deployment->env_snapshot);

    expect($env['STRIPE_KEY'])->toBe('')
        ->and($env['FEATURE'])->toBe('on')
        ->and($env['DATABASE_URL'])->toStartWith('postgres')
        ->and($env['DATABASE_URL'])->toContain('@postgres:5432')
        ->and(parse_url($env['DATABASE_URL'], PHP_URL_PASS))->not->toBe('')
        ->and(Placement::query()->where('deployment_id', $deployment->id)->exists())->toBeTrue();

    // A non-fork PR gets the same secret.
    $own = PreviewFlow::event($project, overrides: ['number' => 9, 'headRef' => 'feature/x']);
    PreviewFlow::run($server);
    $ownDeployment = Deployment::query()->where('environment_id', $own->environment_id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->latest('id')->sole();

    expect(resolve(ProjectKeyring::class)->decryptSnapshot($project, $ownDeployment->public_id, (string) $ownDeployment->env_snapshot)['STRIPE_KEY'])->toBe('sk_live_secret');
});

test('the base’s own variables are copied into previews; fork previews get no secret copies', function (): void {
    [, , , $project, $production, , $web] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();
    foreach ([['API_TOKEN', 'tok_prod', VariableType::Secret], ['LOG_LEVEL', 'debug', VariableType::Config]] as [$key, $value, $type]) {
        resolve(CreateVariable::class)->handle($project, $web, VariableInput::from([
            'key' => $key, 'value' => $value, 'type' => $type, 'targets' => [VariableTargetData::environment($production->public_id)],
        ]), null, AuditActor::system());
    }

    $own = PreviewFlow::event($project, overrides: ['number' => 11, 'headRef' => 'feature/own']);
    $fork = PreviewFlow::event($project, overrides: fpFork(['number' => 12, 'headRef' => 'fork/x', 'authorPermission' => 'write']));
    $keys = static fn (int $environmentId): array => Variable::query()
        ->where('service_id', $web->id)
        ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $environmentId))
        ->pluck('key')->sort()->values()->all();

    expect($keys((int) $own->environment_id))->toBe(['API_TOKEN', 'LOG_LEVEL'])
        ->and($keys((int) $fork->environment_id))->toBe(['LOG_LEVEL']);

    // Closing removes the copies with the environment.
    PreviewFlow::event($project, 'closed', ['number' => 11, 'headRef' => 'feature/own']);
    expect(Variable::query()->where('service_id', $web->id)->where('key', 'API_TOKEN')->count())->toBe(1);
});

test('an approval covers only the commit the maintainer saw: a push in between refuses it', function (): void {
    [$team, , , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();
    $preview = PreviewFlow::event($project, overrides: fpFork());
    $developer = memberOf($team, TeamRole::Developer);

    // The maintainer reviewed commit 1; the fork pushes commit 2 before the click.
    PreviewFlow::event($project, 'synchronize', fpFork(['headSha' => PreviewFlow::SHA_PR_2]));

    $this->actingAs($developer)->post(route('projects.previews.approve', [$project, $preview]), ['headSha' => PreviewFlow::SHA_PR_1])->assertSessionHasErrors('general');
    expect($preview->refresh()->approved_at)->toBeNull()
        ->and($preview->status)->toBe(PreviewStatus::AwaitingApproval)
        ->and($preview->environment_id)->toBeNull();

    $this->actingAs($developer)->post(route('projects.previews.approve', [$project, $preview]), ['headSha' => PreviewFlow::SHA_PR_2])->assertRedirect();
    expect($preview->refresh()->approved_sha)->toBe(PreviewFlow::SHA_PR_2);
});

test('an author whose permission lookup fails loses automatic fork previews for that event (no sticky write access)', function (): void {
    [, $server, , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();
    $preview = PreviewFlow::event($project, overrides: fpFork(['authorPermission' => 'write']));
    PreviewFlow::run($server);
    expect($preview->refresh()->status)->toBe(PreviewStatus::Running);

    $preview = PreviewFlow::event($project, 'synchronize', fpFork(['headSha' => PreviewFlow::SHA_PR_2, 'authorPermission' => 'unknown']));

    expect($preview->author_permission)->toBe('unknown')
        ->and($preview->status)->toBe(PreviewStatus::AwaitingApproval)
        ->and(Deployment::query()->where('commit_sha', PreviewFlow::SHA_PR_2)->exists())->toBeFalse();
});

test('promotion into a fork preview never copies a secret; a value has to be entered (docs/09 R20)', function (): void {
    [, $server, , $project, $production, , $web] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();
    resolve(CreateVariable::class)->handle($project, $web, VariableInput::from([
        'key' => 'API_TOKEN', 'value' => 'tok_prod', 'type' => VariableType::Secret, 'targets' => [VariableTargetData::environment($production->public_id)],
    ]), null, AuditActor::system());
    $preview = PreviewFlow::event($project, overrides: fpFork(['authorPermission' => 'write']));
    $fork = $preview->environment;
    // Production changes after the preview was copied from it.
    resolve(CreateVariable::class)->handle($project, $web, VariableInput::from([
        'key' => 'API_TOKEN_2', 'value' => 'tok_prod_2', 'type' => VariableType::Secret, 'targets' => [VariableTargetData::environment($production->public_id)],
    ]), null, AuditActor::system());
    $path = "services.{$web->public_id}.variables.API_TOKEN_2";
    $plan = resolve(PromotionPlanner::class)->plan($production->refresh(), $fork);
    $item = collect($plan->items)->firstWhere('path', $path);

    expect($item?->canCopyValue)->toBeFalse()
        ->and($item?->copyBlockedReason)->toContain('forks');

    $promote = static fn (array $input): mixed => resolve(PromoteChanges::class)->handle($project, PromoteChangesData::from([
        'from' => $production->public_id, 'to' => $fork->public_id, 'items' => [['path' => $path, ...$input]], 'images' => [],
    ]), User::factory()->create(), AuditActor::system());

    expect(fn (): mixed => $promote(['copyValue' => true]))->toThrow(DomainRuleViolation::class)
        ->and(fn (): mixed => $promote([]))->toThrow(DomainRuleViolation::class);

    $promote(['value' => 'tok_for_the_fork']);
    $row = Variable::query()->where('service_id', $web->id)->where('key', 'API_TOKEN_2')->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $fork->id))->sole();
    expect(resolve(VariableStore::class)->plaintext($project, $row))->toBe('tok_for_the_fork')
        ->and($server->id)->toBeInt();
});

test('discard and revert never recreate a withheld secret as a row of a fork preview', function (): void {
    [, $server, , $project, , , $web] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();
    $secret = resolve(CreateVariable::class)->handle($project, $web, VariableInput::from([
        'key' => 'STRIPE_KEY', 'value' => 'sk_live_secret', 'type' => VariableType::Secret, 'targets' => [new VariableTargetData(allPreviews: true)],
    ]), null, AuditActor::system());
    $preview = PreviewFlow::event($project, overrides: fpFork(['authorPermission' => 'write']));
    PreviewFlow::run($server);
    $fork = $preview->refresh()->environment;
    $revision = $fork->lastAppliedRevision;
    expect(collect($revision->spec->service($web->public_id)?->variables)->firstWhere('key', 'STRIPE_KEY')?->withheld)->toBeTrue();

    $secret->delete();
    resolve(DiscardChanges::class)->handle($fork, null, AuditActor::system());
    expect(Variable::query()->where('key', 'STRIPE_KEY')->exists())->toBeFalse();

    resolve(DiscardChanges::class)->handle($fork, null, AuditActor::system(), $revision);
    expect(Variable::query()->where('key', 'STRIPE_KEY')->exists())->toBeFalse();
});

test('"production secrets must differ" blocks preview and push deploys, not only Deploy (M6)', function (): void {
    [, $server, , $project, $production, , $web] = PreviewFlow::project();
    $project->forceFill(['settings' => ['productionSecretsMustDiffer' => true]])->save();
    // A preview row with production's value (e.g. planted before the policy was on).
    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);
    $environment = $preview->refresh()->environment;
    foreach ([$production, $environment] as $target) {
        $row = new Variable;
        $row->forceFill(['team_id' => $project->team_id, 'project_id' => $project->id, 'service_id' => $web->id, 'key' => 'API_TOKEN', 'type' => VariableType::Secret, 'version' => 1, 'value' => 'x'])->save();
        $row->forceFill(['value' => resolve(ProjectKeyring::class)->encryptVariable($project, $row->public_id, 1, 'tok_same')])->save();
        $row->targets()->create(['environment_id' => $target->id, 'all_previews' => false]);
    }

    // A new commit on the PR: the preview fails with the policy's message.
    $preview = PreviewFlow::event($project, 'synchronize', ['headSha' => PreviewFlow::SHA_PR_2]);
    PreviewFlow::run($server);
    expect($preview->refresh()->status)->toBe(PreviewStatus::Failed)
        ->and($preview->status_reason)->toContain('must differ')
        ->and(Deployment::query()->where('commit_sha', PreviewFlow::SHA_PR_2)->exists())->toBeFalse();

    // A push to production is not deployed either.
    $instance = ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $web->id)->sole();
    expect(resolve(DeployCommit::class)->handle($instance, new CommitData(str_repeat('d', 40), 'push', 'dev', null, 'dev'), 'refs/heads/main'))->toBeNull();
});

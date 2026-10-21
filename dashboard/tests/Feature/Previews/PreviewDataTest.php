<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Previews\AdvancePreviews;
use App\Actions\Previews\UpdatePreviewStrategy;
use App\Data\Audit\AuditActor;
use App\Data\Previews\PreviewStrategyInput;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\DeploymentStatus;
use App\Enums\Previews\ForkPolicy;
use App\Enums\Previews\PreviewStatus;
use App\Enums\PreviewStrategy;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Volume;
use App\Queries\Previews\PreviewCanvas;
use App\Queries\Previews\PreviewList;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Previews\PreviewDatabases;
use App\Services\Previews\PreviewRoutes;
use App\Services\Previews\PreviewUrls;
use App\Services\Variables\SpecVariableResolver;
use Illuminate\Contracts\Database\Query\Builder;
use Tests\Support\Backups;
use Tests\Support\PreviewFlow;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

beforeEach(function (): void {
    PreviewFlow::setUp();
    Backups::fakeEnvironment();
});

/**
 * A successful backup run of $instance with $sizeBytes (no daemon needed).
 */
function pdBackup(Team $team, ServiceInstance $instance, int $sizeBytes): BackupRun
{
    $destination = Backups::destination($team, memberOf($team, TeamRole::Owner), 'Primary '.$instance->id);
    $run = new BackupRun([
        'team_id' => $team->id,
        'destination_id' => $destination->id,
        'subject' => 'database',
        'service_instance_id' => $instance->id,
        'reason' => BackupReason::Manual,
    ]);
    $run->status = BackupRunStatus::Succeeded;
    $run->forceFill(['snapshot_id' => str_repeat('c', 64), 'size_bytes' => $sizeBytes, 'finished_at' => now()])->save();

    return $run;
}

function pdInstance(Project $project, string $slug): ServiceInstance
{
    return ServiceInstance::query()
        ->where('environment_id', $project->production()->sole()->id)
        ->whereHas('service', static fn (Builder $query) => $query->where('slug', $slug))
        ->with('service')
        ->sole();
}

test('clone sources: the picker shows backup sizes; the size guard and the production toggle refuse sources', function (): void {
    [$team, , , $project] = PreviewFlow::project();
    $target = pdInstance($project, 'postgres');
    $replica = Projects::postgres($project, 'analytics');
    $source = pdInstance($project, 'analytics');
    pdBackup($team, $source, 600 * 1024 * 1024);
    $settings = PreviewSettings::forProject($project);

    $candidates = resolve(PreviewDatabases::class)->candidates($target, $settings);
    $byId = collect($candidates)->keyBy(static fn (array $candidate): int => $candidate['instance']->id);
    expect($candidates)->toHaveCount(2)
        ->and($byId[$source->id]['sizeBytes'])->toBe(600 * 1024 * 1024)
        ->and($byId[$source->id]['allowed'])->toBeFalse()
        ->and($byId[$source->id]['reason'])->toContain('production')
        ->and($byId[$target->id]['allowed'])->toBeFalse();

    $input = new PreviewStrategyInput(PreviewStrategy::Clone, $source->public_id, ['psql', '-c', 'UPDATE users SET email = id || \'@example.com\'']);
    expect(fn () => resolve(UpdatePreviewStrategy::class)->handle($target, $input, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'production');

    $settings->forceFill(['allow_production_clone' => true])->save();
    $team->forceFill(['settings' => [...$team->settings->toArray(), 'previewCloneLimitMb' => 500]])->save();
    expect(fn () => resolve(UpdatePreviewStrategy::class)->handle($target, $input, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'limit for preview clones is 500 MB');

    $team->forceFill(['settings' => [...$team->settings->toArray(), 'previewCloneLimitMb' => null]])->save();
    $updated = resolve(UpdatePreviewStrategy::class)->handle($target, $input, AuditActor::system());

    expect($updated->preview_strategy)->toBe(PreviewStrategy::Clone)
        ->and($updated->preview_clone_source_id)->toBe($source->id)
        ->and($updated->preview_sanitize_command)->toBe(['psql', '-c', 'UPDATE users SET email = id || \'@example.com\''])
        ->and($replica->kind->isStateful())->toBeTrue();

    // A database without a backup can't be a source.
    $other = Projects::postgres($project, 'cache-db');
    expect(fn () => resolve(UpdatePreviewStrategy::class)->handle($target, new PreviewStrategyInput(PreviewStrategy::Clone, pdInstance($project, 'cache-db')->public_id), AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'no successful backup')
        ->and($other->id)->toBeInt();
});

test('clone: the preview database is restored from the source’s latest backup and sanitized before the apps deploy', function (): void {
    [$team, $server, $keypair, $project, , , $web] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['allow_production_clone' => true])->save();
    $postgres = pdInstance($project, 'postgres');
    $backup = pdBackup($team, $postgres, 1024);
    resolve(UpdatePreviewStrategy::class)->handle($postgres, new PreviewStrategyInput(PreviewStrategy::Clone, $postgres->public_id, ['psql', '-c', 'DELETE FROM sessions']), AuditActor::system());

    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);
    $preview->refresh();
    $restore = RestoreRun::query()->sole();
    $previewDb = ServiceInstance::query()->where('environment_id', $preview->environment_id)->where('service_id', $postgres->service_id)->sole();

    expect($preview->status)->toBe(PreviewStatus::Preparing)
        ->and($preview->data['databases'][$postgres->service->public_id]['state'])->toBe('restoring')
        ->and($restore->source_service_instance_id)->toBe($postgres->id)
        ->and($restore->target_service_instance_id)->toBe($previewDb->id)
        ->and($restore->snapshot_id)->toBe($backup->snapshot_id)
        ->and($restore->destination_id)->toBe($backup->destination_id)
        // The apps wait.
        ->and(Deployment::query()->where('environment_id', $preview->environment_id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->exists())->toBeFalse();

    // The backup machinery restores into the running preview database.
    $restore->transitionTo(RestoreRunStatus::Restoring);
    $restore->transitionTo(RestoreRunStatus::Succeeded);
    resolve(AdvancePreviews::class)->handle();

    $sanitize = DaemonTask::query()->where('purpose', 'sanitize')->sole();
    $spec = json_decode(json_encode($sanitize->spec, JSON_THROW_ON_ERROR), true);
    $dbDeployment = Deployment::query()->whereKey($previewDb->active_deployment_id)->sole();

    expect($preview->refresh()->data['databases'][$postgres->service->public_id]['state'])->toBe('sanitizing')
        ->and($sanitize->server_id)->toBe($server->id)
        ->and($spec['image'])->toBe($dbDeployment->image)
        ->and([...($spec['command'] ?? []), ...($spec['args'] ?? [])])->toBe(['psql', '-c', 'DELETE FROM sessions'])
        ->and($spec['network']['id'])->toBe($preview->environment?->networkId());

    $leased = resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['run_once']]));
    expect($leased)->toHaveCount(1);
    $env = Projects::openEnv($leased[0]->spec->env->sealed, $keypair);
    expect($env['POSTGRES_PASSWORD'] ?? null)->toBeString();

    resolve(CompleteTask::class)->handle($server, $sanitize->refresh(), TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => 1, 'outcome' => 'succeeded', 'runOnce' => ['exitCode' => 0, 'durationMs' => 10],
    ]));
    resolve(AdvancePreviews::class)->handle();
    PreviewFlow::run($server);

    expect($preview->refresh()->status)->toBe(PreviewStatus::Running)
        ->and($preview->data['databases'][$postgres->service->public_id]['state'])->toBe('ready')
        ->and(Deployment::query()->where('environment_id', $preview->environment_id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->latest('id')->first()?->status)->toBe(DeploymentStatus::Active);
});

test('a failed clone fails the preview with the reason; the apps never start', function (): void {
    [$team, $server, , $project, , , $web] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['allow_production_clone' => true])->save();
    $postgres = pdInstance($project, 'postgres');
    pdBackup($team, $postgres, 1024);
    resolve(UpdatePreviewStrategy::class)->handle($postgres, new PreviewStrategyInput(PreviewStrategy::Clone, $postgres->public_id), AuditActor::system());

    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);
    RestoreRun::query()->sole()->transitionTo(RestoreRunStatus::Failed, 'restic: wrong password');
    resolve(AdvancePreviews::class)->handle();

    expect($preview->refresh()->status)->toBe(PreviewStatus::Failed)
        ->and($preview->status_reason)->toContain('wrong password')
        ->and(Deployment::query()->where('environment_id', $preview->environment_id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->exists())->toBeFalse();
});

test('share base instance: no database of its own, references resolve to the base, and the app joins the base network', function (): void {
    [, $server, , $project, $production, , $web] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['allow_production_clone' => true])->save();
    $postgres = pdInstance($project, 'postgres');
    resolve(UpdatePreviewStrategy::class)->handle($postgres, new PreviewStrategyInput(PreviewStrategy::Share), AuditActor::system());

    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);
    $previewDb = ServiceInstance::query()->where('environment_id', $preview->environment_id)->where('service_id', $postgres->service_id)->sole();
    $app = Deployment::query()->where('environment_id', $preview->environment_id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->latest('id')->sole();
    $base = Deployment::query()->where('environment_id', $production->id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->latest('id')->sole();
    $keyring = resolve(ProjectKeyring::class);

    expect($previewDb->enabled)->toBeFalse()
        ->and(Volume::query()->where('service_instance_id', $previewDb->id)->exists())->toBeFalse()
        ->and(Deployment::query()->where('service_instance_id', $previewDb->id)->exists())->toBeFalse()
        ->and($preview->refresh()->status)->toBe(PreviewStatus::Running)
        ->and($preview->environment?->lastAppliedRevision?->spec->sharedServices)->toBe([$postgres->service->public_id => $production->public_id])
        ->and($keyring->decryptSnapshot($project, $app->public_id, (string) $app->env_snapshot)['DATABASE_URL'])
        ->toBe($keyring->decryptSnapshot($project, $base->public_id, (string) $base->env_snapshot)['DATABASE_URL']);

    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('id', Placement::query()->where('deployment_id', $app->id)->sole()->workload_id);
    $networks = collect(Projects::nodeState($server)['networks'])->pluck('id')->all();

    expect($workload['networks'])->toBe([
        ['network' => $preview->environment?->networkId(), 'aliases' => ['web']],
        ['network' => $production->networkId(), 'aliases' => []],
    ])->and($networks)->toContain($production->networkId())
        // Each network is declared once (the daemon rejects duplicates).
        ->and($networks)->toBe(array_values(array_unique($networks)))
        ->and(ProtocolSchemas::errors('node-state', Projects::latestDocument($server)))->toBe([]);
});

test('closing a preview while its clone restores waits and retries on the next tick', function (): void {
    [$team, $server, , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['allow_production_clone' => true])->save();
    $postgres = pdInstance($project, 'postgres');
    pdBackup($team, $postgres, 1024);
    resolve(UpdatePreviewStrategy::class)->handle($postgres, new PreviewStrategyInput(PreviewStrategy::Clone, $postgres->public_id), AuditActor::system());
    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);
    $restore = RestoreRun::query()->sole();
    $restore->transitionTo(RestoreRunStatus::Restoring);

    PreviewFlow::event($project, 'closed');
    expect($preview->refresh()->environment_id)->not->toBeNull()
        ->and($preview->status_reason)->toContain('waits')
        ->and($preview->data['pendingClose']['to'] ?? null)->toBe('closed');

    $restore->transitionTo(RestoreRunStatus::Succeeded);
    resolve(AdvancePreviews::class)->handle();

    expect($preview->refresh()->status)->toBe(PreviewStatus::Closed)
        ->and($preview->environment_id)->toBeNull();
});

test('fork previews never share the base database: a new empty one, no base credentials, no base network (docs/09 R20)', function (): void {
    [, $server, , $project, $production, , $web] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval, 'allow_production_clone' => true])->save();
    $postgres = pdInstance($project, 'postgres');
    resolve(UpdatePreviewStrategy::class)->handle($postgres, new PreviewStrategyInput(PreviewStrategy::Share), AuditActor::system());

    $preview = PreviewFlow::event($project, overrides: ['isFork' => true, 'authorLogin' => 'stranger', 'authorPermission' => 'write']);
    PreviewFlow::run($server);
    $previewDb = ServiceInstance::query()->where('environment_id', $preview->environment_id)->where('service_id', $postgres->service_id)->sole();
    $app = Deployment::query()->where('environment_id', $preview->environment_id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->latest('id')->sole();
    $base = Deployment::query()->where('environment_id', $production->id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->latest('id')->sole();
    $keyring = resolve(ProjectKeyring::class);
    $forkUrl = $keyring->decryptSnapshot($project, $app->public_id, (string) $app->env_snapshot)['DATABASE_URL'];
    $baseUrl = $keyring->decryptSnapshot($project, $base->public_id, (string) $base->env_snapshot)['DATABASE_URL'];

    expect($preview->refresh()->status)->toBe(PreviewStatus::Running)
        ->and($previewDb->enabled)->toBeTrue()
        ->and($previewDb->preview_strategy)->toBe(PreviewStrategy::NewEmpty)
        ->and(Deployment::query()->where('service_instance_id', $previewDb->id)->exists())->toBeTrue()
        ->and($preview->environment?->lastAppliedRevision?->spec->sharedServices)->toBe([])
        ->and($preview->data['databases'][$postgres->service->public_id]['downgradedFrom'])->toBe('share')
        ->and($forkUrl)->not->toBe($baseUrl)
        ->and($forkUrl)->not->toContain((string) parse_url($baseUrl, PHP_URL_PASS));

    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('id', Placement::query()->where('deployment_id', $app->id)->sole()->workload_id);
    expect($workload['networks'])->toBe([['network' => $preview->environment?->networkId(), 'aliases' => ['web']]]);

    // The previews list and the canvas say why.
    $list = resolve(PreviewList::class)->for($project, false);
    $database = collect($list)->firstWhere('number', $preview->pr_number)?->databases[0];
    expect($database?->downgradedFrom)->toBe(PreviewStrategy::Share)
        ->and(collect(resolve(PreviewCanvas::class)->for($preview->environment)?->edgeBadges)->pluck('label')->all())->toBe(['new and empty (fork: no base data)']);
});

test('a revision that shares a base database never resolves base credentials or joins the base network once secrets are withheld', function (): void {
    [, $server, , $project, $production] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['allow_production_clone' => true])->save();
    $postgres = pdInstance($project, 'postgres');
    resolve(UpdatePreviewStrategy::class)->handle($postgres, new PreviewStrategyInput(PreviewStrategy::Share), AuditActor::system());
    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);
    $environment = $preview->refresh()->environment;
    $spec = $environment?->lastAppliedRevision?->spec;
    expect($spec?->sharedServices)->toBe([$postgres->service->public_id => $production->public_id]);

    $environment?->forceFill(['secrets_withheld' => true])->save();
    $scopes = resolve(SpecVariableResolver::class)->annotatedScopes($project, $spec);
    $base = resolve(SpecVariableResolver::class)->annotatedScopes($project, $production->refresh()->lastAppliedRevision->spec);

    expect($scopes['postgres']['POSTGRES_PASSWORD']['value'] ?? null)->not->toBe($base['postgres']['POSTGRES_PASSWORD']['value'])
        ->and(new PreviewRoutes(resolve(PreviewUrls::class), $server)->sharedNetworks($spec))->toBe([]);
});

test('fork previews never receive a clone: the database starts new and empty, no restore runs', function (): void {
    [$team, $server, , $project, , , $web] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['allow_production_clone' => true, 'fork_policy' => ForkPolicy::Approval])->save();
    $postgres = pdInstance($project, 'postgres');
    pdBackup($team, $postgres, 1024);
    resolve(UpdatePreviewStrategy::class)->handle($postgres, new PreviewStrategyInput(PreviewStrategy::Clone, $postgres->public_id, ['psql', '-c', 'DELETE FROM sessions']), AuditActor::system());

    $preview = PreviewFlow::event($project, overrides: ['isFork' => true, 'authorLogin' => 'stranger', 'authorPermission' => 'write']);
    PreviewFlow::run($server);
    $previewDb = ServiceInstance::query()->where('environment_id', $preview->environment_id)->where('service_id', $postgres->service_id)->sole();

    expect(RestoreRun::query()->exists())->toBeFalse()
        ->and(DaemonTask::query()->where('purpose', 'sanitize')->exists())->toBeFalse()
        ->and($previewDb->preview_strategy)->toBe(PreviewStrategy::NewEmpty)
        ->and($previewDb->preview_clone_source_id)->toBeNull()
        ->and($preview->refresh()->status)->toBe(PreviewStatus::Running)
        ->and($preview->data['databases'][$postgres->service->public_id]['downgradedFrom'])->toBe('clone')
        ->and(Deployment::query()->where('environment_id', $preview->environment_id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->latest('id')->first()?->status)->toBe(DeploymentStatus::Active);
});

test('sharing a production database with previews needs the owner/admin production toggle, also when the preview is created', function (): void {
    [, $server, , $project, $production] = PreviewFlow::project();
    $postgres = pdInstance($project, 'postgres');

    expect(fn () => resolve(UpdatePreviewStrategy::class)->handle($postgres, new PreviewStrategyInput(PreviewStrategy::Share), AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'Allow production data in previews');

    $settings = PreviewSettings::forProject($project);
    $settings->forceFill(['allow_production_clone' => true])->save();
    resolve(UpdatePreviewStrategy::class)->handle($postgres, new PreviewStrategyInput(PreviewStrategy::Share), AuditActor::system());
    // Turned off again before the pull request opens.
    $settings->forceFill(['allow_production_clone' => false])->save();

    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);
    $previewDb = ServiceInstance::query()->where('environment_id', $preview->environment_id)->where('service_id', $postgres->service_id)->sole();

    expect($previewDb->enabled)->toBeTrue()
        ->and($previewDb->preview_strategy)->toBe(PreviewStrategy::NewEmpty)
        ->and($preview->refresh()->environment?->lastAppliedRevision?->spec->sharedServices)->toBe([])
        ->and($preview->data['databases'][$postgres->service->public_id]['downgradeReason'])->toBe('production')
        ->and($production->kind->value)->toBe('production');
});

<?php

declare(strict_types=1);

use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Transfers\AdvanceCopies;
use App\Actions\Transfers\AdvanceImports;
use App\Data\Activity\ActivityFiltersData;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Services\DatabaseSource;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DatabaseEngine;
use App\Enums\TeamRole;
use App\Enums\Transfers\CopyRunStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\Environment;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Transfers\DataCopyRun;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Queries\TeamActivity;
use App\Services\Transfers\CopyRules;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\Transfers;
use Tests\Support\VolumeServices;
use Tests\TestCase;

/*
| Copy data between environments (D110, docs/14 §14): an export of the
| source chained into an import of the target, with the permission matrix,
| typed confirmation, cancel and failure states.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    $this->store = Transfers::useTempStore();
});

/**
 * Production's postgres and the same service in a deployed environment $name.
 *
 * @return array{Team, Server, ServiceInstance, ServiceInstance}
 */
function copyPair(string $name = 'staging'): array
{
    [$team, $server, , $production] = Backups::runningPostgres();
    $server->forceFill(['daemon_features' => [DaemonFeature::DataTransfer->value]])->save();
    $production->load('environment.project');
    $staging = resolve(CreateEnvironment::class)->handle($production->environment->project, new CreateEnvironmentData($name), null, AuditActor::system());
    resolve(DeployEnvironment::class)->handle($staging, null, AuditActor::system());
    Projects::report($server);
    Projects::report($server);

    $target = ServiceInstance::query()->where('service_id', $production->service_id)->where('environment_id', $staging->id)->sole();

    return [$team, $server->refresh(), $production, $target];
}

/**
 * Lets launchroomd upload and finish the export of the copy.
 */
function exportCopy(TestCase $test, Server $server, string $bytes): void
{
    $task = VolumeServices::lease($server, DaemonTaskType::DbExport);
    $transfer = DataTransfer::query()->whereKey(DataExportRun::query()->latest('id')->firstOrFail()->transfer_id)->sole();

    foreach (str_split($bytes, Transfers::CHUNK) as $index => $chunk) {
        Transfers::daemon($server)->send($test, 'PUT', "/daemon/v1/transfers/{$transfer->public_id}/chunks/{$index}", $chunk, ['Content-Type' => 'application/octet-stream'])->assertNoContent();
    }

    VolumeServices::finish($server, $task, 'succeeded', ['dataExport' => ['format' => 'postgres_custom', 'sizeBytes' => strlen($bytes), 'chunks' => count(str_split($bytes, Transfers::CHUNK)), 'sha256' => Transfers::digest($bytes), 'durationMs' => 900]]);
    resolve(AdvanceCopies::class)->handle();
}

test('production is copied into staging: export, safety snapshot, hold, import, start again', function (): void {
    [$team, $server, , $staging] = copyPair();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    $production = Environment::query()->where('kind', 'production')->where('project_id', $staging->environment->project_id)->sole();

    $this->actingAs($owner)->getJson(route('transfers.services.copies.options', $staging))
        ->assertOk()
        ->assertJsonPath('environmentName', 'staging')
        ->assertJsonPath('refusal', null)
        ->assertJsonPath('sources.0.environmentName', 'production')
        ->assertJsonPath('sources.0.refusal', null)
        ->assertJsonPath('sources.0.versionLabel', 'PostgreSQL 18');

    // Without an inspection that found it empty, the environment's name is typed.
    $this->postJson(route('transfers.services.copies.store', $staging), ['source' => $production->public_id, 'point' => 'live'])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Type staging'));
    $this->postJson(route('transfers.services.copies.store', $staging), ['source' => $production->public_id, 'point' => 'latest_backup', 'confirmation' => 'staging'])->assertStatus(422);

    $this->postJson(route('transfers.services.copies.store', $staging), ['source' => $production->public_id, 'point' => 'live', 'confirmation' => 'staging'])
        ->assertSuccessful()
        ->assertJsonPath('copies.0.status', 'exporting')
        ->assertJsonPath('copies.0.steps.0.state', 'current')
        ->assertJsonPath('copies.0.steps.0.label', 'Copy the data from production')
        ->assertJsonPath('copies.0.cancellable', true)
        ->assertJsonPath('exports', []);
    $copy = DataCopyRun::query()->sole();

    $this->postJson(route('transfers.services.copies.store', $staging), ['source' => $production->public_id, 'point' => 'live', 'confirmation' => 'staging'])->assertStatus(409);

    exportCopy($this, $server, $bytes = Transfers::pgCustom('18.1', '18.1', Transfers::CHUNK + 50));
    $import = DataImportRun::query()->sole();

    expect($copy->refresh()->status)->toBe(CopyRunStatus::Importing)
        ->and($copy->size_bytes)->toBe(strlen($bytes))
        ->and($import->service_instance_id)->toBe($staging->id)
        ->and($import->replace)->toBeTrue()
        ->and($import->source_label)->toBe('Copied from production')
        ->and($import->status)->toBe(ImportRunStatus::WaitingForSafetyBackup);

    VolumeServices::backedUp($server, str_repeat('ef', 32));
    resolve(AdvanceImports::class)->handle();
    expect($import->refresh()->status)->toBe(ImportRunStatus::Importing);

    $task = VolumeServices::lease($server, DaemonTaskType::DbImport);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['source']['file']['id'])->toBe(DataTransfer::query()->whereKey($import->transfer_id)->value('public_id'));
    // The file is the export of production's postgres.
    Transfers::daemon($server)->send($this, 'GET', "/daemon/v1/transfers/{$spec['source']['file']['id']}/chunks/0")->assertOk();

    $this->postJson(route('transfers.services.copies.cancel', [$staging, $copy->public_id]))->assertStatus(409);

    VolumeServices::finish($server, $task, 'succeeded', ['dataImport' => ['mode' => 'import', 'empty' => false, 'tables' => 9, 'sizeBytes' => 4096, 'serverVersion' => '18.1', 'format' => 'postgres_custom', 'durationMs' => 3000]]);
    resolve(AdvanceCopies::class)->handle();

    expect($copy->refresh()->status)->toBe(CopyRunStatus::Succeeded)
        ->and(AuditEvent::query()->where('action', 'data.copy_started')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'data.copy_finished')->value('meta'))->toMatchArray(['outcome' => 'succeeded']);

    $this->getJson(route('transfers.services.show', $staging))
        ->assertJsonPath('copies.0.status', 'succeeded')
        ->assertJsonPath('copies.0.steps.2.detail', '9 tables, 4.1 KB')
        ->assertJsonPath('imports', []);

    $entries = resolve(TeamActivity::class)->get($team->id, new ActivityFiltersData);
    $entry = collect($entries)->firstWhere('kind', 'copy');
    expect($entry?->title)->toBe('copied postgres from production to staging')
        ->and($entry?->status)->toBe('live');
});

test('an empty target needs no typed name; production as the target needs the extra confirmation', function (): void {
    [$team, $server, $production, $staging] = copyPair();
    $owner = memberOf($team, TeamRole::Owner);
    $staging->load('environment');

    $inspection = $this->actingAs($owner)->postJson(route('transfers.services.copies.inspect', $staging), ['source' => Environment::query()->whereKey($production->environment_id)->value('public_id')])
        ->assertSuccessful()
        ->assertJsonPath('target.status', 'pending')
        ->assertJsonPath('source.status', 'pending');
    $tasks = DaemonTask::query()->where('type', DaemonTaskType::DbImport->value)->orderBy('id')->get();
    expect($tasks)->toHaveCount(2);

    $leased = resolve(LeaseTasks::class)->handle($server, LeaseRequest::validateAndCreate(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 8, 'types' => ['db_import']]));
    expect($leased)->toHaveCount(2);

    foreach (DaemonTask::query()->where('type', DaemonTaskType::DbImport->value)->get() as $task) {
        $empty = $task->public_id === $inspection->json('target.id');
        VolumeServices::finish($server, $task, 'succeeded', ['dataImport' => ['mode' => 'inspect', 'empty' => $empty, 'tables' => $empty ? 0 : 12, 'sizeBytes' => $empty ? 0 : 52_000_000, 'serverVersion' => '18.1', 'durationMs' => 50]]);
    }

    $this->getJson(route('transfers.services.copies.inspection', [$staging, $inspection->json('target.id')]))->assertJsonPath('empty', true);
    $this->getJson(route('transfers.services.copies.inspection', [$staging, $inspection->json('source.id')]).'?source='.Environment::query()->whereKey($production->environment_id)->value('public_id'))
        ->assertJsonPath('sizeBytes', 52_000_000);

    $this->postJson(route('transfers.services.copies.store', $staging), ['source' => Environment::query()->whereKey($production->environment_id)->value('public_id'), 'point' => 'live'])->assertSuccessful();

    // Into production: an explicit confirmation and the typed name, even when empty.
    $into = ['source' => $staging->environment->public_id, 'point' => 'live'];
    $this->postJson(route('transfers.services.copies.store', $production), [...$into, 'confirmation' => 'production'])->assertStatus(422)->assertJsonValidationErrors('replaceProduction');
    $this->postJson(route('transfers.services.copies.store', $production), [...$into, 'replaceProduction' => true])->assertStatus(422)->assertJsonValidationErrors('confirmation');
    $this->postJson(route('transfers.services.copies.store', $production), [...$into, 'replaceProduction' => true, 'confirmation' => 'production'])->assertSuccessful();
});

test('who may copy: production in or out needs owners or admins, other environments developers', function (): void {
    [$team, , $production, $staging] = copyPair();
    $project = $staging->environment()->firstOrFail()->project()->firstOrFail();
    $qa = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('qa'), null, AuditActor::system());
    $qaPostgres = ServiceInstance::query()->where('service_id', $staging->service_id)->where('environment_id', $qa->id)->sole();
    $productionId = Environment::query()->whereKey($production->environment_id)->value('public_id');
    $stagingId = Environment::query()->whereKey($staging->environment_id)->value('public_id');

    $developer = memberOf($team, TeamRole::Developer);
    $this->actingAs($developer)->getJson(route('transfers.services.copies.options', $qaPostgres))
        ->assertOk()
        ->assertJsonPath('sources.0.environmentName', 'production')
        ->assertJsonPath('sources.0.refusal', 'Copying production data needs an owner or admin.')
        ->assertJsonPath('sources.1.environmentName', 'staging')
        ->assertJsonPath('sources.1.refusal', null);
    $this->postJson(route('transfers.services.copies.store', $staging), ['source' => $productionId, 'point' => 'live', 'confirmation' => 'staging'])->assertForbidden();
    $this->getJson(route('transfers.services.copies.options', $production))->assertForbidden();
    // Staging into qa is a developer's.
    $this->postJson(route('transfers.services.copies.store', $qaPostgres), ['source' => $stagingId, 'point' => 'live', 'confirmation' => 'qa'])->assertSuccessful();

    $this->actingAs(memberOf($team, TeamRole::Viewer))->getJson(route('transfers.services.copies.options', $qaPostgres))->assertForbidden();
    $this->actingAs(memberOf($team, TeamRole::Admin))->getJson(route('transfers.services.copies.options', $production))->assertOk();
    $this->actingAs(memberOf(Team::factory()->create(), TeamRole::Owner))->getJson(route('transfers.services.copies.options', $staging))->assertNotFound();
});

test('a failed export leaves the target unchanged; a failed import offers the safety snapshot', function (): void {
    [$team, $server, $production, $staging] = copyPair();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    $body = ['source' => Environment::query()->whereKey($production->environment_id)->value('public_id'), 'point' => 'live', 'confirmation' => 'staging'];

    $this->actingAs($owner)->postJson(route('transfers.services.copies.store', $staging), $body)->assertSuccessful();
    VolumeServices::finish($server, VolumeServices::lease($server, DaemonTaskType::DbExport), 'failed', ['error' => 'pg_dump failed: connection refused']);
    resolve(AdvanceCopies::class)->handle();
    $first = DataCopyRun::query()->sole();
    expect($first->status)->toBe(CopyRunStatus::Failed)
        ->and($first->error)->toBe('Copying the data out of production failed: pg_dump failed: connection refused. staging is unchanged.')
        ->and(DataImportRun::query()->count())->toBe(0);

    $this->postJson(route('transfers.services.copies.store', $staging), $body)->assertSuccessful();
    exportCopy($this, $server, Transfers::pgCustom('18.1', '18.1', 100));
    VolumeServices::backedUp($server, $safety = str_repeat('12', 32));
    resolve(AdvanceImports::class)->handle();
    VolumeServices::finish($server, VolumeServices::lease($server, DaemonTaskType::DbImport), 'failed', ['error' => 'pg_restore failed: relation "events" already exists']);
    resolve(AdvanceCopies::class)->handle();

    $this->getJson(route('transfers.services.show', $staging))
        ->assertJsonPath('copies.0.status', 'failed')
        ->assertJsonPath('copies.0.safetySnapshot', $safety)
        ->assertJsonPath('copies.0.steps.1.state', 'done')
        ->assertJsonPath('copies.0.steps.2.key', 'import')
        ->assertJsonPath('copies.0.steps.2.state', 'failed');
});

test('a copy is cancelled while exporting or while the apps stop, never once the data loads', function (): void {
    [$team, $server, $production, $staging] = copyPair();
    $owner = memberOf($team, TeamRole::Owner);
    $body = ['source' => Environment::query()->whereKey($production->environment_id)->value('public_id'), 'point' => 'live', 'confirmation' => 'staging'];

    $this->actingAs($owner)->postJson(route('transfers.services.copies.store', $staging), $body)->assertSuccessful();
    $copy = DataCopyRun::query()->sole();
    $exportTask = DaemonTask::query()->where('type', DaemonTaskType::DbExport->value)->sole();

    $this->actingAs(memberOf($team, TeamRole::Developer))->postJson(route('transfers.services.copies.cancel', [$staging, $copy->public_id]))->assertForbidden();
    $this->actingAs($owner)->postJson(route('transfers.services.copies.cancel', [$staging, $copy->public_id]))
        ->assertSuccessful()
        ->assertJsonPath('copies.0.status', 'cancelled')
        ->assertJsonPath('copies.0.steps.0.state', 'cancelled');
    expect($exportTask->refresh()->status)->toBe(DaemonTaskStatus::Cancelled)
        ->and(AuditEvent::query()->where('action', 'data.copy_cancelled')->exists())->toBeTrue();

    // While the import waits for its safety snapshot, cancelling cancels the import.
    Backups::destination($team, $owner);
    $this->postJson(route('transfers.services.copies.store', $staging), $body)->assertSuccessful();
    exportCopy($this, $server, Transfers::pgCustom('18.1', '18.1', 100));
    $second = DataCopyRun::query()->latest('id')->firstOrFail();
    $import = DataImportRun::query()->sole();
    expect($import->status)->toBe(ImportRunStatus::WaitingForSafetyBackup);

    $this->postJson(route('transfers.services.copies.cancel', [$staging, $second->public_id]))->assertSuccessful();
    expect($import->refresh()->status)->toBe(ImportRunStatus::Cancelled)
        ->and($second->refresh()->status)->toBe(CopyRunStatus::Cancelled);

    // Once launchroomd loads the data it can't be stopped halfway.
    $this->postJson(route('transfers.services.copies.store', $staging), $body)->assertSuccessful();
    exportCopy($this, $server, Transfers::pgCustom('18.1', '18.1', 100));
    VolumeServices::backedUp($server, str_repeat('34', 32));
    resolve(AdvanceImports::class)->handle();
    $third = DataCopyRun::query()->latest('id')->firstOrFail();
    expect(DataImportRun::query()->latest('id')->firstOrFail()->status)->toBe(ImportRunStatus::Importing);
    $this->postJson(route('transfers.services.copies.cancel', [$staging, $third->public_id]))->assertStatus(409);
});

test('targets and sources that can’t be copied say why', function (): void {
    [$team, , $production, $staging] = copyPair();
    $owner = memberOf($team, TeamRole::Owner);

    $staging->environment()->update(['secrets_withheld' => true]);
    $this->actingAs($owner)->getJson(route('transfers.services.copies.options', $staging->refresh()))
        ->assertJsonPath('refusal', 'This preview comes from a fork, so it never receives data from other environments.');
    $this->postJson(route('transfers.services.copies.store', $staging), ['source' => Environment::query()->whereKey($production->environment_id)->value('public_id'), 'point' => 'live', 'confirmation' => 'staging'])->assertStatus(422);

    $newer = new DatabaseSource(DatabaseEngine::Postgres, '18');
    $older = new DatabaseSource(DatabaseEngine::Postgres, '17');
    expect(CopyRules::versionRefusal($newer, $older, 'production', 'staging', 'postgres'))
        ->toBe('production runs PostgreSQL 18 and staging runs PostgreSQL 17. A dump can’t go into an older major version. Pick 18 for staging’s postgres first.')
        ->and(CopyRules::versionRefusal($older, $newer, 'staging', 'production', 'postgres'))->toBeNull()
        ->and(CopyRules::versionRefusal(new DatabaseSource(DatabaseEngine::MongoDb, '7.0'), $newer, 'a', 'b', 'db'))->toContain('can’t be copied')
        ->and(CopyRules::versionRefusal($newer, new DatabaseSource(DatabaseEngine::Redis, '7'), 'a', 'b', 'cache'))->toBe('Copying Redis data isn’t supported yet.')
        ->and(CopyRules::estimatedStopSeconds(0))->toBe(30)
        ->and(CopyRules::estimatedStopSeconds(2 * 1024 * 1024 * 1024))->toBe(150);
});

test('an import or restore waits for a copy into the same service', function (): void {
    [$team, , $production, $staging] = copyPair();
    $owner = memberOf($team, TeamRole::Owner);
    $staging->load('service');
    $this->actingAs($owner)->postJson(route('transfers.services.copies.store', $staging), ['source' => Environment::query()->whereKey($production->environment_id)->value('public_id'), 'point' => 'live', 'confirmation' => 'staging'])->assertSuccessful();

    [$transfer] = Transfers::upload($this, $owner, $staging, Transfers::pgCustom('15.8', '15.8', 100));
    $this->postJson(route('transfers.services.imports.store', $staging), ['transfer' => $transfer->public_id, 'replace' => true, 'confirmation' => $staging->service->slug])
        ->assertStatus(409)
        ->assertJsonPath('message', 'A copy from another environment into this service is running. Import once it has finished.');
});

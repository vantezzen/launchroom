<?php

declare(strict_types=1);

use App\Actions\Transfers\AdvanceImports;
use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskLease;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\TeamRole;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\TransferStatus;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\Team;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use Illuminate\Support\Facades\DB;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;
use Tests\Support\Transfers;
use Tests\Support\VolumeServices;

/*
| Database and volume imports (D55, docs/14 §12): chunked, resumable,
| encrypted uploads; format and version checks; replace with a safety
| snapshot; holds; the signed chunk endpoint for launchroomd; URLs sealed
| to the server and never stored readable.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    $this->store = Transfers::useTempStore();
});

function withTransfers(Server $server): Server
{
    $server->forceFill(['daemon_features' => [DaemonFeature::DataTransfer->value]])->save();

    return $server->refresh();
}

test('an owner uploads a dump in chunks, resumes it, and it is recognised and encrypted at rest', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    withTransfers($server);
    $owner = memberOf($team, TeamRole::Owner);
    $bytes = Transfers::pgCustom('15.8 (Debian 15.8-1.pgdg120+1)', '15.8', Transfers::CHUNK + 4096);

    $created = $this->actingAs($owner)->postJson(route('transfers.services.transfers.store', $postgres), ['purpose' => 'database_import', 'fileName' => '../../etc/umami.dump', 'sizeBytes' => strlen($bytes)])
        ->assertCreated()
        ->assertJsonPath('status', 'receiving')
        ->assertJsonPath('fileName', 'umami.dump')
        ->assertJsonPath('chunks', 2)
        ->assertJsonPath('chunkBytes', Transfers::CHUNK);
    $id = (string) $created->json('id');
    [$first, $second] = str_split($bytes, Transfers::CHUNK);

    Transfers::putChunk($this, $id, 1, $second)->assertOk()->assertJson(['index' => 1, 'received' => 1, 'chunks' => 2]);
    $this->getJson(route('transfers.show', $id))->assertOk()->assertJsonPath('receivedChunks', [1]);

    Transfers::putChunk($this, $id, 0, $first, str_repeat('0', 64))->assertStatus(422);
    Transfers::putChunk($this, $id, 0, substr($first, 1))->assertStatus(422);
    Transfers::putChunk($this, $id, 2, $second)->assertStatus(422);
    $this->postJson(route('transfers.complete', $id))->assertStatus(422);

    Transfers::putChunk($this, $id, 0, $first)->assertOk();
    // A chunk sent again replaces it.
    Transfers::putChunk($this, $id, 0, $first)->assertOk()->assertJson(['received' => 2]);

    $this->postJson(route('transfers.complete', $id))
        ->assertSuccessful()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('format', 'postgres_custom')
        ->assertJsonPath('sourceVersion', 'PostgreSQL 15.8')
        ->assertJsonPath('detail', 'PostgreSQL custom dump from PostgreSQL 15.8')
        ->assertJsonPath('receivedChunks', []);

    $transfer = DataTransfer::query()->where('public_id', $id)->sole();
    $onDisk = (string) file_get_contents("{$this->store}/{$id}/0.chunk");

    expect($transfer->digest)->toBe(Transfers::digest($bytes))
        ->and($onDisk)->not->toContain('PGDMP')
        ->and($onDisk)->not->toContain('umami')
        ->and(strlen($onDisk))->toBe(Transfers::CHUNK + 40)
        ->and(json_encode($transfer->toArray()))->not->toContain($transfer->wrapped_dek);
});

test('only owners and admins upload; other teams see nothing', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    withTransfers($server);
    $body = ['purpose' => 'database_import', 'fileName' => 'a.dump', 'sizeBytes' => 10];

    $this->actingAs(memberOf($team, TeamRole::Developer))->postJson(route('transfers.services.transfers.store', $postgres), $body)->assertForbidden();
    $this->actingAs(memberOf($team, TeamRole::Viewer))->postJson(route('transfers.services.transfers.store', $postgres), $body)->assertForbidden();
    $this->actingAs(memberOf($team, TeamRole::Admin))->postJson(route('transfers.services.transfers.store', $postgres), $body)->assertCreated();
    $id = (string) DataTransfer::query()->value('public_id');

    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($stranger)->postJson(route('transfers.services.transfers.store', $postgres), $body)->assertNotFound();
    $this->actingAs($stranger)->getJson(route('transfers.show', $id))->assertNotFound();
    Transfers::putChunk($this, $id, 0, str_repeat('x', 10))->assertNotFound();
});

test('a dump from a newer PostgreSQL is refused when the upload completes, naming both versions', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    withTransfers($server);
    $owner = memberOf($team, TeamRole::Owner);

    [$transfer, $response] = Transfers::upload($this, $owner, $postgres, Transfers::pgCustom('19.1', '19.1', 2048));

    $response->assertStatus(422);
    expect((string) $response->json('message'))->toContain('PostgreSQL 19.1')->toContain('PostgreSQL 18')
        ->and($transfer->refresh()->status)->toBe(TransferStatus::Failed)
        ->and(is_dir("{$this->store}/{$transfer->public_id}"))->toBeFalse();
});

test('servers without dataTransfer are refused with an update hint', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);

    $this->actingAs($owner)->postJson(route('transfers.services.inspect', $postgres), [])
        ->assertStatus(422)
        ->assertJsonPath('message', "Update {$server->name} in Settings → Updates to import data.");
    $this->getJson(route('transfers.services.show', $postgres))
        ->assertOk()
        ->assertJsonPath('importSupported', true)
        ->assertJsonPath('unavailableReason', "Update {$server->name} in Settings → Updates to import data.");
});

test('replacing needs the typed slug, takes a safety snapshot, then launchroomd fetches signed chunks', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    withTransfers($server);
    $postgres->load('service');
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    $bytes = Transfers::pgCustom('15.8', '15.8', Transfers::CHUNK + 100);
    [$transfer] = Transfers::upload($this, $owner, $postgres, $bytes);

    $this->postJson(route('transfers.services.imports.store', $postgres), ['transfer' => $transfer->public_id, 'replace' => true, 'confirmation' => 'nope'])->assertStatus(422);
    $this->postJson(route('transfers.services.imports.store', $postgres), ['transfer' => $transfer->public_id, 'replace' => true, 'confirmation' => $postgres->service->slug])
        ->assertSuccessful()
        ->assertJsonPath('imports.0.status', 'waiting_for_safety_backup')
        ->assertJsonPath('imports.0.safetySnapshot', true)
        ->assertJsonPath('imports.0.sourceLabel', 'umami.dump');
    $run = DataImportRun::query()->sole();

    // A second import while one runs is refused.
    $this->postJson(route('transfers.services.imports.store', $postgres), ['transfer' => $transfer->public_id, 'replace' => true, 'confirmation' => $postgres->service->slug])->assertStatus(409);

    VolumeServices::backedUp($server, str_repeat('ab', 32));
    resolve(AdvanceImports::class)->handle();
    expect($run->refresh()->status)->toBe(ImportRunStatus::Importing);

    $daemon = Transfers::daemon($server);
    $other = withTransfers(Projects::server($team)[0]);
    $otherDaemon = Transfers::daemon($other);
    $chunkUrl = "/daemon/v1/transfers/{$transfer->public_id}/chunks/0";

    // Not leased yet.
    $daemon->send($this, 'GET', $chunkUrl)->assertNotFound();

    $task = VolumeServices::lease($server, DaemonTaskType::DbImport);
    expect(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease([Task::fromTask($task)]))))->toBe([]);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['mode'])->toBe('import')
        ->and($spec['engine'])->toBe('postgres')
        ->and($spec['replace'])->toBeTrue()
        ->and($spec['source']['file'])->toEqual(['id' => $transfer->public_id, 'sizeBytes' => strlen($bytes), 'chunkBytes' => Transfers::CHUNK, 'chunks' => 2, 'sha256' => Transfers::digest($bytes)]);

    $chunk = $daemon->send($this, 'GET', $chunkUrl)->assertOk();
    expect($chunk->getContent())->toBe(substr($bytes, 0, Transfers::CHUNK))
        ->and($chunk->headers->get('X-LR-Signature'))->toStartWith('kid=');
    $daemon->send($this, 'GET', "/daemon/v1/transfers/{$transfer->public_id}/chunks/2")->assertStatus(422);
    $otherDaemon->send($this, 'GET', $chunkUrl)->assertNotFound();

    VolumeServices::finish($server, $task, 'succeeded', ['dataImport' => ['mode' => 'import', 'empty' => false, 'tables' => 14, 'sizeBytes' => 48234496, 'serverVersion' => '18.1', 'format' => 'postgres_custom', 'sourceVersion' => 'PostgreSQL 15.8', 'readBytes' => strlen($bytes), 'durationMs' => 73000]]);

    expect($run->refresh()->status)->toBe(ImportRunStatus::Succeeded)
        ->and($run->result['tables'] ?? null)->toBe(14)
        ->and(AuditEvent::query()->where('action', 'data.import_finished')->exists())->toBeTrue();
    $daemon->send($this, 'GET', $chunkUrl)->assertNotFound();
    $this->getJson(route('transfers.services.show', $postgres))->assertJsonPath('imports.0.result.tables', 14);
});

test('a connection URL is sealed to the server, used by the task and never stored or shown', function (): void {
    [$team, $server, $keypair, $postgres] = Backups::runningPostgres();
    withTransfers($server);
    $owner = memberOf($team, TeamRole::Owner);
    $url = 'postgres://umami:hunter2-very-secret@db.old-server.example:5432/umami?sslmode=require';

    $this->actingAs($owner)->postJson(route('transfers.services.imports.store', $postgres), ['sourceUrl' => 'mysql://u:p@h/db'])->assertStatus(422);
    $response = $this->postJson(route('transfers.services.imports.store', $postgres), ['sourceUrl' => $url])
        ->assertSuccessful()
        ->assertJsonPath('imports.0.sourceKind', 'url')
        ->assertJsonPath('imports.0.sourceLabel', 'postgres://db.old-server.example:5432/umami')
        ->assertJsonPath('imports.0.status', 'importing');

    $task = VolumeServices::lease($server, DaemonTaskType::DbImport);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    $opened = Backups::openTaskEnv($spec['source']['url']['sealed'], $keypair);

    $runs = json_encode(DB::table('data_import_runs')->get(), JSON_THROW_ON_ERROR);
    $audit = json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);

    expect($opened)->toBe(['serverId' => $server->public_id, 'taskId' => $task->public_id, 'env' => ['SOURCE_URL' => $url]])
        ->and($response->getContent())->not->toContain('hunter2')
        ->and($runs)->not->toContain('hunter2')
        ->and($audit)->not->toContain('hunter2')
        ->and(DataImportRun::query()->sole()->sealed_source)->toBeNull();

    // A check of the connection also seals it, for its own task.
    $inspection = $this->postJson(route('transfers.services.inspect', $postgres), ['sourceUrl' => $url])->assertSuccessful()->assertJsonPath('status', 'pending');
    $inspect = VolumeServices::lease($server, DaemonTaskType::DbImport);
    $this->getJson(route('transfers.services.inspection', [$postgres, $inspection->json('id')]))->assertJsonPath('status', 'pending');
    VolumeServices::finish($server, $inspect, 'succeeded', ['dataImport' => ['mode' => 'inspect', 'empty' => true, 'serverVersion' => '18.1', 'sourceVersion' => 'PostgreSQL 16.4', 'durationMs' => 900]]);
    $this->getJson(route('transfers.services.inspection', [$postgres, $inspection->json('id')]))
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('empty', true)
        ->assertJsonPath('sourceVersion', 'PostgreSQL 16.4');
    $this->actingAs(memberOf($team, TeamRole::Owner))->getJson(route('transfers.services.inspection', [$postgres, $inspection->json('id')]))->assertNotFound();
    expect(json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR))->not->toContain('hunter2');
});

test('a volume import holds the service until the task ends and carries strip and owner', function (): void {
    [$team, $server, , $app] = VolumeServices::runningApp();
    withTransfers($server);
    $owner = memberOf($team, TeamRole::Owner);
    $dir = sys_get_temp_dir().'/lr-vol-'.bin2hex(random_bytes(4));
    mkdir($dir.'/uploads', 0755, true);
    file_put_contents($dir.'/uploads/a.txt', 'hello');
    $tar = new PharData($dir.'/x.tar');
    $tar->buildFromDirectory($dir, '#uploads#');
    $volume = $app->volumes()->sole();

    [$transfer, $response] = Transfers::upload($this, $owner, $app, (string) file_get_contents($dir.'/x.tar'), 'uploads.tar', 'volume_import', $volume->public_id);
    $response->assertSuccessful()->assertJsonPath('format', 'tar')->assertJsonPath('topFolder', 'uploads');

    $this->postJson(route('transfers.services.imports.store', $app), ['transfer' => $transfer->public_id, 'stripComponents' => 1, 'owner' => ['uid' => 1000, 'gid' => 1000]])
        ->assertSuccessful()
        ->assertJsonPath('imports.0.status', 'holding');
    expect(Projects::nodeState($server)['workloads'])->toBe([]);

    // launchroomd waits for the volume to be free itself; the dashboard gives the held service the restore wait.
    Projects::report($server);
    $this->travel(config()->integer('launchroom.backups.restore_hold_wait_seconds') + 1)->seconds();
    resolve(AdvanceImports::class)->handle();
    $task = VolumeServices::lease($server, DaemonTaskType::VolumeImport);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec)->toMatchArray(['mode' => 'import', 'volume' => $volume->public_id, 'stripComponents' => 1, 'owner' => ['uid' => 1000, 'gid' => 1000]])
        ->and($spec)->not->toHaveKey('replace');

    VolumeServices::finish($server, $task, 'succeeded', ['dataImport' => ['mode' => 'import', 'empty' => false, 'files' => 2, 'sizeBytes' => 5, 'format' => 'tar', 'durationMs' => 300]]);

    expect(DataImportRun::query()->sole()->status)->toBe(ImportRunStatus::Succeeded)
        ->and(Projects::nodeState($server)['workloads'])->not->toBe([]);
});

test('cancelling an upload deletes its chunks; one in use is kept', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    withTransfers($server);
    $owner = memberOf($team, TeamRole::Owner);
    [$transfer] = Transfers::upload($this, $owner, $postgres, Transfers::pgCustom('15.8', '15.8', 4096));

    $this->postJson(route('transfers.services.imports.store', $postgres), ['transfer' => $transfer->public_id])->assertSuccessful();
    $this->deleteJson(route('transfers.destroy', $transfer->public_id))->assertStatus(409);

    [$other] = Transfers::upload($this, $owner, $postgres, Transfers::pgCustom('15.8', '15.8', 4096), 'b.dump');
    $this->deleteJson(route('transfers.destroy', $other->public_id))->assertNoContent();
    expect(is_dir("{$this->store}/{$other->public_id}"))->toBeFalse()
        ->and(DataTransfer::query()->whereKey($other->id)->exists())->toBeFalse();
});

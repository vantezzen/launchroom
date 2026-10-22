<?php

declare(strict_types=1);

use App\Actions\Backups\StartDatabaseBackup;
use App\Data\Audit\AuditActor;
use App\Enums\ApiAbility;
use App\Enums\Backups\BackupReason;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\TeamRole;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\TransferStatus;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataTransfer;
use Tests\Support\Api;
use Tests\Support\Backups;
use Tests\Support\FakeDaemon;
use Tests\Support\Projects;
use Tests\Support\Transfers;
use Tests\Support\VolumeServices;
use Tests\TestCase;

/*
| Downloads (D55, docs/14 §12.5): owners and admins with a recent password
| start an export; launchroomd uploads it in signed chunks; it is ready only
| when size, chunk count and digest match; a link is one-use, short-lived and
| bound to its user.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    $this->store = Transfers::useTempStore();
});

/**
 * Uploads $bytes as launchroomd would for the leased export $task.
 */
function daemonUploads(TestCase $test, FakeDaemon $daemon, string $transfer, string $bytes): void
{
    foreach (str_split($bytes, Transfers::CHUNK) as $index => $chunk) {
        $daemon->send($test, 'PUT', "/daemon/v1/transfers/{$transfer}/chunks/{$index}", $chunk, ['Content-Type' => 'application/octet-stream'])->assertNoContent();
    }
}

function exportable(Server $server): void
{
    $server->forceFill(['daemon_features' => [DaemonFeature::DataTransfer->value]])->save();
}

test('an authorized download needs no extra password, is uploaded by launchroomd and is one use', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    exportable($server);
    $owner = memberOf($team, TeamRole::Owner);

    $this->actingAs($owner)->postJson(route('transfers.services.exports.store', $postgres))
        ->assertSuccessful()
        ->assertJsonPath('exports.0.status', 'preparing')
        ->assertJsonPath('exports.0.source', 'live');
    $run = DataExportRun::query()->sole();
    $transfer = DataTransfer::query()->whereKey($run->transfer_id)->sole();

    expect($run->file_name)->toMatch('/^postgres-\d{8}-\d{4}\.dump$/')
        ->and(AuditEvent::query()->where('action', 'data.export_started')->exists())->toBeTrue();

    $daemon = Transfers::daemon($server);
    // Before the lease the server may not write it.
    $daemon->send($this, 'PUT', "/daemon/v1/transfers/{$transfer->public_id}/chunks/0", 'x', ['Content-Type' => 'application/octet-stream'])->assertNotFound();

    $task = VolumeServices::lease($server, DaemonTaskType::DbExport);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['transfer'])->toBe(['id' => $transfer->public_id, 'chunkBytes' => Transfers::CHUNK])
        ->and($spec['workload'])->toStartWith('wl_')
        ->and($spec)->not->toHaveKey('snapshot');

    $bytes = Transfers::pgCustom('18.1', '18.1', Transfers::CHUNK + 777);
    daemonUploads($this, $daemon, $transfer->public_id, $bytes);
    $this->getJson(route('transfers.services.show', $postgres))->assertJsonPath('exports.0.sizeBytes', strlen($bytes));

    VolumeServices::finish($server, $task, 'succeeded', ['dataExport' => ['format' => 'postgres_custom', 'sizeBytes' => strlen($bytes), 'chunks' => 2, 'sha256' => Transfers::digest($bytes), 'durationMs' => 1200]]);
    expect($run->refresh()->status)->toBe(ExportRunStatus::Ready);

    $link = $this->postJson(route('transfers.services.exports.link', [$postgres, $run->public_id]))->assertSuccessful();
    $url = (string) $link->json('url');
    expect($url)->toContain('/transfers/download/lr_dl_');

    // Another owner can't use it.
    $this->actingAs(memberOf($team, TeamRole::Owner))->get($url)->assertNotFound();

    $download = $this->actingAs($owner)->get($url)->assertOk();
    expect($download->streamedContent())->toBe($bytes)
        ->and($download->headers->get('Content-Disposition'))->toContain('attachment')->toContain($run->file_name)
        ->and((int) $download->headers->get('Content-Length'))->toBe(strlen($bytes))
        ->and(AuditEvent::query()->where('action', 'data.export_downloaded')->exists())->toBeTrue();

    // One use.
    $this->get($url)->assertNotFound();

    // A new link works until it expires.
    $again = (string) $this->postJson(route('transfers.services.exports.link', [$postgres, $run->public_id]))->json('url');
    $this->travel(301)->seconds();
    $this->get($again)->assertNotFound();
});

test('chunks that don’t match what launchroomd reported fail the export and are deleted', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    exportable($server);
    $owner = memberOf($team, TeamRole::Owner);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->postJson(route('transfers.services.exports.store', $postgres))->assertSuccessful();
    $run = DataExportRun::query()->sole();
    $transfer = DataTransfer::query()->whereKey($run->transfer_id)->sole();
    $daemon = Transfers::daemon($server);
    $task = VolumeServices::lease($server, DaemonTaskType::DbExport);
    daemonUploads($this, $daemon, $transfer->public_id, str_repeat('a', 100));

    // Another server holds no task for it.
    [$other] = Projects::server($team);
    Transfers::daemon($other)->send($this, 'PUT', "/daemon/v1/transfers/{$transfer->public_id}/chunks/0", 'b', ['Content-Type' => 'application/octet-stream'])->assertNotFound();

    VolumeServices::finish($server, $task, 'succeeded', ['dataExport' => ['format' => 'postgres_custom', 'sizeBytes' => 100, 'chunks' => 1, 'sha256' => str_repeat('0', 64), 'durationMs' => 5]]);

    expect($run->refresh()->status)->toBe(ExportRunStatus::Failed)
        ->and($run->error)->toContain('damaged')
        ->and($transfer->refresh()->status)->toBe(TransferStatus::Failed)
        ->and(is_dir("{$this->store}/{$transfer->public_id}"))->toBeFalse();
});

test('a backup snapshot is exported with its destination sealed to the task', function (): void {
    [$team, $server, $keypair, $postgres] = Backups::runningPostgres();
    exportable($server);
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    resolve(StartDatabaseBackup::class)->handle($postgres, BackupReason::Manual, $owner, AuditActor::user($owner));
    VolumeServices::backedUp($server, $snapshot = str_repeat('cd', 32));

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('transfers.services.exports.store', $postgres), ['snapshot' => substr($snapshot, 0, 8)])
        ->assertSuccessful()
        ->assertJsonPath('exports.0.source', 'snapshot')
        ->assertJsonPath('exports.0.snapshot', substr($snapshot, 0, 8));
    $this->postJson(route('transfers.services.exports.store', $postgres), ['snapshot' => 'deadbeef'])->assertStatus(422);
    $this->postJson(route('transfers.services.exports.store', $postgres), ['snapshot' => substr($snapshot, 0, 8)])->assertStatus(409);

    $task = VolumeServices::lease($server, DaemonTaskType::DbExport);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    $credentials = Backups::openTaskEnv($spec['destination']['credentials']['sealed'], $keypair);

    expect($spec['snapshot'])->toBe($snapshot)
        ->and($spec)->not->toHaveKey('workload')
        ->and($credentials['taskId'])->toBe($task->public_id)
        ->and(array_keys($credentials['env']))->toBe(['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'RESTIC_PASSWORD']);
});

test('developers can’t download; the API needs the data:transfer ability', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    exportable($server);
    $postgres->load('environment', 'service');
    $developer = memberOf($team, TeamRole::Developer);
    $owner = memberOf($team, TeamRole::Owner);

    $this->actingAs($developer)->withSession(['auth.password_confirmed_at' => time()])->postJson(route('transfers.services.exports.store', $postgres))->assertForbidden();

    $path = "environments/{$postgres->environment->public_id}/services/{$postgres->service->public_id}/exports";
    Api::call('POST', $path, Api::token($owner, $team, [ApiAbility::Read]), [])->assertForbidden()->assertJsonPath('error.code', 'missing_ability');
    Api::call('POST', $path, Api::rawToken($developer, $team, ['read', 'data:transfer']), [])->assertForbidden();

    $plain = Api::token($owner, $team, [ApiAbility::Read, ApiAbility::DataTransfer]);
    $created = Api::call('POST', $path, $plain, [])->assertCreated()->assertJsonPath('data.status', 'preparing');
    $id = (string) $created->json('data.id');
    Api::get("exports/{$id}", $plain)->assertOk()->assertJsonPath('data.id', $id);
    Api::call('POST', "exports/{$id}/link", $plain)->assertStatus(409);

    $task = VolumeServices::lease($server, DaemonTaskType::DbExport);
    $transfer = DataTransfer::query()->whereKey(DataExportRun::query()->sole()->transfer_id)->sole();
    daemonUploads($this, Transfers::daemon($server), $transfer->public_id, 'tiny');
    VolumeServices::finish($server, $task, 'succeeded', ['dataExport' => ['format' => 'postgres_custom', 'sizeBytes' => 4, 'chunks' => 1, 'sha256' => Transfers::digest('tiny'), 'durationMs' => 5]]);

    $url = (string) Api::call('POST', "exports/{$id}/link", $plain)->assertOk()->json('data.url');
    expect($url)->toContain('/api/v1/downloads/lr_dl_');
    $download = Api::get(substr($url, strpos($url, '/api/v1/') + 8), $plain)->assertOk();
    expect($download->streamedContent())->toBe('tiny');
});

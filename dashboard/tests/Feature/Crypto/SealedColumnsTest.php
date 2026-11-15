<?php

declare(strict_types=1);

use App\Contracts\Crypto\SealedRow;
use App\Enums\Dockerfiles\AiProvider;
use App\Enums\IngressCredentialPurpose;
use App\Enums\Mesh\MeshCredentialPurpose;
use App\Exceptions\DecryptionFailed;
use App\Models\Alerts\AlertChannel;
use App\Models\AuditSink;
use App\Models\Backups\BackupDestination;
use App\Models\DaemonClientCa;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\IngressCredential;
use App\Models\Mesh\MeshCredential;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\Registry\RegistryStorage;
use App\Models\Transfers\DataTransfer;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SealedColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
});

test('every table with a wrapped data key is rotated by rotate-kek', function (): void {
    $tables = collect(DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'wrapped_dek'"))
        ->map(static fn (object $row): string => (string) $row->table_name)
        ->sort()->values()->all();
    $covered = collect(SealedColumns::MODELS)
        ->map(static fn (string $model): string => new $model()->getTable())
        ->sort()->values()->all();

    expect($covered)->toBe($tables);
});

/**
 * The wrap context and associated data are part of the stored format: rows
 * sealed before the shared primitive must keep decrypting.
 *
 * @return array<string, array{0: Closure(): Model, 1: string, 2: array<string, string>}>
 */
dataset('stored formats', fn (): array => [
    'alert channel' => [fn () => new AlertChannel()->forceFill(['public_id' => 'ach_X', 'team_id' => 7]), 'alert_channel:ach_X', ['secret' => 'alert_channel|7|ach_X|secret']],
    'audit sink' => [fn () => new AuditSink()->forceFill(['public_id' => 'aus_X']), 'audit_sink:aus_X', ['secret' => 'audit_sink|instance|aus_X|secret']],
    'team destination' => [fn () => new BackupDestination()->forceFill(['public_id' => 'bkd_X', 'team_id' => 7]), 'backup_destination:bkd_X', ['credentials' => 'backup_destination|7|bkd_X|credentials', 'repository_password' => 'backup_destination|7|bkd_X|repository_password']],
    'instance destination' => [fn () => new BackupDestination()->forceFill(['public_id' => 'bkd_X', 'team_id' => null]), 'backup_destination:bkd_X', ['credentials' => 'backup_destination|instance|bkd_X|credentials']],
    'client ca' => [fn () => new DaemonClientCa()->forceFill(['id' => 3]), 'daemon_client_ca:3', ['private_key' => 'daemon_client_ca|3|key']],
    'ai provider' => [fn () => new AiProviderSettings()->forceFill(['provider' => AiProvider::Anthropic]), 'ai_provider:anthropic', ['api_key_ciphertext' => 'ai_provider|anthropic']],
    'git connection' => [fn () => new GitConnection()->forceFill(['public_id' => 'con_X', 'team_id' => 7]), 'git_connection:con_X', ['access_token' => 'git_connection|7|con_X|access_token', 'webhook_secret' => 'git_connection|7|con_X|webhook_secret']],
    'github app' => [fn () => new GitHubApp()->forceFill(['public_id' => 'gha_X', 'team_id' => 7]), 'github_app:gha_X', ['private_key' => 'github_app|7|gha_X|private_key', 'client_secret' => 'github_app|7|gha_X|client_secret']],
    'ingress credential' => [fn () => new IngressCredential()->forceFill(['purpose' => IngressCredentialPurpose::cases()[0]]), 'ingress_credential:'.IngressCredentialPurpose::cases()[0]->value, ['ciphertext' => 'ingress_credential|'.IngressCredentialPurpose::cases()[0]->value]],
    'mesh credential' => [fn () => new MeshCredential()->forceFill(['scope' => 'server:4', 'purpose' => MeshCredentialPurpose::cases()[0]]), 'mesh_credential:server:4:'.MeshCredentialPurpose::cases()[0]->value, ['ciphertext' => 'mesh_credential|server:4|'.MeshCredentialPurpose::cases()[0]->value]],
    'registry storage' => [fn (): RegistryStorage => new RegistryStorage, 'registry_storage:instance', ['credentials' => 'registry_storage|instance|credentials']],
    'data transfer' => [fn () => new DataTransfer()->forceFill(['public_id' => 'dtr_X']), 'transfer:dtr_X', []],
    'project key' => [fn () => new ProjectKey()->setRelation('project', new Project()->forceFill(['public_id' => 'prj_X'])), 'project:prj_X', []],
]);

test('rows sealed in the stored format reveal through the shared primitive', function (Closure $make, string $wrapContext, array $associatedData): void {
    /** @var Model&SealedRow $row */
    $row = $make();
    $envelope = resolve(Envelope::class);
    $sealed = resolve(SealedColumns::class);

    expect($row->sealWrapContext())->toBe($wrapContext);

    foreach ($associatedData as $column => $ad) {
        // Sealed by hand, the way the per-model classes did before.
        $dek = $envelope->generateDataKey();
        $row->setAttribute('wrapped_dek', $envelope->wrap($dek, $wrapContext));
        $row->setAttribute($column, $dek->encrypt('s3cret-'.$column, $ad));

        expect($row->sealAssociatedData($column))->toBe($ad)
            ->and($sealed->reveal($row, $column))->toBe('s3cret-'.$column);
    }

    expect(fn () => $row->sealAssociatedData('name'))->toThrow(InvalidArgumentException::class);
})->with('stored formats');

test('a sealed column is bound to its row and column', function (): void {
    $sealed = resolve(SealedColumns::class);
    $a = new GitHubApp()->forceFill(['public_id' => 'gha_A', 'team_id' => 1]);
    $b = new GitHubApp()->forceFill(['public_id' => 'gha_B', 'team_id' => 1]);
    $sealed->seal($a, 'private_key', 'key-a', $sealed->newKey($a));
    $sealed->seal($a, 'client_secret', 'secret-a');

    $a->private_key = $a->client_secret;
    $b->wrapped_dek = $a->wrapped_dek;
    $b->client_secret = $a->client_secret;

    expect(fn () => $sealed->reveal($a, 'private_key'))->toThrow(DecryptionFailed::class)
        ->and(fn () => $sealed->reveal($b, 'client_secret'))->toThrow(DecryptionFailed::class);
});

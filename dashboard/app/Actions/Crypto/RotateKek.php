<?php

declare(strict_types=1);

namespace App\Actions\Crypto;

use App\Enums\Transfers\TransferStatus;
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
use App\Services\Alerts\AlertChannelSecrets;
use App\Services\Audit\AuditSinkSecrets;
use App\Services\Backups\DestinationSecrets;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Daemon\ClientCertificateAuthority;
use App\Services\Dockerfiles\AiProviderSecrets;
use App\Services\Git\GitConnectionSecrets;
use App\Services\GitHub\GitHubAppSecrets;
use App\Services\Ingress\IngressSecrets;
use App\Services\Mesh\MeshSecrets;
use App\Services\Registry\RegistryStorageSecrets;
use App\Services\Transfers\TransferStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KEK rotation (docs/09 R13, `launchroom:keys:rotate-kek`): every wrapped
 * DEK (project keys, GitHub App keys, git connection keys, backup destination keys, mesh and ingress credentials, registry storage, alert channels, audit sinks, the client-certificate CA, the AI provider key, data transfers in flight) is unwrapped
 * with the current KEK and wrapped with the new one, and its `kek_id`
 * updated, in one transaction. Values encrypted under the DEKs don't change.
 * Rows already wrapped by the new KEK are skipped, so a re-run with the same
 * new key is harmless; a row wrapped by an unknown KEK aborts everything.
 */
final readonly class RotateKek
{
    public function __construct(
        private ProjectKeyring $keyring,
        private GitHubAppSecrets $githubSecrets,
        private DestinationSecrets $destinationSecrets,
        private IngressSecrets $ingressSecrets,
        private MeshSecrets $meshSecrets,
        private RegistryStorageSecrets $registrySecrets,
        private AlertChannelSecrets $alertSecrets,
        private AuditSinkSecrets $auditSinkSecrets,
        private GitConnectionSecrets $gitSecrets,
        private AiProviderSecrets $aiSecrets,
        private TransferStore $transfers,
    ) {}

    /**
     * @return array{rewrapped: int, skipped: int}
     */
    public function handle(Envelope $current, Envelope $next): array
    {
        if (hash_equals($current->kekId(), $next->kekId())) {
            throw new RuntimeException('The new KEK is the current KEK.');
        }

        return DB::transaction(function () use ($current, $next): array {
            $counts = ['rewrapped' => 0, 'skipped' => 0];

            $projectKeys = ProjectKey::query()->with('project')->orderBy('id')->lockForUpdate()->get();

            foreach ($projectKeys as $key) {
                $this->rotateRow($key, $current, $next, $counts, function () use ($key, $current, $next): void {
                    /** @var Project $project */
                    $project = $key->project;
                    $this->keyring->rewrap($project, $key, $current, $next);
                });
            }

            foreach (GitHubApp::query()->orderBy('id')->lockForUpdate()->get() as $app) {
                $this->rotateRow($app, $current, $next, $counts, fn () => $this->githubSecrets->rewrap($app, $current, $next));
            }

            foreach (GitConnection::query()->orderBy('id')->lockForUpdate()->get() as $connection) {
                $this->rotateRow($connection, $current, $next, $counts, fn () => $this->gitSecrets->rewrap($connection, $current, $next));
            }

            foreach (BackupDestination::query()->orderBy('id')->lockForUpdate()->get() as $destination) {
                $this->rotateRow($destination, $current, $next, $counts, fn () => $this->destinationSecrets->rewrap($destination, $current, $next));
            }

            foreach (IngressCredential::query()->orderBy('id')->lockForUpdate()->get() as $credential) {
                $this->rotateRow($credential, $current, $next, $counts, fn () => $this->ingressSecrets->rewrap($credential, $current, $next));
            }

            foreach (MeshCredential::query()->orderBy('id')->lockForUpdate()->get() as $credential) {
                $this->rotateRow($credential, $current, $next, $counts, fn () => $this->meshSecrets->rewrap($credential, $current, $next));
            }

            foreach (RegistryStorage::query()->lockForUpdate()->get() as $storage) {
                $this->rotateRow($storage, $current, $next, $counts, fn () => $this->registrySecrets->rewrap($storage, $current, $next));
            }

            // Email channels have no secret (and no wrapped key).
            foreach (AlertChannel::query()->whereNotNull('wrapped_dek')->orderBy('id')->lockForUpdate()->get() as $channel) {
                $this->rotateRow($channel, $current, $next, $counts, fn () => $this->alertSecrets->rewrap($channel, $current, $next));
            }

            // Audit log forwarding credentials (docs/09 R34); syslog sinks have none.
            foreach (AuditSink::query()->whereNotNull('wrapped_dek')->orderBy('id')->lockForUpdate()->get() as $sink) {
                $this->rotateRow($sink, $current, $next, $counts, fn () => $this->auditSinkSecrets->rewrap($sink, $current, $next));
            }

            // The client-certificate CA key (mTLS, docs/03 §4.3).
            foreach (DaemonClientCa::query()->orderBy('id')->lockForUpdate()->get() as $ca) {
                $this->rotateRow($ca, $current, $next, $counts, static function () use ($ca, $current, $next): void {
                    $ca->wrapped_dek = $current->rewrap($ca->wrapped_dek, ClientCertificateAuthority::wrapContext($ca), $next);
                    $ca->kek_id = $next->kekId();
                });
            }

            // The Dockerfile generation provider key (docs/20 §1), when one is set.
            foreach (AiProviderSettings::query()->whereNotNull('wrapped_dek')->lockForUpdate()->get() as $settings) {
                $this->rotateRow($settings, $current, $next, $counts, fn () => $this->aiSecrets->rewrap($settings, $current, $next));
            }

            // Data transfers in flight (docs/14 §12.1): their chunks' keys.
            foreach (DataTransfer::query()->whereIn('status', [TransferStatus::Receiving->value, TransferStatus::Ready->value])->orderBy('id')->lockForUpdate()->get() as $transfer) {
                $this->rotateRow($transfer, $current, $next, $counts, fn () => $this->transfers->rewrap($transfer, $current, $next));
            }

            return $counts;
        });
    }

    /**
     * @param  array{rewrapped: int, skipped: int}  $counts
     * @param  \Closure(): void  $rewrap
     */
    private function rotateRow(Model $row, Envelope $current, Envelope $next, array &$counts, \Closure $rewrap): void
    {
        $kekId = $row->getAttribute('kek_id');
        $kekId = is_string($kekId) ? $kekId : '';

        if (hash_equals($next->kekId(), $kekId)) {
            $counts['skipped']++;

            return;
        }

        if (! hash_equals($current->kekId(), $kekId)) {
            $id = $row->getKey();

            throw new RuntimeException(class_basename($row).' #'.(is_scalar($id) ? (string) $id : '?')." is wrapped by an unknown KEK ({$kekId}); nothing was changed.");
        }

        $rewrap();
        $row->save();
        $counts['rewrapped']++;
    }
}

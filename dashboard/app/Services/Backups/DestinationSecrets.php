<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Exceptions\DecryptionFailed;
use App\Models\Backups\BackupDestination;
use App\Services\Crypto\SealedColumns;
use App\Support\Crypto\OpaqueToken;
use App\Support\S3\S3Credentials;
use SensitiveParameter;

/**
 * The typed secrets of a backup destination (docs/14 §3): S3 credentials and
 * the restic repository password, sealed under the destination's own data
 * key (App\Services\Crypto\SealedColumns).
 */
final readonly class DestinationSecrets
{
    public function __construct(
        private SealedColumns $sealed,
    ) {}

    /**
     * A fresh restic repository password: 256 bits, URL-safe (it is typed
     * into the installer from the recovery kit, so no ambiguous symbols).
     */
    public static function generateRepositoryPassword(): string
    {
        return OpaqueToken::generate();
    }

    /**
     * Encrypts the secrets onto the model (which must have its public id)
     * under a new data key.
     */
    public function seal(
        BackupDestination $destination,
        #[SensitiveParameter] string $accessKeyId,
        #[SensitiveParameter] string $secretAccessKey,
        #[SensitiveParameter] string $repositoryPassword,
    ): void {
        $dek = $this->sealed->newKey($destination);
        $this->sealed->sealJson($destination, 'credentials', new S3Credentials($accessKeyId, $secretAccessKey)->toArray(), $dek);
        $this->sealed->seal($destination, 'repository_password', $repositoryPassword, $dek);
    }

    /**
     * @throws DecryptionFailed
     */
    public function reveal(BackupDestination $destination): DestinationCredentials
    {
        $s3 = S3Credentials::of($this->sealed->revealJson($destination, 'credentials'));

        return new DestinationCredentials(
            $s3->accessKeyId,
            $s3->secretAccessKey,
            $this->sealed->reveal($destination, 'repository_password') ?? throw DecryptionFailed::malformed(),
        );
    }

    /**
     * Only the repository password: removed storage keeps it after its access
     * keys are wiped (reconnecting, disaster recovery with plain restic).
     *
     * @throws DecryptionFailed
     */
    public function repositoryPassword(BackupDestination $destination): string
    {
        return $this->sealed->reveal($destination, 'repository_password') ?? throw DecryptionFailed::malformed();
    }

    /**
     * The repository password for new storage ($destination, not saved yet):
     * the one given (a repository created elsewhere), else the one removed
     * storage of the same scope at the same bucket and folder kept (adding
     * it again reconnects its backups; the removed row hands the password
     * over and keeps no copy), else a fresh one. Callers own the transaction.
     *
     * @return array{string, 'given'|'kept'|'generated'}
     */
    public function adoptRepositoryPassword(BackupDestination $destination, #[SensitiveParameter] ?string $given): array
    {
        if ($given !== null && $given !== '') {
            return [$given, 'given'];
        }

        $removed = BackupDestination::removedAt($destination->team_id, $destination->endpoint, $destination->bucket, $destination->prefix);

        if ($removed instanceof BackupDestination) {
            try {
                $password = $this->repositoryPassword($removed);
            } catch (DecryptionFailed) {
                return [self::generateRepositoryPassword(), 'generated'];
            }

            $removed->forceFill(['wrapped_dek' => '', 'repository_password' => ''])->save();

            return [$password, 'kept'];
        }

        return [self::generateRepositoryPassword(), 'generated'];
    }
}

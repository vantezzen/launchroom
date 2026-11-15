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
}

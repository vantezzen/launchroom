<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Models\Backups\BackupDestination;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\SecretKey;
use JsonException;
use SensitiveParameter;

/**
 * Envelope encryption for backup destinations (docs/09 R13, docs/14 §3).
 *
 * Same scheme as GitHub App credentials: each destination gets its own data
 * key wrapped by the instance KEK (context `backup_destination:<public id>`),
 * values are XChaCha20-Poly1305 with associated data
 * `backup_destination|<team or "instance">|<public id>|<field>`, so
 * ciphertexts can't be moved between destinations, teams or fields.
 */
final readonly class DestinationSecrets
{
    public function __construct(
        private Envelope $envelope,
    ) {}

    /**
     * A fresh restic repository password: 256 bits, URL-safe (it is typed
     * into the installer from the recovery kit, so no ambiguous symbols).
     */
    public static function generateRepositoryPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Encrypts credentials onto the model (which must have its public id).
     * A new data key is generated each time (rotation is free).
     */
    public function seal(
        BackupDestination $destination,
        #[SensitiveParameter] string $accessKeyId,
        #[SensitiveParameter] string $secretAccessKey,
        #[SensitiveParameter] string $repositoryPassword,
    ): void {
        $dek = $this->envelope->generateDataKey();

        $destination->wrapped_dek = $this->envelope->wrap($dek, $this->wrapContext($destination));
        $destination->kek_id = $this->envelope->kekId();
        $destination->credentials = $dek->encrypt(
            json_encode(['accessKeyId' => $accessKeyId, 'secretAccessKey' => $secretAccessKey], JSON_THROW_ON_ERROR),
            $this->associatedData($destination, 'credentials'),
        );
        $destination->repository_password = $dek->encrypt($repositoryPassword, $this->associatedData($destination, 'repository_password'));
    }

    /**
     * @throws DecryptionFailed
     */
    public function reveal(BackupDestination $destination): DestinationCredentials
    {
        $dek = $this->dataKey($destination);
        $json = $dek->decrypt($destination->credentials, $this->associatedData($destination, 'credentials'));

        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DecryptionFailed::malformed();
        }

        if (! is_array($decoded) || ! is_string($decoded['accessKeyId'] ?? null) || ! is_string($decoded['secretAccessKey'] ?? null)) {
            throw DecryptionFailed::malformed();
        }

        return new DestinationCredentials(
            $decoded['accessKeyId'],
            $decoded['secretAccessKey'],
            $dek->decrypt($destination->repository_password, $this->associatedData($destination, 'repository_password')),
        );
    }

    private function dataKey(BackupDestination $destination): SecretKey
    {
        return $this->envelope->unwrap($destination->wrapped_dek, $this->wrapContext($destination));
    }

    /**
     * Re-wraps the stored DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(BackupDestination $destination, Envelope $from, Envelope $to): void
    {
        $destination->wrapped_dek = $from->rewrap($destination->wrapped_dek, $this->wrapContext($destination), $to);
        $destination->kek_id = $to->kekId();
    }

    private function wrapContext(BackupDestination $destination): string
    {
        return 'backup_destination:'.$destination->public_id;
    }

    private function associatedData(BackupDestination $destination, string $field): string
    {
        $scope = $destination->team_id === null ? 'instance' : (string) $destination->team_id;

        return "backup_destination|{$scope}|{$destination->public_id}|{$field}";
    }
}

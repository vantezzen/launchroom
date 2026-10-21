<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\Exceptions\InvalidKeyFile;

/**
 * Envelope encryption (docs/09-security.md R13).
 *
 * A key-encryption key (KEK), loaded from a file outside the database and
 * distinct from APP_KEY, wraps one data-encryption key (DEK) per project.
 * Values are encrypted with the project's DEK and associated data such as
 * `team|project|row|version`, so ciphertexts can't be swapped between rows.
 *
 *     $dek = $envelope->generateDataKey();
 *     $wrapped = $envelope->wrap($dek, "project:{$project->id}");  // store in project_keys
 *     $ciphertext = $dek->encrypt($secret, "{$teamId}|{$projectId}|{$rowId}|{$version}");
 *
 *     $dek = $envelope->unwrap($wrapped, "project:{$project->id}");
 *     $secret = $dek->decrypt($ciphertext, "{$teamId}|{$projectId}|{$rowId}|{$version}");
 */
final readonly class Envelope
{
    public function __construct(
        private SecretKey $kek,
    ) {}

    /**
     * @throws InvalidKeyFile
     */
    public static function fromKeyFile(string $path): self
    {
        return new self(KeyFile::read($path));
    }

    /**
     * Identifier of the KEK, stored next to wrapped DEKs to support rotation.
     */
    public function kekId(): string
    {
        return $this->kek->id();
    }

    public function generateDataKey(): SecretKey
    {
        return SecretKey::generate();
    }

    public function wrap(SecretKey $dataKey, string $associatedData): string
    {
        return $this->kek->encrypt($dataKey->reveal(), $this->wrapContext($associatedData));
    }

    /**
     * @throws DecryptionFailed
     */
    public function unwrap(string $wrappedDataKey, string $associatedData): SecretKey
    {
        $bytes = $this->kek->decrypt($wrappedDataKey, $this->wrapContext($associatedData));

        if (strlen($bytes) !== SecretKey::BYTES) {
            throw DecryptionFailed::malformed();
        }

        return SecretKey::fromBytes($bytes);
    }

    /**
     * Unwraps a DEK with this KEK and wraps it with $to (KEK rotation).
     *
     * @throws DecryptionFailed
     */
    public function rewrap(string $wrappedDataKey, string $associatedData, self $to): string
    {
        return $to->wrap($this->unwrap($wrappedDataKey, $associatedData), $associatedData);
    }

    /**
     * Domain-separates DEK wrapping from any other use of the KEK.
     */
    private function wrapContext(string $associatedData): string
    {
        return 'dek|'.$associatedData;
    }
}

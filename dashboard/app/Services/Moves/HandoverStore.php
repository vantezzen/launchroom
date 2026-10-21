<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Contracts\ControlPlaneDumps;
use App\Services\Transfers\TransferStore;
use Illuminate\Support\Facades\File;
use RuntimeException;
use SensitiveParameter;

/**
 * The control-plane hand-over file (docs/14 §13.4): the final `pg_dump` of
 * the dashboard's database, written in 8 MiB chunks to the transfers disk
 * (`<transfers>/<cpm_…>/<n>.chunk`, shared by every dashboard container)
 * as `nonce(24) || XChaCha20-Poly1305(chunk)` with associated data
 * `launchroom-handover|<cpm>|<n>`, under a key that exists only in memory
 * while it is written and, sealed to the new machine's X25519 key
 * (crypto_box_seal), in the hand-over manifest. Neither this dashboard nor
 * anyone who reads the disk can open the chunks afterwards; the new
 * machine fetches them as they are.
 */
final readonly class HandoverStore
{
    public const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    public const string KEYS_FILE = 'keys.sealed';

    public function __construct(
        private ControlPlaneDumps $database,
    ) {}

    /**
     * Dumps the dashboard's database into encrypted chunks.
     *
     * @return array{chunks: int, chunkBytes: int, sizeBytes: int, digests: list<string>, digest: string}
     *
     * @throws RuntimeException when pg_dump fails
     */
    public function write(string $moveId, #[SensitiveParameter] string $key, int $timeoutSeconds): array
    {
        $dir = $this->directory($moveId);
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir, 0700);

        $chunkBytes = config()->integer('launchroom.transfers.chunk_bytes');
        $index = 0;
        $digests = [];

        try {
            $size = $this->database->dump(function (string $plaintext) use ($moveId, $key, &$index, &$digests, $dir): void {
                $nonce = random_bytes(self::NONCE_BYTES);
                $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::associatedData($moveId, $index), $nonce, $key);
                $path = $dir.DIRECTORY_SEPARATOR.$index.'.chunk';

                if (File::put($path, $nonce.$ciphertext) === false) {
                    throw new RuntimeException('The hand-over file could not be written.');
                }

                @chmod($path, 0600);
                $digests[] = hash('sha256', $plaintext);
                $index++;
            }, $chunkBytes, $timeoutSeconds);
        } catch (\Throwable $e) {
            File::deleteDirectory($dir);

            throw $e;
        }

        $raw = '';

        foreach ($digests as $digest) {
            $raw .= (string) hex2bin($digest);
        }

        return ['chunks' => $index, 'chunkBytes' => $chunkBytes, 'sizeBytes' => $size, 'digests' => $digests, 'digest' => hash('sha256', $raw)];
    }

    /**
     * Chunk $index as stored (still encrypted).
     */
    public function read(string $moveId, int $index): ?string
    {
        if ($index < 0) {
            return null;
        }

        $path = $this->directory($moveId).DIRECTORY_SEPARATOR.$index.'.chunk';

        return File::isFile($path) ? File::get($path) : null;
    }

    /**
     * The key bundle sealed to the new machine, as stored.
     */
    public function readKeys(string $moveId): ?string
    {
        $path = $this->directory($moveId).DIRECTORY_SEPARATOR.self::KEYS_FILE;

        return File::isFile($path) ? File::get($path) : null;
    }

    public function writeKeys(string $moveId, string $sealed): void
    {
        $dir = $this->directory($moveId);
        File::ensureDirectoryExists($dir, 0700);
        $path = $dir.DIRECTORY_SEPARATOR.self::KEYS_FILE;

        if (File::put($path, $sealed) === false) {
            throw new RuntimeException('The sealed keys could not be written.');
        }

        @chmod($path, 0600);
    }

    public function delete(string $moveId): void
    {
        File::deleteDirectory($this->directory($moveId));
    }

    public static function associatedData(string $moveId, int $index): string
    {
        return "launchroom-handover|{$moveId}|{$index}";
    }

    /**
     * Opens a chunk (the new machine's side).
     */
    public static function open(string $moveId, int $index, string $raw, #[SensitiveParameter] string $key): ?string
    {
        if (strlen($raw) <= self::NONCE_BYTES) {
            return null;
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, self::NONCE_BYTES), self::associatedData($moveId, $index), substr($raw, 0, self::NONCE_BYTES), $key);

        return $plaintext === false ? null : $plaintext;
    }

    private function directory(string $moveId): string
    {
        if (preg_match('/^cpm_[0-9A-HJKMNP-TV-Z]{26}$/D', $moveId) !== 1) {
            throw new RuntimeException('Invalid move id.');
        }

        return TransferStore::root().DIRECTORY_SEPARATOR.$moveId;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Exceptions\DecryptionFailed;
use App\Models\Transfers\DataTransfer;
use App\Services\Crypto\SealedColumns;
use App\Support\PublicId;
use Illuminate\Support\Facades\File;
use RuntimeException;
use SensitiveParameter;

/**
 * Chunk files of transfers (docs/14 §12.1): `<path>/<dtr>/<n>.chunk`, each
 * `nonce(24) || XChaCha20-Poly1305(chunk)` under the transfer's own data key
 * with associated data `transfer|<dtr>|<n>`, so chunks can't be swapped
 * between positions or transfers. The key is the transfer row's sealed data
 * key (App\Services\Crypto\SealedColumns). Plaintext only ever exists in
 * memory, one chunk at a time.
 */
final readonly class TransferStore
{
    private const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    public function __construct(
        private SealedColumns $sealed,
    ) {}

    /**
     * Gives a new transfer (with its public id) its data key.
     */
    public function initialise(DataTransfer $transfer): void
    {
        $this->sealed->newKey($transfer);
    }

    public function put(DataTransfer $transfer, int $index, #[SensitiveParameter] string $plaintext): void
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $this->associatedData($transfer, $index), $nonce, $this->sealed->dataKey($transfer)->reveal());
        $path = $this->chunkPath($transfer, $index);
        File::ensureDirectoryExists(dirname($path), 0700);

        // Write then rename: a reader never sees half a chunk.
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (File::put($tmp, $nonce.$ciphertext, true) === false || ! rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('The chunk could not be stored.');
        }

        @chmod($path, 0600);
    }

    /**
     * @throws DecryptionFailed when the chunk is missing, tampered with or from elsewhere
     */
    public function get(DataTransfer $transfer, int $index): string
    {
        $path = $this->chunkPath($transfer, $index);
        $raw = is_file($path) ? File::get($path, true) : '';

        if (strlen($raw) < self::NONCE_BYTES) {
            throw DecryptionFailed::malformed();
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, self::NONCE_BYTES), $this->associatedData($transfer, $index), substr($raw, 0, self::NONCE_BYTES), $this->sealed->dataKey($transfer)->reveal());

        if ($plaintext === false) {
            throw DecryptionFailed::malformed();
        }

        return $plaintext;
    }

    public function has(DataTransfer $transfer, int $index): bool
    {
        return is_file($this->chunkPath($transfer, $index));
    }

    public function delete(DataTransfer $transfer): void
    {
        $this->deleteDirectory($transfer->public_id);
    }

    /**
     * Transfer directories on disk (public ids) not written to for $idleSeconds,
     * for the sweep's orphan check.
     *
     * @return list<string>
     */
    public function directories(int $idleSeconds = 600): array
    {
        $root = self::root();

        if (! is_dir($root)) {
            return [];
        }

        $names = [];

        foreach (File::directories($root) as $path) {
            if (! is_string($path)) {
                continue;
            }

            $name = basename($path);

            if (PublicId::isValid($name, 'dtr') && (int) @filemtime($path) < time() - $idleSeconds) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function deleteDirectory(string $publicId): void
    {
        if (! PublicId::isValid($publicId, 'dtr')) {
            return;
        }

        File::deleteDirectory(self::root().DIRECTORY_SEPARATOR.$publicId);
    }

    /**
     * Free bytes on the transfers disk (null: unknown).
     */
    public function freeBytes(): ?int
    {
        $root = self::root();
        File::ensureDirectoryExists($root, 0700);
        $free = @disk_free_space($root);

        return $free === false ? null : (int) $free;
    }

    public static function root(): string
    {
        return rtrim(config()->string('launchroom.transfers.path'), '/');
    }

    private function chunkPath(DataTransfer $transfer, int $index): string
    {
        if ($index < 0 || ! PublicId::isValid($transfer->public_id, 'dtr')) {
            throw new RuntimeException('Invalid transfer chunk.');
        }

        return self::root().DIRECTORY_SEPARATOR.$transfer->public_id.DIRECTORY_SEPARATOR.$index.'.chunk';
    }

    private function associatedData(DataTransfer $transfer, int $index): string
    {
        return "transfer|{$transfer->public_id}|{$index}";
    }
}

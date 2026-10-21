<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Models\Transfers\DataTransfer;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\SecretKey;
use Illuminate\Support\Facades\File;
use RuntimeException;
use SensitiveParameter;

/**
 * Chunk files of transfers (docs/14 §12.1): `<path>/<dtr>/<n>.chunk`, each
 * `nonce(24) || XChaCha20-Poly1305(chunk)` under the transfer's own data key
 * with associated data `transfer|<dtr>|<n>`, so chunks can't be swapped
 * between positions or transfers. The key is wrapped by the KEK (context
 * `transfer:<dtr>`). Plaintext only ever exists in memory, one chunk at a time.
 */
final readonly class TransferStore
{
    private const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    public function __construct(
        private Envelope $envelope,
    ) {}

    /**
     * Gives a new transfer (with its public id) its data key.
     */
    public function initialise(DataTransfer $transfer): void
    {
        $dek = $this->envelope->generateDataKey();
        $transfer->wrapped_dek = $this->envelope->wrap($dek, $this->wrapContext($transfer));
        $transfer->kek_id = $this->envelope->kekId();
    }

    public function put(DataTransfer $transfer, int $index, #[SensitiveParameter] string $plaintext): void
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $this->associatedData($transfer, $index), $nonce, $this->dataKey($transfer)->reveal());
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

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, self::NONCE_BYTES), $this->associatedData($transfer, $index), substr($raw, 0, self::NONCE_BYTES), $this->dataKey($transfer)->reveal());

        if ($plaintext === false) {
            throw DecryptionFailed::malformed();
        }

        return $plaintext;
    }

    public function has(DataTransfer $transfer, int $index): bool
    {
        return is_file($this->chunkPath($transfer, $index));
    }

    /**
     * Removes every chunk of the transfer.
     */
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

            if (preg_match('/^dtr_[0-9A-HJKMNP-TV-Z]{26}$/D', $name) === 1 && (int) @filemtime($path) < time() - $idleSeconds) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function deleteDirectory(string $publicId): void
    {
        if (preg_match('/^dtr_[0-9A-HJKMNP-TV-Z]{26}$/D', $publicId) !== 1) {
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

    /**
     * Re-wraps the data key from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(DataTransfer $transfer, Envelope $from, Envelope $to): void
    {
        $transfer->wrapped_dek = $from->rewrap($transfer->wrapped_dek, $this->wrapContext($transfer), $to);
        $transfer->kek_id = $to->kekId();
    }

    public static function root(): string
    {
        return rtrim(config()->string('launchroom.transfers.path'), '/');
    }

    private function chunkPath(DataTransfer $transfer, int $index): string
    {
        if ($index < 0 || preg_match('/^dtr_[0-9A-HJKMNP-TV-Z]{26}$/D', $transfer->public_id) !== 1) {
            throw new RuntimeException('Invalid transfer chunk.');
        }

        return self::root().DIRECTORY_SEPARATOR.$transfer->public_id.DIRECTORY_SEPARATOR.$index.'.chunk';
    }

    private function dataKey(DataTransfer $transfer): SecretKey
    {
        return $this->envelope->unwrap($transfer->wrapped_dek, $this->wrapContext($transfer));
    }

    private function wrapContext(DataTransfer $transfer): string
    {
        return 'transfer:'.$transfer->public_id;
    }

    private function associatedData(DataTransfer $transfer, int $index): string
    {
        return "transfer|{$transfer->public_id}|{$index}";
    }
}

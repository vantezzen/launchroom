<?php

declare(strict_types=1);

namespace App\Services\Traces;

use App\Exceptions\DecryptionFailed;
use App\Models\Server;
use App\Models\Traces\TraceKey;
use App\Services\Crypto\SealedColumns;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Request trace keys (docs/23 §4): store, read and reveal. The current key
 * of an edge is its newest unretired one; retired keys still open traces
 * until they are deleted. App\Actions\Traces\ProvisionTraceKeys decides
 * when keys are created, retired and deleted; renders and decoding only read.
 */
final readonly class TraceKeys
{
    public function __construct(
        private SealedColumns $sealed,
    ) {}

    /**
     * Stores a new key for $server under a fresh random key id. The caller
     * holds the transaction.
     */
    public function create(Server $server, #[SensitiveParameter] string $key): TraceKey
    {
        for ($attempt = 0; ; $attempt++) {
            $row = new TraceKey;
            $row->id = random_int(1, TraceKey::MAX_ID);
            $row->server_id = $server->id;
            $row->created_at = now();
            $this->sealed->seal($row, 'ciphertext', base64_encode($key), $this->sealed->newKey($row));

            try {
                // A savepoint inside the caller's transaction, so a collision can be retried.
                DB::transaction(static fn () => $row->save());

                return $row;
            } catch (UniqueConstraintViolationException $e) {
                // A key id taken already: draw again (2^32 ids, a handful of keys per edge).
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }

    public function current(Server $server): ?TraceKey
    {
        return TraceKey::query()
            ->where('server_id', $server->id)
            ->whereNull('retired_at')
            ->latest('created_at')
            ->first();
    }

    public function find(int $keyId): ?TraceKey
    {
        return $keyId < 1 || $keyId > TraceKey::MAX_ID ? null : TraceKey::query()->find($keyId);
    }

    /**
     * The raw 32-byte key.
     *
     * @throws DecryptionFailed
     */
    public function reveal(TraceKey $key): string
    {
        $encoded = $this->sealed->reveal($key, 'ciphertext');
        $raw = $encoded === null ? false : base64_decode($encoded, true);

        if ($raw === false || strlen($raw) !== 32) {
            throw DecryptionFailed::malformed();
        }

        return $raw;
    }

    /**
     * The key as the IngressSecret carries it: standard base64.
     *
     * @throws DecryptionFailed
     */
    public function encoded(TraceKey $key): string
    {
        return base64_encode($this->reveal($key));
    }

    /**
     * Secret-free and stable while the key is unchanged: renders compare
     * fingerprints, not the randomized sealed box.
     */
    public static function fingerprint(TraceKey $key): string
    {
        return hash('sha256', 'trace|'.$key->id.'|'.$key->ciphertext);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ingress;

use App\Enums\IngressCredentialPurpose;
use App\Exceptions\DecryptionFailed;
use App\Models\IngressCredential;
use App\Services\Crypto\SealedColumns;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Ingress secrets (docs/05 §5): the DNS provider credentials and the shared
 * certificate storage's password and encryption key, one sealed row per purpose
 * (App\Services\Crypto\SealedColumns). Values only leave the dashboard
 * sealed to an edge server's X25519 key (App\Support\Crypto\IngressSecretSealer).
 */
final readonly class IngressSecrets
{
    public function __construct(
        private SealedColumns $sealed,
    ) {}

    /**
     * Stores (or replaces) a secret; a new data key every time.
     */
    public function put(IngressCredentialPurpose $purpose, #[SensitiveParameter] string $value): IngressCredential
    {
        $row = IngressCredential::query()->firstOrNew(['purpose' => $purpose->value]);
        $row->purpose = $purpose;
        $this->sealed->seal($row, 'ciphertext', $value, $this->sealed->newKey($row));
        $row->save();

        return $row;
    }

    /**
     * @throws DecryptionFailed
     */
    public function get(IngressCredentialPurpose $purpose): ?string
    {
        $row = IngressCredential::query()->where('purpose', $purpose->value)->first();

        if (! $row instanceof IngressCredential) {
            return null;
        }

        return $this->sealed->reveal($row, 'ciphertext');
    }

    /**
     * The stored secret, created once with $generate when there is none. A
     * concurrent creator wins; its value is returned (never replaced, so all
     * edges keep one storage key).
     *
     * @param  callable(): string  $generate
     *
     * @throws DecryptionFailed
     */
    public function ensure(IngressCredentialPurpose $purpose, callable $generate): string
    {
        $existing = $this->get($purpose);

        if ($existing !== null) {
            return $existing;
        }

        $row = new IngressCredential;
        $row->purpose = $purpose;
        $this->sealed->seal($row, 'ciphertext', $generate(), $this->sealed->newKey($row));

        try {
            DB::transaction(fn () => $row->save());
        } catch (UniqueConstraintViolationException) {
            // Created concurrently.
        }

        return $this->get($purpose) ?? throw DecryptionFailed::malformed();
    }

    public function forget(IngressCredentialPurpose $purpose): void
    {
        IngressCredential::query()->where('purpose', $purpose->value)->delete();
    }

    /**
     * A stable, secret-free fingerprint of the stored value (changes when it
     * is replaced): NodeState renders compare fingerprints, not ciphertexts.
     */
    public function fingerprint(IngressCredentialPurpose $purpose): ?string
    {
        $ciphertext = IngressCredential::query()->where('purpose', $purpose->value)->value('ciphertext');

        return is_string($ciphertext) ? hash('sha256', $ciphertext) : null;
    }
}

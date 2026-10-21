<?php

declare(strict_types=1);

namespace App\Services\Ingress;

use App\Enums\IngressCredentialPurpose;
use App\Models\IngressCredential;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Envelope encryption for ingress secrets (docs/09 R13, docs/05 §5): the DNS
 * provider token and the shared certificate storage's encryption key. Each
 * row gets its own data key wrapped by the instance KEK (context
 * `ingress_credential:<purpose>`); the value is XChaCha20-Poly1305 with
 * associated data `ingress_credential|<purpose>`, so ciphertexts can't be
 * swapped between purposes. Values only leave the dashboard sealed to an edge
 * server's X25519 key (App\Support\Crypto\IngressSecretSealer).
 */
final readonly class IngressSecrets
{
    public function __construct(
        private Envelope $envelope,
    ) {}

    /**
     * Stores (or replaces) a secret; a new data key every time.
     */
    public function put(IngressCredentialPurpose $purpose, #[SensitiveParameter] string $value): IngressCredential
    {
        $dek = $this->envelope->generateDataKey();
        $row = IngressCredential::query()->firstOrNew(['purpose' => $purpose->value]);
        $row->forceFill([
            'purpose' => $purpose,
            'wrapped_dek' => $this->envelope->wrap($dek, $this->wrapContext($purpose)),
            'kek_id' => $this->envelope->kekId(),
            'ciphertext' => $dek->encrypt($value, $this->associatedData($purpose)),
        ])->save();

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

        return $this->envelope->unwrap($row->wrapped_dek, $this->wrapContext($purpose))
            ->decrypt($row->ciphertext, $this->associatedData($purpose));
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

        $dek = $this->envelope->generateDataKey();

        try {
            DB::transaction(fn () => IngressCredential::query()->create([
                'purpose' => $purpose,
                'wrapped_dek' => $this->envelope->wrap($dek, $this->wrapContext($purpose)),
                'kek_id' => $this->envelope->kekId(),
                'ciphertext' => $dek->encrypt($generate(), $this->associatedData($purpose)),
            ]));
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

    /**
     * Re-wraps the stored DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(IngressCredential $row, Envelope $from, Envelope $to): void
    {
        $row->wrapped_dek = $from->rewrap($row->wrapped_dek, $this->wrapContext($row->purpose), $to);
        $row->kek_id = $to->kekId();
    }

    private function wrapContext(IngressCredentialPurpose $purpose): string
    {
        return 'ingress_credential:'.$purpose->value;
    }

    private function associatedData(IngressCredentialPurpose $purpose): string
    {
        return 'ingress_credential|'.$purpose->value;
    }
}

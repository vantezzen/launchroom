<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Enums\Dockerfiles\AiProvider;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use SensitiveParameter;

/**
 * Envelope encryption of the model provider's API key (docs/09 R13,
 * docs/20 §1): its own data key, wrapped by the KEK with context
 * `ai_provider:<provider>`; the key is encrypted with associated data
 * `ai_provider|<provider>`. The plaintext only exists while a request to the
 * provider is made; it is never logged, serialized or sent to the browser.
 */
final readonly class AiProviderSecrets
{
    public function __construct(
        private Envelope $envelope,
    ) {}

    /**
     * Sets the key on the (locked) row; the caller saves.
     */
    public function put(AiProviderSettings $settings, #[SensitiveParameter] string $apiKey): void
    {
        $dek = $this->envelope->generateDataKey();
        $settings->forceFill([
            'wrapped_dek' => $this->envelope->wrap($dek, $this->wrapContext($settings->provider)),
            'kek_id' => $this->envelope->kekId(),
            'api_key_ciphertext' => $dek->encrypt($apiKey, $this->associatedData($settings->provider)),
            'api_key_last4' => substr($apiKey, -4),
            'key_set_at' => now(),
        ]);
    }

    public function forget(AiProviderSettings $settings): void
    {
        $settings->forceFill([
            'wrapped_dek' => null,
            'kek_id' => null,
            'api_key_ciphertext' => null,
            'api_key_last4' => null,
            'key_set_at' => null,
        ]);
    }

    /**
     * @throws DecryptionFailed
     */
    public function get(AiProviderSettings $settings): ?string
    {
        if ($settings->api_key_ciphertext === null || $settings->wrapped_dek === null) {
            return null;
        }

        return $this->envelope->unwrap($settings->wrapped_dek, $this->wrapContext($settings->provider))
            ->decrypt($settings->api_key_ciphertext, $this->associatedData($settings->provider));
    }

    /**
     * Re-wraps the stored DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(AiProviderSettings $settings, Envelope $from, Envelope $to): void
    {
        if ($settings->wrapped_dek === null) {
            return;
        }

        $settings->wrapped_dek = $from->rewrap($settings->wrapped_dek, $this->wrapContext($settings->provider), $to);
        $settings->kek_id = $to->kekId();
    }

    private function wrapContext(AiProvider $provider): string
    {
        return 'ai_provider:'.$provider->value;
    }

    private function associatedData(AiProvider $provider): string
    {
        return 'ai_provider|'.$provider->value;
    }
}

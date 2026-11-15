<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Exceptions\DecryptionFailed;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Services\Crypto\SealedColumns;
use SensitiveParameter;

/**
 * The model provider's API key (docs/20 §1), sealed under the settings row's
 * own data key. The plaintext only exists while a request to the provider is
 * made; it is never logged, serialized or sent to the browser.
 */
final readonly class AiProviderSecrets
{
    public function __construct(
        private SealedColumns $sealed,
    ) {}

    /**
     * Sets the key on the (locked) row; the caller saves.
     */
    public function put(AiProviderSettings $settings, #[SensitiveParameter] string $apiKey): void
    {
        $this->sealed->seal($settings, 'api_key_ciphertext', $apiKey, $this->sealed->newKey($settings));
        $settings->forceFill([
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
        if ($settings->wrapped_dek === null) {
            return null;
        }

        return $this->sealed->reveal($settings, 'api_key_ciphertext');
    }
}

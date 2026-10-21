<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use App\Models\Dockerfiles\AiProviderSettings;
use Spatie\LaravelData\Data;

/**
 * The instance's provider settings as the page shows them: never the key,
 * only its last four characters.
 */
final class AiProviderSettingsData extends Data
{
    /**
     * @param  list<string>  $suggestedModels
     */
    public function __construct(
        public bool $enabled,
        public string $provider,
        public string $providerLabel,
        public string $model,
        public string $defaultModel,
        public array $suggestedModels,
        public bool $hasKey,
        public ?string $keyLast4,
        public ?string $keySetAt,
        /** Development stub model: no key needed (docs/20 §8). */
        public bool $stub,
        public bool $canManage,
    ) {}

    /**
     * @param  list<string>  $suggested
     */
    public static function fromModel(AiProviderSettings $settings, bool $canManage, array $suggested, bool $stub): self
    {
        return new self(
            enabled: $settings->enabled,
            provider: $settings->provider->value,
            providerLabel: $settings->provider->label(),
            model: $settings->effectiveModel(),
            defaultModel: config()->string('launchroom.dockerfile_generation.default_model'),
            suggestedModels: $suggested,
            hasKey: $settings->hasKey(),
            keyLast4: $canManage ? $settings->api_key_last4 : null,
            keySetAt: $settings->key_set_at?->toIso8601ZuluString(),
            stub: $stub,
            canManage: $canManage,
        );
    }
}

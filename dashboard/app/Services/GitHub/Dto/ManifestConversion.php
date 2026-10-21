<?php

declare(strict_types=1);

namespace App\Services\GitHub\Dto;

use App\Services\GitHub\JsonObject;
use LogicException;
use SensitiveParameter;

/**
 * Result of `POST /app-manifests/{code}/conversions`: the new app and its
 * credentials. Never serializable, never printed (docs/09 R12, R16).
 */
final readonly class ManifestConversion
{
    public function __construct(
        public int $appId,
        public string $slug,
        public string $name,
        public string $clientId,
        public string $htmlUrl,
        public ?string $ownerLogin,
        #[SensitiveParameter] public string $clientSecret,
        #[SensitiveParameter] public ?string $webhookSecret,
        #[SensitiveParameter] public string $privateKey,
    ) {}

    public static function fromJson(JsonObject $json): self
    {
        return new self(
            appId: $json->int('id'),
            slug: $json->string('slug'),
            name: $json->string('name'),
            clientId: $json->string('client_id'),
            htmlUrl: $json->string('html_url'),
            ownerLogin: $json->objectOrNull('owner')?->stringOrNull('login'),
            clientSecret: $json->string('client_secret'),
            webhookSecret: $json->stringOrNull('webhook_secret'),
            privateKey: $json->string('pem'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['appId' => $this->appId, 'slug' => $this->slug, 'secrets' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Manifest credentials cannot be serialized.');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Mesh;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * A freshly created pre-auth key. The key is a secret: never logged or
 * serialized; it goes straight into MeshSecrets.
 */
final readonly class HeadscalePreAuthKey
{
    public function __construct(
        public string $id,
        #[SensitiveParameter]
        public string $key,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'key' => '[redacted]'];
    }
}

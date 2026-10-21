<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

/**
 * NodeState.system.tailscale (docs/05 §2). `authKeySealed` is a MeshAuthKey
 * sealed to the server (protocol/schema/v1/mesh-auth-key.json); it is only
 * present until the server has joined. `authKeyFingerprint` identifies the
 * key without the randomized ciphertext (render deduplication).
 */
final class TailscaleSpec extends Data
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public bool $enabled,
        public ?string $image = null,
        public ?string $loginServer = null,
        public array $tags = [],
        public ?string $authKeySealed = null,
        public ?string $authKeyFingerprint = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        $spec = $this->base();

        if ($this->authKeySealed !== null) {
            $spec['authKey'] = ['sealed' => $this->authKeySealed];
        }

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    public function toFingerprint(): array
    {
        $spec = $this->base();

        if ($this->authKeySealed !== null) {
            $spec['authKey'] = ['fingerprint' => $this->authKeyFingerprint ?? ''];
        }

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private function base(): array
    {
        $spec = ['enabled' => $this->enabled];

        if ($this->image !== null) {
            $spec['image'] = $this->image;
        }

        if ($this->loginServer !== null) {
            $spec['loginServer'] = $this->loginServer;
        }

        if ($this->tags !== []) {
            $spec['tags'] = $this->tags;
        }

        return $spec;
    }
}

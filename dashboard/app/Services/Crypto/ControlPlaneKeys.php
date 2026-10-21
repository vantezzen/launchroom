<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Enums\SigningKeyStatus;
use App\Models\SigningKey;

/**
 * The key set daemons trust (protocol/SIGNING.md §3): every active signing key
 * plus the key currently in use.
 */
final readonly class ControlPlaneKeys
{
    public function __construct(
        private Signer $signer,
    ) {}

    /**
     * @return array<string, string> kid => "ed25519:<base64>"
     */
    public function trusted(): array
    {
        /** @var array<string, string> $keys */
        $keys = SigningKey::query()
            ->where('status', SigningKeyStatus::Active->value)
            ->orderBy('id')
            ->pluck('public_key', 'kid')
            ->all();

        $keys[$this->signer->kid] = $this->signer->publicKey();

        return $keys;
    }

    /**
     * Every key this control plane has signed with, retired ones included:
     * audit checkpoints (docs/09 R34) are history and keep verifying after a
     * key rotation.
     *
     * @return array<string, string> kid => "ed25519:<base64>"
     */
    public function historical(): array
    {
        /** @var array<string, string> $keys */
        $keys = SigningKey::query()->orderBy('id')->pluck('public_key', 'kid')->all();

        $keys[$this->signer->kid] = $this->signer->publicKey();

        return $keys;
    }
}

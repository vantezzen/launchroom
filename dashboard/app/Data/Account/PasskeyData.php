<?php

declare(strict_types=1);

namespace App\Data\Account;

use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Spatie\LaravelData\Data;

final class PasskeyData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $authenticator,
        public ?string $addedAgo,
        public ?string $lastUsedAgo,
        /** Registered for an earlier dashboard address (docs/05 §4.1): it can't sign in here. */
        public ?string $otherAddress,
    ) {}

    public static function fromPasskey(Passkey $passkey): self
    {
        $rpId = $passkey->getAttribute('rp_id');

        return new self(
            id: $passkey->id,
            name: $passkey->name,
            authenticator: $passkey->authenticator,
            addedAgo: $passkey->created_at?->diffForHumans(),
            lastUsedAgo: $passkey->last_used_at?->diffForHumans(),
            otherAddress: is_string($rpId) && $rpId !== Passkeys::relyingPartyId() ? $rpId : null,
        );
    }
}

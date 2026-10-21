<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * What switching to the new domain changes for people (shown before "Switch").
 */
final class DashboardSwitchImpactData extends Data
{
    public function __construct(
        /** The passkey relying party after the switch (null: none, IP). */
        public ?string $rpId,
        /** Existing passkeys keep working (the new host is under the current relying party). */
        public bool $keepsPasskeys,
        /** Passkeys of everyone that stop working there. */
        public int $passkeysAffected,
        /** The signed-in owner's own. */
        public int $yourPasskeysAffected,
    ) {}
}

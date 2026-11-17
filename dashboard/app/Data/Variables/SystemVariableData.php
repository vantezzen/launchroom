<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * A variable launchroom injects (docs/06 §4.1 "System variables"); read-only.
 */
final class SystemVariableData extends Data
{
    public function __construct(
        public string $key,
        public string $value,
        /** Where the value comes from, for framework aliases: "Set from PUBLIC_URL for Laravel". */
        public ?string $note = null,
        /** A variable of the user's with the same name replaces it. */
        public bool $overridden = false,
        /** The running deployment has it, the next deploy won't (HOST, OD12). */
        public bool $removedNextDeploy = false,
    ) {}
}

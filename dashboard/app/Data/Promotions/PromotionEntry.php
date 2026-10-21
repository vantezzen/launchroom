<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use App\Enums\ChangeEffect;
use App\Enums\PromotionClass;
use App\Enums\PromotionEntryType;
use Spatie\LaravelData\Data;

/**
 * One promotable unit of an environment's intent (docs/06 §6.2), keyed in a
 * PromotionSnapshot by its logical path. `fingerprint` is what the three-way
 * diff compares; variable values only enter it as a keyed MAC (never the
 * value), generated values not at all (they are never promoted).
 * `display` is a readable value for settings and config variables; variable
 * displays are dropped before a snapshot is stored.
 */
final class PromotionEntry extends Data
{
    /**
     * @param  list<string>  $dependsOn  paths that must exist in the target first
     */
    public function __construct(
        public PromotionEntryType $type,
        public string $fingerprint,
        public string $label,
        public PromotionClass $promotion,
        public ChangeEffect $effect,
        public ?string $serviceId = null,
        /** Field name (`deploy.port`), variable key, mount path, connection id. */
        public ?string $key = null,
        public ?string $display = null,
        public bool $secret = false,
        public bool $generated = false,
        /** Generator of a generated value (`secret(32)`), so a copy can get a fresh one. */
        public ?string $generator = null,
        /** Fingerprint of the value alone (variables), to tell value from metadata changes. */
        public ?string $valueFingerprint = null,
        /** Services: holds data (stateful kind or volumes). */
        public bool $holdsData = false,
        public array $dependsOn = [],
    ) {}
}

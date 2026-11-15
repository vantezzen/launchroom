<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * `control_plane_moves.handover`: the hand-over manifest the new machine
 * fetches (`launchroom-move/1`, docs/14 §13.4). `sealedKey` is sealed to the new
 * machine's key; nothing here decrypts the dump on this side.
 */
final class HandoverManifestData extends Data
{
    public function __construct(
        public string $kind,
        public string $move,
        public string $createdAt,
        public string $appUrl,
        public ?string $canonicalUrl,
        public HandoverDatabaseData $database,
        public string $sealedKey,
        public HandoverKeysData $keys,
    ) {}
}

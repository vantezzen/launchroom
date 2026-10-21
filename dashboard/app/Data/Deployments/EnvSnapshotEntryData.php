<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * A variable in a deployment's env snapshot: name and a keyed hash of the
 * value (docs/06 §3.5: "the UI shows names plus hashes"), never the value.
 */
final class EnvSnapshotEntryData extends Data
{
    public function __construct(
        public string $name,
        public string $hash,
    ) {}
}

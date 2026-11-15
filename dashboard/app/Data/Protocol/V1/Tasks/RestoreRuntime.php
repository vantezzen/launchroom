<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\Resources;
use App\Data\Protocol\V1\Sealed;
use Spatie\LaravelData\Data;

/**
 * How Postgres runs for a point-in-time replay (protocol RestoreRuntime,
 * docs/14 §10.3). `env` (POSTGRES_USER, POSTGRES_PASSWORD, POSTGRES_DB of the
 * target) is sealed at lease time.
 */
final class RestoreRuntime extends Data
{
    public function __construct(
        public string $image,
        public string $mountPath,
        public string $dataDirectory,
        public Resources $resources,
        public ?string $archiveDirectory = null,
        public ?Sealed $env = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Settings;

use App\Data\Servers\ServerMeshData;
use Spatie\LaravelData\Data;

/**
 * A server on Settings → Network: its private network state and how it
 * reaches the dashboard's server.
 */
final class NetworkMemberData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isControlPlane,
        public bool $online,
        public ServerMeshData $mesh,
    ) {}
}

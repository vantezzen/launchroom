<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use Spatie\LaravelData\Data;

final class ServerResource extends Data
{
    /**
     * @param  list<ServerRole>  $roles
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $id,
        public string $name,
        public ServerStatus $status,
        public array $roles,
        public bool $isControlPlane,
        public ?string $publicIpv4,
        public ?string $publicIpv6,
        public ?string $meshIp,
        public ?string $daemonVersion,
        public ?string $lastSeenAt,
        public array $labels,
    ) {}
}

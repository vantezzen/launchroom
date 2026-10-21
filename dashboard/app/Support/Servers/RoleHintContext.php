<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Enums\DeviceKind;
use App\Enums\ServerRole;

/**
 * What RoleHints looks at: the machine, the roles picked for it, its size
 * when known, and the rest of the team's fleet. Plain values, so the rules
 * stay a pure function.
 */
final readonly class RoleHintContext
{
    /**
     * @param  list<ServerRole>  $roles  the roles picked (wizard) or current (server page)
     * @param  bool  $existing  a connected server (its facts are real), not the add-server wizard
     * @param  ?string  $arch  amd64 or arm64, when known
     * @param  int  $otherServers  other approved servers of the team
     * @param  int  $otherEdges  other approved servers with the edge role
     * @param  list<string>  $otherRuntimeArchs  architectures of the other runtime servers
     * @param  list<string>  $otherBuildArchs  architectures of the other build servers
     */
    public function __construct(
        public DeviceKind $device,
        public array $roles,
        public bool $existing = false,
        public ?int $cpus = null,
        public ?int $memoryMb = null,
        public ?string $arch = null,
        public bool $battery = false,
        public int $otherServers = 0,
        public int $otherEdges = 0,
        public array $otherRuntimeArchs = [],
        public array $otherBuildArchs = [],
    ) {}

    public function has(ServerRole $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** amd64/arm64 from what launchroomd or uname report; null when unknown. */
    public static function normalizeArch(?string $arch): ?string
    {
        return match (strtolower((string) $arch)) {
            'amd64', 'x86_64', 'x64' => 'amd64',
            'arm64', 'aarch64', 'armv8' => 'arm64',
            default => null,
        };
    }
}

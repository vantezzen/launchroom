<?php

declare(strict_types=1);

namespace App\Scheduling;

use App\Enums\ServerRole;

/**
 * A server as the scheduler sees it. `allocated*` are the limits of the
 * workloads already placed there, excluding the replica set being placed
 * (the scheduler adds `PlacementRequest::$existingServerIds` itself).
 */
final readonly class SchedulableServer
{
    /**
     * @param  list<ServerRole>  $roles
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public int $id,
        public array $roles,
        public array $labels = [],
        public bool $online = true,
        /** Total memory; null when unknown (no memory check). */
        public ?int $memoryMb = null,
        public int $allocatedMemoryMb = 0,
        /** Cores; null when unknown (no CPU check). */
        public ?int $cpus = null,
        public float $allocatedCpus = 0.0,
        /** Server setting "Allow previews" (docs/06 §5). */
        public bool $allowPreviews = true,
        /** Server setting "Allow production"; off = excluded from production environments. */
        public bool $allowProduction = true,
        /** `linux/amd64` / `linux/arm64`; null when unknown (matches anything). */
        public ?string $platform = null,
        /** Memory kept free for the system (launchroomd, Caddy, the control plane's own stack). */
        public int $reservedMemoryMb = 0,
        /** Display name for messages. */
        public string $name = '',
        /**
         * Being moved away from (the source of an open server move, docs/14
         * §13.2): only services still pinned here by their volumes may stay.
         */
        public bool $draining = false,
    ) {}

    public function hasRole(ServerRole $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * @param  array<string, string>  $required
     */
    public function hasLabels(array $required): bool
    {
        return array_all($required, fn (string $value, string|int $key): bool => ($this->labels[(string) $key] ?? null) === $value);
    }

    /**
     * Failure domain for anti-affinity: the `zone` label, else `provider`,
     * else the server itself.
     */
    public function failureDomain(): string
    {
        $zone = $this->labels['zone'] ?? null;

        if (is_string($zone) && $zone !== '') {
            return 'zone:'.$zone;
        }

        $provider = $this->labels['provider'] ?? null;

        return is_string($provider) && $provider !== '' ? 'provider:'.$provider : 'server:'.$this->id;
    }

    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : "server {$this->id}";
    }
}

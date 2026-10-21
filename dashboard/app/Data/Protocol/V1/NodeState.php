<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use App\Data\Protocol\ProtocolJson;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The signed desired state of one server (protocol/schema/v1/node-state.json).
 * Ambassadors and the mesh allow-list come from App\Services\NodeStates\MeshLinks
 * (docs/05 §3); both are empty without the mesh.
 */
final class NodeState extends Data implements ProtocolDocument
{
    /**
     * @param  list<NetworkSpec>  $networks
     * @param  list<VolumeSpec>  $volumes
     * @param  list<WorkloadSpec>  $workloads
     * @param  list<AmbassadorSpec>  $ambassadors
     * @param  list<RouteSpec>  $routes
     * @param  list<MeshAllowRule>  $meshAllow
     * @param  list<VolumeDeletion>  $deleteVolumes
     */
    public function __construct(
        public string $serverId,
        public int $version,
        public CarbonImmutable $issuedAt,
        public SystemSpec $system,
        public array $networks = [],
        public array $volumes = [],
        public array $workloads = [],
        public array $ambassadors = [],
        public array $routes = [],
        public array $meshAllow = [],
        public array $deleteVolumes = [],
        public ?IngressSpec $ingress = null,
    ) {}

    public function toProtocol(): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'NodeState',
            'serverId' => $this->serverId,
            'version' => $this->version,
            'issuedAt' => ProtocolJson::time($this->issuedAt),
            ...$this->content(),
        ];
    }

    /**
     * Everything except the envelope, version and issue time.
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return $this->body(
            array_map(static fn (WorkloadSpec $workload): array => $workload->toProtocol(), $this->workloads),
            $this->system->toProtocol(),
            $this->ingress?->toProtocol(),
            array_map(static fn (RouteSpec $route): array => $route->toProtocol(), $this->routes),
        );
    }

    /**
     * The content with sealed envs replaced by their stable fingerprints:
     * two renders with equal fingerprint content need no new version
     * (sealing is randomized).
     *
     * @return array<string, mixed>
     */
    public function fingerprintContent(): array
    {
        return $this->body(
            array_map(static fn (WorkloadSpec $workload): array => $workload->toFingerprint(), $this->workloads),
            $this->system->toFingerprint(),
            $this->ingress?->toFingerprint(),
            array_map(static fn (RouteSpec $route): array => $route->toFingerprint(), $this->routes),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $workloads
     * @param  array<string, mixed>  $system
     * @param  array<string, mixed>|null  $ingress
     * @param  list<array<string, mixed>>  $routes
     * @return array<string, mixed>
     */
    private function body(array $workloads, array $system, ?array $ingress, array $routes): array
    {
        $body = [
            'system' => $system,
            'networks' => array_map(static fn (NetworkSpec $network): array => $network->toProtocol(), $this->networks),
            'volumes' => array_map(static fn (VolumeSpec $volume): array => $volume->toProtocol(), $this->volumes),
            'workloads' => $workloads,
            'ambassadors' => array_map(static fn (AmbassadorSpec $ambassador): array => $ambassador->toProtocol(), $this->ambassadors),
            'routes' => $routes,
            'firewall' => ['meshAllow' => array_map(static fn (MeshAllowRule $rule): array => $rule->toProtocol(), $this->meshAllow)],
        ];

        // Optional (strict decoding: only sent when there is something to delete).
        if ($this->deleteVolumes !== []) {
            $body['deleteVolumes'] = array_map(static fn (VolumeDeletion $deletion): array => $deletion->toProtocol(), $this->deleteVolumes);
        }

        // Edge servers only, and only when there is something to configure (docs/05 §5).
        if ($ingress !== null && $ingress !== []) {
            $body['ingress'] = $ingress;
        }

        return $body;
    }
}

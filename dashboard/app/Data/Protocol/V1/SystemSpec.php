<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

final class SystemSpec extends Data
{
    /**
     * @param  list<string>  $certStorageAllowFrom
     * @param  list<array{serverId: string, ipv4: string, signingKey: string}>|null  $linkProbePeers  null = link probes off (docs/05 §2.1)
     */
    public function __construct(
        public SystemContainer $caddy,
        public SystemContainer $buildkit,
        public TailscaleSpec $tailscale,
        /** Mesh address of Zot; null on the control-plane host. */
        public ?string $registryForwardTo = null,
        /** Control-plane host with the mesh on: serve the registry on the mesh IP (docs/05 §6). */
        public bool $registryServeOnMesh = false,
        /** Control-plane host with automatic shared certificate storage: serve Valkey on the mesh IP (docs/05 §5). */
        public bool $certStorageServeOnMesh = false,
        /** Mesh IPs of the edges allowed to reach it. */
        public array $certStorageAllowFrom = [],
        /** Peers to measure and answer (`system.linkProbe`); only for daemons with the `linkProbe` feature. */
        public ?array $linkProbePeers = null,
        /** The dashboard's canonical origin (`system.controlPlane.url`, docs/05 §4.1); only for daemons with the `controlPlaneUrl` feature. */
        public ?string $controlPlaneUrl = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return $this->render($this->tailscale->toProtocol());
    }

    /**
     * Like toProtocol, with the sealed mesh key replaced by its stable
     * fingerprint (sealing is randomized).
     *
     * @return array<string, mixed>
     */
    public function toFingerprint(): array
    {
        return $this->render($this->tailscale->toFingerprint());
    }

    /**
     * @param  array<string, mixed>  $tailscale
     * @return array<string, mixed>
     */
    private function render(array $tailscale): array
    {
        $registry = array_filter(['forwardTo' => $this->registryForwardTo], is_string(...));

        if ($this->registryServeOnMesh) {
            $registry['serveOnMesh'] = true;
        }

        $system = [
            'caddy' => $this->caddy->toProtocol(),
            'buildkit' => $this->buildkit->toProtocol(),
            'tailscale' => $tailscale,
            // A map: `{}` when empty, never `[]` (protocol/README.md).
            'registry' => (object) $registry,
        ];

        // Optional (strict decoding): only while the control plane serves it.
        if ($this->certStorageServeOnMesh) {
            $system['certStorage'] = array_filter(['serveOnMesh' => true, 'allowFrom' => $this->certStorageAllowFrom], static fn (mixed $value): bool => $value !== []);
        }

        if ($this->linkProbePeers !== null) {
            $system['linkProbe'] = $this->linkProbePeers === [] ? ['enabled' => true] : ['enabled' => true, 'peers' => $this->linkProbePeers];
        }

        if ($this->controlPlaneUrl !== null) {
            $system['controlPlane'] = ['url' => $this->controlPlaneUrl];
        }

        return $system;
    }
}

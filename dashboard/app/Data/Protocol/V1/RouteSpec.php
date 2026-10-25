<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\Wire;
use Spatie\LaravelData\Data;

/**
 * NodeState `routes[]` (edge servers only): hosts → upstreams that reference
 * workloads of the same NodeState (`{workload, port}`) or mesh addresses.
 * `tls`: `acme` | `on_demand` | `internal` | `wildcard` (DNS-01, docs/05 §5).
 * `clientAuth` (the dashboard's system routes only, daemons with the
 * `clientCertificates` feature): client certificates for /daemon/v1 (mTLS,
 * docs/03 §4.3); the edge secret is sealed, so renders compare its
 * fingerprint. `waf` (edges with the `waf` feature, docs/03 §13.3): the
 * web firewall's typed settings; omitted means off.
 */
final class RouteSpec extends Data
{
    /**
     * @param  list<string>  $hosts
     * @param  list<array{workload: string, port: int}|array{address: string}>  $upstreams
     * @param  array<string, string>|null  $headers  values must not contain { or }
     * @param  array{forwardAuth: array{url: string, stripHeaders?: list<string>}}|null  $protection  preview protection (forward auth to the dashboard, docs/06 §5)
     * @param  array{mode: string, caCertificates: list<string>, edgeSecret: array{sealed: string, fingerprint: string}}|null  $clientAuth
     * @param  array{mode: string, paranoia?: int, inspectBodyKiB?: int, exclusions?: list<array{rule: int, path?: string, target?: string}>}|null  $waf
     */
    public function __construct(
        public string $id,
        public array $hosts,
        public string $tls,
        public array $upstreams,
        public ?string $redirectWwwTo = null,
        public ?array $headers = null,
        public ?array $protection = null,
        /** `round_robin` (default when null) or `prefer_local` (docs/05 §4). */
        public ?string $loadBalancing = null,
        public ?array $clientAuth = null,
        public ?array $waf = null,
    ) {}

    public static function fromProtocol(object $wire): self
    {
        $upstreams = array_map(static fn (object $upstream): array => Wire::nullableString($upstream, 'address') !== null
            ? ['address' => Wire::string($upstream, 'address')]
            : ['workload' => Wire::string($upstream, 'workload'), 'port' => Wire::int($upstream, 'port')], Wire::objects($wire, 'upstreams'));

        $protection = null;
        $forwardAuth = isset($wire->protection) && is_object($wire->protection) && isset($wire->protection->forwardAuth) && is_object($wire->protection->forwardAuth)
            ? $wire->protection->forwardAuth
            : null;

        if ($forwardAuth !== null) {
            $strip = Wire::strings($forwardAuth, 'stripHeaders');
            $protection = ['forwardAuth' => ['url' => Wire::string($forwardAuth, 'url'), ...($strip === null ? [] : ['stripHeaders' => $strip])]];
        }

        $clientAuth = null;

        if (isset($wire->clientAuth) && is_object($wire->clientAuth) && isset($wire->clientAuth->edgeSecret) && is_object($wire->clientAuth->edgeSecret)) {
            $sealed = Wire::string($wire->clientAuth->edgeSecret, 'sealed');
            $clientAuth = [
                'mode' => Wire::string($wire->clientAuth, 'mode'),
                'caCertificates' => Wire::strings($wire->clientAuth, 'caCertificates') ?? [],
                'edgeSecret' => ['sealed' => $sealed, 'fingerprint' => hash('sha256', $sealed)],
            ];
        }

        $waf = null;
        $wafWire = Wire::object($wire, 'waf');

        if ($wafWire !== null) {
            $exclusions = array_map(static fn (object $x): array => array_filter([
                'rule' => Wire::int($x, 'rule'),
                'path' => Wire::nullableString($x, 'path'),
                'target' => Wire::nullableString($x, 'target'),
            ], static fn (mixed $value): bool => $value !== null), Wire::objects($wafWire, 'exclusions'));
            $paranoia = Wire::nullableInt($wafWire, 'paranoia');
            $body = Wire::nullableInt($wafWire, 'inspectBodyKiB');
            $waf = [
                'mode' => Wire::string($wafWire, 'mode'),
                ...($paranoia === null ? [] : ['paranoia' => $paranoia]),
                ...($body === null ? [] : ['inspectBodyKiB' => $body]),
                ...($exclusions === [] ? [] : ['exclusions' => $exclusions]),
            ];
        }

        return new self(
            Wire::string($wire, 'id'),
            Wire::strings($wire, 'hosts') ?? [],
            Wire::string($wire, 'tls'),
            $upstreams,
            Wire::nullableString($wire, 'redirectWwwTo'),
            Wire::stringMap($wire, 'headers'),
            $protection,
            Wire::nullableString($wire, 'loadBalancing'),
            $clientAuth,
            $waf,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return $this->wire(false);
    }

    /**
     * The wire shape with the sealed edge secret replaced by its fingerprint.
     *
     * @return array<string, mixed>
     */
    public function toFingerprint(): array
    {
        return $this->wire(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function wire(bool $fingerprint): array
    {
        $wire = ['id' => $this->id, 'hosts' => $this->hosts, 'tls' => $this->tls];

        if ($this->redirectWwwTo !== null) {
            $wire['redirectWwwTo'] = $this->redirectWwwTo;
        }

        $wire['upstreams'] = $this->upstreams;

        if ($this->headers !== null) {
            // A map: `{}` when empty (protocol/README.md).
            $wire['headers'] = (object) $this->headers;
        }

        if ($this->protection !== null) {
            $wire['protection'] = $this->protection;
        }

        if ($this->loadBalancing !== null) {
            $wire['loadBalancing'] = $this->loadBalancing;
        }

        if ($this->clientAuth !== null) {
            $secret = $this->clientAuth['edgeSecret'];
            $wire['clientAuth'] = [
                'mode' => $this->clientAuth['mode'],
                'caCertificates' => $this->clientAuth['caCertificates'],
                'edgeSecret' => $fingerprint ? ['fingerprint' => $secret['fingerprint']] : ['sealed' => $secret['sealed']],
            ];
        }

        if ($this->waf !== null) {
            $wire['waf'] = $this->waf;
        }

        return $wire;
    }
}

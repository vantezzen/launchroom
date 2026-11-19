<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

/**
 * NodeState `ingress` (edge servers, docs/05 §4–5): shared certificate
 * storage and the DNS-01 provider, each with credentials sealed to this
 * server, and the edge's request trace key (docs/23 §4, feature
 * `requestTrace`). Fingerprints stand in for the (randomized) ciphertexts
 * when renders are compared.
 */
final class IngressSpec extends Data
{
    /**
     * @param  array{address: string, keyPrefix: string, sealed: string, fingerprint: string}|null  $storage
     * @param  array{provider: string, domains: list<string>, sealed: string, fingerprint: string}|null  $dns
     * @param  array{sealed: string, fingerprint: string}|null  $trace
     */
    public function __construct(
        public ?array $storage = null,
        public ?array $dns = null,
        public ?array $trace = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->storage === null && $this->dns === null && $this->trace === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return $this->body(static fn (array $part): array => ['sealed' => $part['sealed']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toFingerprint(): array
    {
        return $this->body(static fn (array $part): array => ['fingerprint' => $part['fingerprint']]);
    }

    /**
     * @param  callable(array{sealed: string, fingerprint: string}): array<string, string>  $credentials
     * @return array<string, mixed>
     */
    private function body(callable $credentials): array
    {
        $wire = [];

        if ($this->storage !== null) {
            $wire['storage'] = [
                'address' => $this->storage['address'],
                'keyPrefix' => $this->storage['keyPrefix'],
                'credentials' => $credentials($this->storage),
            ];
        }

        if ($this->dns !== null) {
            $wire['dns'] = [
                'provider' => $this->dns['provider'],
                'domains' => $this->dns['domains'],
                'credentials' => $credentials($this->dns),
            ];
        }

        if ($this->trace !== null) {
            $wire['trace'] = $credentials($this->trace);
        }

        return $wire;
    }
}

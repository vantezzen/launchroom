<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Support\Cron\CronGrammar;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Checks a rendered NodeState before it is signed and stored (docs/03 §5,
 * protocol/README.md "The dashboard's renderer must enforce the same
 * rules"). One invalid document is refused whole by launchroomd, which then
 * keeps converging to its previous state: everything on that server stalls.
 * So the dashboard never publishes one.
 *
 * Two layers:
 * - the Go-generated JSON Schema (grammar, ranges, required fields);
 * - a port of the Go `NodeState.Validate()` cross-reference rules the schema
 *   can't express (daemon/internal/state/nodestate.go): unique ids, names,
 *   subnets, hosts and mesh ports; references to declared networks, volumes
 *   and workloads; upstream and protection rules; drain/health/publish
 *   pairing; clean mount targets; the policy floor's name prefixes.
 *
 * Problems are "path: message" strings in the Go validator's path syntax
 * (`workloads[2].networks[0].network: …`), at most 25 like the daemon.
 */
final class NodeStateValidator
{
    private const int MAX_PROBLEMS = 25;

    private const array FORBIDDEN_MOUNT_TARGETS = ['/proc', '/sys', '/dev', '/var/run/docker.sock', '/run/docker.sock'];

    private static ?Validator $schema = null;

    /** @var list<string> */
    private array $problems = [];

    /**
     * @return list<string> problems; [] when the document is valid
     */
    public function problems(string $json): array
    {
        $this->problems = [];

        try {
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['document: not valid JSON: '.$e->getMessage()];
        }

        $this->schemaProblems($object);

        if (is_array($document)) {
            $this->semanticProblems($document);
        }

        return array_values(array_unique(array_slice($this->problems, 0, self::MAX_PROBLEMS)));
    }

    private function schemaProblems(mixed $object): void
    {
        $result = $this->schemaValidator()->validate($object, 'https://launchroom.vantezzen.io/schema/v1/node-state.json');
        $error = $result->error();

        if (! $error instanceof ValidationError) {
            return;
        }

        foreach ((new ErrorFormatter)->format($error) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $this->add($this->pointerToPath((string) $pointer), is_string($message) ? $message : 'is invalid');
            }
        }
    }

    private function schemaValidator(): Validator
    {
        if (! self::$schema instanceof Validator) {
            $path = rtrim(config()->string('launchroom.protocol.schema_path'), '/').'/';

            if (! is_file($path.'node-state.json')) {
                // Fail closed: without the contract nothing is published.
                throw new RuntimeException("Protocol schemas not found at {$path} (launchroom.protocol.schema_path).");
            }

            $validator = new Validator;
            $validator->resolver()?->registerPrefix('https://launchroom.vantezzen.io/schema/v1/', $path);
            $validator->setMaxErrors(self::MAX_PROBLEMS);
            self::$schema = $validator;
        }

        return self::$schema;
    }

    /**
     * @param  array<array-key, mixed>  $doc
     */
    private function semanticProblems(array $doc): void
    {
        $networks = $this->list($doc['networks'] ?? []);
        $volumes = $this->list($doc['volumes'] ?? []);
        $workloads = $this->list($doc['workloads'] ?? []);
        $routes = $this->list($doc['routes'] ?? []);
        $ambassadors = $this->list($doc['ambassadors'] ?? []);
        $deletes = $this->list($doc['deleteVolumes'] ?? []);

        $netIds = [];
        $netNames = [];
        $subnets = [];

        foreach ($networks as $i => $network) {
            $id = $this->str($network['id'] ?? null);
            $name = $this->str($network['name'] ?? null);

            if (isset($netIds[$id])) {
                $this->add("networks[{$i}].id", "duplicate network id {$id}");
            }

            $netIds[$id] = true;
            $this->unique('networks.name', $i, $name, $netNames);
            $this->unique('networks.subnet', $i, $this->str($network['subnet'] ?? null), $subnets);

            if (! str_starts_with($name, 'lr-e_')) {
                $this->add("networks[{$i}].name", "network {$name} is not an environment network (lr-e_…)");
            }

            if (! $this->canonicalSubnet($this->str($network['subnet'] ?? null))) {
                $this->add("networks[{$i}].subnet", 'is not a canonical IPv4 prefix between /8 and /30');
            }
        }

        $volIds = [];
        $volNames = [];

        foreach ($volumes as $i => $volume) {
            $id = $this->str($volume['id'] ?? null);
            $name = $this->str($volume['name'] ?? null);

            if (isset($volIds[$id])) {
                $this->add("volumes[{$i}].id", "duplicate volume id {$id}");
            }

            $volIds[$id] = true;
            $this->unique('volumes.name', $i, $name, $volNames);

            if (! str_starts_with($name, 'lr-v_')) {
                $this->add("volumes[{$i}].name", "volume {$name} is not a workload volume (lr-v_…)");
            }
        }

        $wlIds = [];
        $meshPorts = [];
        $volumeOwners = [];

        foreach ($workloads as $i => $workload) {
            $this->workload($i, $workload, $netIds, $volIds, $volumeOwners);
            $this->unique('workloads.id', $i, $this->str($workload['id'] ?? null), $wlIds);

            $mesh = $workload['publishOnMesh'] ?? null;

            if (is_array($mesh)) {
                $this->unique('workloads.publishOnMesh.port', $i, $this->str($mesh['port'] ?? null), $meshPorts);
            }
        }

        $listeners = [];

        foreach ($ambassadors as $i => $ambassador) {
            $network = $this->str($ambassador['network'] ?? null);

            if (! isset($netIds[$network])) {
                $this->add("ambassadors[{$i}].network", "references unknown network {$network}");
            }

            $this->unique('ambassadors', $i, $network.'|'.$this->str($ambassador['alias'] ?? null).'|'.$this->str($ambassador['port'] ?? null), $listeners);
            $seenUpstreams = [];

            foreach ($this->strings($ambassador['upstreams'] ?? []) as $j => $upstream) {
                $this->unique("ambassadors[{$i}].upstreams", $j, $upstream, $seenUpstreams);
            }
        }

        $routeIds = [];
        $hosts = [];

        foreach ($routes as $i => $route) {
            $this->route($i, $route, $wlIds);
            $this->unique('routes.id', $i, $this->str($route['id'] ?? null), $routeIds);

            foreach ($this->strings($route['hosts'] ?? []) as $host) {
                $this->unique('routes.hosts', $i, $host, $hosts);
            }
        }

        $certStorage = is_array($doc['system'] ?? null) && is_array($doc['system']['certStorage'] ?? null) ? $doc['system']['certStorage'] : null;

        if ($certStorage !== null) {
            $allowFrom = $this->strings($certStorage['allowFrom'] ?? []);

            if ($allowFrom !== [] && ($certStorage['serveOnMesh'] ?? false) !== true) {
                $this->add('system.certStorage.allowFrom', 'is only allowed with serveOnMesh');
            }

            $seenFrom = [];

            foreach ($allowFrom as $i => $ip) {
                $this->unique('system.certStorage.allowFrom', $i, $ip, $seenFrom);
            }
        }

        $linkProbe = is_array($doc['system'] ?? null) && is_array($doc['system']['linkProbe'] ?? null) ? $doc['system']['linkProbe'] : null;

        if ($linkProbe !== null) {
            $peers = $this->list($linkProbe['peers'] ?? []);

            if ($peers !== [] && ($linkProbe['enabled'] ?? false) !== true) {
                $this->add('system.linkProbe.peers', 'are only allowed when enabled');
            }

            $seenIds = [];
            $seenIps = [];

            foreach ($peers as $i => $peer) {
                $id = $this->str($peer['serverId'] ?? null);

                if ($id === $this->str($doc['serverId'] ?? null)) {
                    $this->add("system.linkProbe.peers[{$i}].serverId", 'must not be this server');
                }

                $this->unique('system.linkProbe.peers.serverId', $i, $id, $seenIds);
                $this->unique('system.linkProbe.peers.ipv4', $i, $this->str($peer['ipv4'] ?? null), $seenIps);
            }
        }

        $fwPorts = [];

        foreach ($this->list(is_array($doc['firewall'] ?? null) ? ($doc['firewall']['meshAllow'] ?? []) : []) as $i => $rule) {
            $this->unique('firewall.meshAllow.port', $i, $this->str($rule['port'] ?? null), $fwPorts);
            $seenFrom = [];

            foreach ($this->strings($rule['from'] ?? []) as $j => $ip) {
                $this->unique("firewall.meshAllow[{$i}].from", $j, $ip, $seenFrom);
            }
        }

        $deleteIds = [];
        $deleteNames = [];

        foreach ($deletes as $i => $delete) {
            $id = $this->str($delete['id'] ?? null);
            $name = $this->str($delete['name'] ?? null);

            if (isset($volIds[$id]) || isset($volNames[$name])) {
                $this->add("deleteVolumes[{$i}]", "volume {$id} is still declared in volumes");
            }

            if (! str_starts_with($name, 'lr-v_')) {
                $this->add("deleteVolumes[{$i}].name", "volume {$name} is not a workload volume (lr-v_…)");
            }

            $this->unique('deleteVolumes.id', $i, $id, $deleteIds);
            $this->unique('deleteVolumes.name', $i, $name, $deleteNames);
        }
    }

    /**
     * @param  array<array-key, mixed>  $w
     * @param  array<string, true>  $netIds
     * @param  array<string, true>  $volIds
     * @param  array<string, string>  $volumeOwners  volume id => "service|environment"
     */
    private function workload(int $i, array $w, array $netIds, array $volIds, array &$volumeOwners): void
    {
        $p = "workloads[{$i}]";
        $hasPort = isset($w['port']);

        if (isset($w['health']) && ! $hasPort) {
            $this->add("{$p}.health", 'requires port');
        }

        if (isset($w['publishOnMesh']) && ! $hasPort) {
            $this->add("{$p}.publishOnMesh", 'requires port');
        }

        if (is_array($w['health'] ?? null)) {
            $this->health("{$p}.health", $w['health']);
        }

        $attached = [];

        foreach ($this->list($w['networks'] ?? []) as $j => $attachment) {
            $network = $this->str($attachment['network'] ?? null);

            if (! isset($netIds[$network])) {
                $this->add("{$p}.networks[{$j}].network", "references unknown network {$network}");
            }

            $this->unique("{$p}.networks.network", $j, $network, $attached);
        }

        $targets = [];
        $owner = $this->str($w['service'] ?? null).'|'.$this->str($w['environment'] ?? null);

        foreach ($this->list($w['mounts'] ?? []) as $j => $mount) {
            $volume = $this->str($mount['volume'] ?? null);
            $target = $this->str($mount['target'] ?? null);

            if (! isset($volIds[$volume])) {
                $this->add("{$p}.mounts[{$j}].volume", "references unknown volume {$volume}");
            }

            if (isset($volumeOwners[$volume]) && $volumeOwners[$volume] !== $owner) {
                // launchroomd refuses it (policy floor): one volume, one service in one environment.
                $this->add("{$p}.mounts[{$j}].volume", "volume {$volume} is mounted by workloads of different services or environments");
            }

            $volumeOwners[$volume] ??= $owner;
            $this->unique("{$p}.mounts.target", $j, $target, $targets);

            if (in_array('.', explode('/', $target), true) || in_array('..', explode('/', $target), true)) {
                $this->add("{$p}.mounts[{$j}].target", "container path {$target} must be clean (no . or .. segments)");
            }

            foreach (self::FORBIDDEN_MOUNT_TARGETS as $forbidden) {
                if ($target === $forbidden || str_starts_with($target, $forbidden.'/')) {
                    $this->add("{$p}.mounts[{$j}].target", "container path {$target} is not allowed (inside {$forbidden})");
                }
            }
        }

        $role = $w['role'] ?? null;

        if ($role === 'draining' && ! isset($w['drainUntil'])) {
            $this->add("{$p}.drainUntil", 'is required when role is draining');
        }

        if ($role !== 'draining' && isset($w['drainUntil'])) {
            $this->add("{$p}.drainUntil", 'is only allowed when role is draining');
        }

        // Scheduled workloads (cron services): launchroomd's cron grammar and
        // zone, never restarted, nothing listening.
        if (is_array($w['schedule'] ?? null)) {
            $cron = is_string($w['schedule']['cron'] ?? null) ? $w['schedule']['cron'] : '';
            $zone = is_string($w['schedule']['timeZone'] ?? null) ? $w['schedule']['timeZone'] : '';

            if (($problem = CronGrammar::error($cron)) !== null) {
                $this->add("{$p}.schedule.cron", $problem);
            }

            if (CronGrammar::timeZoneError($zone) !== null) {
                $this->add("{$p}.schedule.timeZone", "unknown time zone \"{$zone}\"");
            }

            if (($w['restart'] ?? null) !== 'no') {
                $this->add("{$p}.schedule", 'requires restart "no"');
            } elseif (isset($w['port']) || isset($w['health']) || isset($w['publishOnMesh'])) {
                $this->add("{$p}.schedule", 'is only allowed without port, health and publishOnMesh');
            }
        }

        // Point-in-time recovery: the WAL archive directory lies in a writable mount (docs/14 §10.2).
        if (is_array($w['walArchive'] ?? null)) {
            $directory = is_string($w['walArchive']['directory'] ?? null) ? $w['walArchive']['directory'] : '';
            $inMount = false;

            foreach ($this->list($w['mounts'] ?? []) as $mount) {
                $target = is_string($mount['target'] ?? null) ? $mount['target'] : null;

                if ($target !== null && ($mount['readOnly'] ?? false) !== true && ($directory === $target || str_starts_with($directory, $target.'/'))) {
                    $inMount = true;
                }
            }

            if (! $inMount) {
                $this->add("{$p}.walArchive.directory", "must be inside one of the workload's writable mounts");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $health
     */
    private function health(string $p, array $health): void
    {
        $type = $health['type'] ?? null;

        if ($type === 'http' && ! isset($health['path'])) {
            $this->add("{$p}.path", 'is required for http probes');
        }

        if ($type === 'tcp' && (isset($health['path']) || isset($health['expectStatus']))) {
            $this->add($p, 'path and expectStatus are only valid for http probes');
        }

        $seconds = [];

        foreach (['startPeriod', 'interval', 'timeout'] as $name) {
            $seconds[$name] = $this->durationSeconds($this->str($health[$name] ?? null));

            if ($seconds[$name] !== null && $seconds[$name] > 3600) {
                $this->add("{$p}.{$name}", 'must be at most 1h');
            }
        }

        if (($seconds['timeout'] ?? 0) > 0 && ($seconds['interval'] ?? 0) > 0 && $seconds['timeout'] > $seconds['interval']) {
            $this->add("{$p}.timeout", 'must not exceed interval');
        }
    }

    /**
     * @param  array<array-key, mixed>  $route
     * @param  array<string, true>  $workloads
     */
    private function route(int $i, array $route, array $workloads): void
    {
        $p = "routes[{$i}]";
        $hosts = $this->strings($route['hosts'] ?? []);
        $redirect = $route['redirectWwwTo'] ?? null;

        if (is_string($redirect) && ! in_array($redirect, $hosts, true)) {
            $this->add("{$p}.redirectWwwTo", "must be one of the route's hosts");
        }

        $upstreams = [];

        foreach ($this->list($route['upstreams'] ?? []) as $j => $upstream) {
            $workload = $upstream['workload'] ?? null;
            $address = $upstream['address'] ?? null;

            if (is_string($workload) && is_string($address)) {
                $this->add("{$p}.upstreams[{$j}]", 'use either workload+port or address, not both');
            } elseif (is_string($workload) && ! isset($workloads[$workload])) {
                $this->add("{$p}.upstreams[{$j}].workload", "references unknown workload {$workload}");
            }

            $this->unique("{$p}.upstreams", $j, $this->str($workload).'|'.$this->str($upstream['port'] ?? null).'|'.$this->str($address), $upstreams);
        }

        foreach ((is_array($route['headers'] ?? null) ? $route['headers'] : []) as $name => $value) {
            if (is_string($value) && (str_contains($value, '{') || str_contains($value, '}'))) {
                $this->add("{$p}.headers[\"{$name}\"]", 'must not contain { or }');
            }
        }

        $protection = $route['protection'] ?? null;

        if (is_array($protection) && is_array($protection['forwardAuth'] ?? null)) {
            $strip = [];

            foreach ($this->strings($protection['forwardAuth']['stripHeaders'] ?? []) as $j => $header) {
                $this->unique("{$p}.protection.forwardAuth.stripHeaders", $j, $header, $strip);
            }
        }

        // Client certificates (mTLS, docs/03 §4.3): every trusted certificate must be a CA.
        $clientAuth = $route['clientAuth'] ?? null;

        if (is_array($clientAuth)) {
            $cas = [];

            foreach ($this->strings($clientAuth['caCertificates'] ?? []) as $j => $ca) {
                $this->unique("{$p}.clientAuth.caCertificates", $j, $ca, $cas);
                $der = base64_decode($ca, true);
                $parsed = $der === false ? false : openssl_x509_parse("-----BEGIN CERTIFICATE-----\n".chunk_split($ca, 64, "\n")."-----END CERTIFICATE-----\n");
                $constraints = is_array($parsed) && is_array($parsed['extensions'] ?? null) ? ($parsed['extensions']['basicConstraints'] ?? '') : null;

                if ($constraints === null) {
                    $this->add("{$p}.clientAuth.caCertificates[{$j}]", 'is not an X.509 certificate');
                } elseif (! is_string($constraints) || ! str_contains($constraints, 'CA:TRUE')) {
                    $this->add("{$p}.clientAuth.caCertificates[{$j}]", 'is not a CA certificate');
                }
            }
        }
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function unique(string $path, int $index, string $value, array &$seen): void
    {
        if (isset($seen[$value])) {
            $this->add("{$path}[{$index}]", "duplicate value {$value}");
        }

        $seen[$value] = true;
    }

    private function add(string $path, string $message): void
    {
        if (count($this->problems) < self::MAX_PROBLEMS) {
            $this->problems[] = "{$path}: {$message}";
        }
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_map(static fn (mixed $item): array => is_array($item) ? $item : [], $value);
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_map($this->str(...), $value);
    }

    private function str(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    private function canonicalSubnet(string $cidr): bool
    {
        if (preg_match('#^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})/(\d{1,2})$#', $cidr, $m) !== 1) {
            return false;
        }

        $bits = (int) $m[5];

        if ($bits < 8 || $bits > 30) {
            return false;
        }

        $octets = array_map(intval(...), array_slice($m, 1, 4));

        foreach ($octets as $octet) {
            if ($octet > 255) {
                return false;
            }
        }

        $address = ($octets[0] << 24) | ($octets[1] << 16) | ($octets[2] << 8) | $octets[3];
        $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

        return ($address & $mask) === $address;
    }

    private function durationSeconds(string $duration): ?float
    {
        if (preg_match('/^(\d+)(ms|s|m|h)$/', $duration, $m) !== 1) {
            return null;
        }

        return (float) $m[1] * match ($m[2]) {
            'ms' => 0.001,
            's' => 1,
            'm' => 60,
            default => 3600,
        };
    }

    /**
     * "/workloads/2/networks/0/network" → "workloads[2].networks[0].network".
     */
    private function pointerToPath(string $pointer): string
    {
        $path = '';

        foreach (array_filter(explode('/', $pointer), static fn (string $part): bool => $part !== '') as $part) {
            $path .= ctype_digit($part) ? "[{$part}]" : ($path === '' ? $part : ".{$part}");
        }

        return $path === '' ? 'document' : $path;
    }
}

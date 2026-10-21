<?php

declare(strict_types=1);

use App\Services\NodeStates\NodeStateValidator;
use Tests\Support\ProtocolSchemas;

/*
| The dashboard's port of the daemon's NodeState rules (docs/03 §5): every
| rendered document is checked before it is published, so launchroomd never
| has to refuse one (which stalls every workload on the server).
*/

function validNodeStateFixture(): array
{
    return json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/node-state.web-postgres.json'), true, flags: JSON_THROW_ON_ERROR);
}

function nodeStateProblems(array $document): array
{
    return resolve(NodeStateValidator::class)->problems(json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

test('the Go fixtures are valid', function (): void {
    foreach (['web-postgres', 'preview', 'delete-volumes'] as $name) {
        $json = (string) file_get_contents(ProtocolSchemas::root()."/fixtures/v1/node-state.{$name}.json");

        expect(resolve(NodeStateValidator::class)->problems($json))->toBe([], $name);
    }
});

test('cross-reference rules the schema cannot express are enforced', function (callable $mutate, string $problem): void {
    $doc = validNodeStateFixture();
    $mutate($doc);

    expect(implode("\n", nodeStateProblems($doc)))->toContain($problem);
})->with([
    // The previews bug: one network declared twice.
    'duplicate network' => [function (array &$d): void {
        $d['networks'][] = $d['networks'][0];
    }, 'networks[1].id: duplicate network id'],
    'duplicate subnet' => [function (array &$d): void {
        $d['networks'][] = [...$d['networks'][0], 'id' => 'net_01J9Z8X7W6V5T4S3R2Q1P0N9Z1', 'name' => 'lr-e_other'];
    }, 'networks.subnet[1]: duplicate value'],
    'non-canonical subnet' => [function (array &$d): void {
        $d['networks'][0]['subnet'] = '10.231.4.1/24';
    }, 'networks[0].subnet: is not a canonical IPv4 prefix'],
    'system network name' => [function (array &$d): void {
        $d['networks'][0]['name'] = 'lr-system-build';
    }, 'is not an environment network'],
    'unknown network' => [function (array &$d): void {
        $d['workloads'][0]['networks'][0]['network'] = 'net_01J9Z8X7W6V5T4S3R2Q1P0N9Z9';
    }, 'workloads[0].networks[0].network: references unknown network'],
    'unknown volume' => [function (array &$d): void {
        $d['volumes'] = [];
    }, 'references unknown volume'],
    'duplicate workload' => [function (array &$d): void {
        $d['workloads'][1]['id'] = $d['workloads'][0]['id'];
    }, 'workloads.id[1]: duplicate value'],
    'upstream to an unknown workload' => [function (array &$d): void {
        $d['routes'][0]['upstreams'][0]['workload'] = 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9Z9';
    }, 'references unknown workload'],
    'duplicate host' => [function (array &$d): void {
        $d['routes'][] = [...$d['routes'][0], 'id' => 'rt_01J9Z8X7W6V5T4S3R2Q1P0N9Z9'];
    }, 'routes.hosts[1]: duplicate value'],
    'redirect to a foreign host' => [function (array &$d): void {
        $d['routes'][0]['redirectWwwTo'] = 'elsewhere.example.com';
    }, "must be one of the route's hosts"],
    'drain without deadline' => [function (array &$d): void {
        $d['workloads'][0]['role'] = 'draining';
        unset($d['workloads'][0]['drainUntil']);
    }, 'drainUntil: is required when role is draining'],
    'health without port' => [function (array &$d): void {
        foreach ($d['workloads'] as $i => $w) {
            if (isset($w['health'])) {
                unset($d['workloads'][$i]['port'], $d['workloads'][$i]['publishOnMesh']);

                return;
            }
        }
    }, 'health: requires port'],
    'dot segment mount' => [function (array &$d): void {
        foreach ($d['workloads'] as $i => $w) {
            if (isset($w['mounts'])) {
                $d['workloads'][$i]['mounts'][0]['target'] = '/data/../etc';

                return;
            }
        }
    }, 'must be clean'],
    'deleting a declared volume' => [function (array &$d): void {
        $d['deleteVolumes'] = [['id' => $d['volumes'][0]['id'], 'name' => $d['volumes'][0]['name'], 'service' => 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2', 'environment' => 'env_01J9Z8X7W6V5T4S3R2Q1P0N9N4', 'requestedAt' => '2026-10-17T12:00:00Z']];
    }, 'is still declared in volumes'],
    // Grammar comes from the Go-generated schema.
    'schema: bad id' => [function (array &$d): void {
        $d['workloads'][0]['id'] = 'wl_nope';
    }, 'workloads[0].id'],
    'schema: unknown member' => [function (array &$d): void {
        $d['workloads'][0]['privileged'] = true;
    }, 'workloads[0]'],
]);

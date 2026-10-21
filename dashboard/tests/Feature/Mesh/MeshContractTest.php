<?php

declare(strict_types=1);

use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\NodeState;
use App\Data\Protocol\V1\Report;
use App\Data\Protocol\V1\SystemContainer;
use App\Data\Protocol\V1\SystemSpec;
use App\Data\Protocol\V1\TailscaleSpec;
use App\Support\Servers\MeshStatusFacts;
use Carbon\CarbonImmutable;
use Tests\Support\ProtocolSchemas;

/*
| Mesh contract (docs/05 §2): PHP-rendered mesh sections validate against
| the Go-generated schemas; Go's mesh report fixture decodes.
*/

test('rendered mesh NodeStates are schema-valid', function (array $system): void {
    $state = new NodeState('srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK', 3, CarbonImmutable::parse('2026-10-20T12:00:00Z'), $system[0]);

    expect(ProtocolSchemas::errors('node-state', ProtocolJson::encode($state)))->toBe([]);
})->with([
    'remote server with a key' => [[new SystemSpec(new SystemContainer(false), new SystemContainer(false),
        new TailscaleSpec(true, 'docker.io/tailscale/tailscale@sha256:'.str_repeat('a', 64), 'https://dash.example.com', [], base64_encode(random_bytes(96)), 'fp'),
        '100.64.0.1:5000')]],
    'control plane serving the registry' => [[new SystemSpec(new SystemContainer(false), new SystemContainer(false),
        new TailscaleSpec(true, 'docker.io/tailscale/tailscale@sha256:'.str_repeat('a', 64), 'https://dash.example.com'),
        null, true)]],
    'own tailnet with tags' => [[new SystemSpec(new SystemContainer(false), new SystemContainer(false),
        new TailscaleSpec(true, 'docker.io/tailscale/tailscale@sha256:'.str_repeat('a', 64), 'https://controlplane.tailscale.com', ['tag:lr-cp', 'tag:lr-edge']))]],
]);

test('the fingerprint form hides the sealed key but changes with it', function (): void {
    $spec = fn (string $fp): TailscaleSpec => new TailscaleSpec(true, 'img', 'https://x.example', [], 'c2VhbGVk', $fp);

    expect($spec('a')->toFingerprint())->toBe(['enabled' => true, 'image' => 'img', 'loginServer' => 'https://x.example', 'authKey' => ['fingerprint' => 'a']])
        ->and($spec('a')->toFingerprint())->not->toBe($spec('b')->toFingerprint())
        ->and($spec('a')->toProtocol()['authKey'])->toBe(['sealed' => 'c2VhbGVk']);
});

test('the Go mesh report fixture decodes and normalizes', function (): void {
    $report = Report::validateAndCreate(json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/report.mesh.json'), true));

    expect($report->host?->mesh)->toBeArray();

    $facts = MeshStatusFacts::fromProtocol((array) $report->host?->mesh);

    expect($facts['state'])->toBe('connected')
        ->and($facts['ipv4'])->toBe('100.64.0.2')
        ->and(array_column($facts['peers'], 'connection'))->toBe(['direct', 'relay', 'idle']);
});

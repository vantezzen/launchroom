<?php

declare(strict_types=1);

use App\Enums\Traces\TraceFailure;
use App\Enums\Traces\TraceFirewallOutcome;
use App\Enums\Traces\TraceOrigin;
use App\Support\Traces\DecodedTrace;
use App\Support\Traces\TraceCodec;
use App\Support\Traces\TraceEnvelope;
use Tests\Support\Traces;

/*
| The PHP decoder of the X-Launchroom-Ray header (docs/23 §2) against the
| shared vectors the Caddy module's Go encoder runs too
| (protocol/fixtures/v1/trace).
*/

function decodeTraceFixture(string $header, int $keyId, string $key): ?DecodedTrace
{
    $envelope = TraceCodec::envelope($header);

    // The dashboard finds the key by id: another id is an unknown key.
    if (! $envelope instanceof TraceEnvelope || $envelope->keyId !== $keyId) {
        return null;
    }

    $payload = TraceCodec::open($envelope, $key);

    return $payload === null ? null : TraceCodec::fields($payload);
}

/**
 * The decoded trace in the fixtures' field names (enumerations as numbers).
 *
 * @return array<string, mixed>
 */
function traceFixtureFields(DecodedTrace $trace): array
{
    $byte = static fn (array $cases, mixed $case): ?int => $case === null ? null : (int) array_search($case, $cases, true);

    return array_filter([
        'receivedAt' => $trace->receivedAtMs,
        'requestId' => $trace->requestId,
        'edge' => $trace->edge,
        'route' => $trace->route,
        'service' => $trace->service,
        'upstreamServer' => $trace->upstreamServer,
        'method' => $trace->method,
        'path' => $trace->path,
        'protocol' => $byte([1 => 'HTTP/1.0', 2 => 'HTTP/1.1', 3 => 'HTTP/2', 4 => 'HTTP/3'], $trace->protocol),
        'status' => $trace->status,
        'origin' => $byte(array_combine(range(1, 8), TraceOrigin::cases()), $trace->origin),
        'edgeTime' => $trace->edgeTimeUs,
        'upstreamTime' => $trace->upstreamTimeUs,
        'upstreamStatus' => $trace->upstreamStatus,
        'attempts' => $trace->attempts,
        'failure' => $byte(array_combine(range(1, 5), TraceFailure::cases()), $trace->failure),
        'firewall' => $trace->firewallMode === null ? null : [
            'mode' => $trace->firewallMode,
            'outcome' => $byte(array_combine(range(0, 2), TraceFirewallOutcome::cases()), $trace->firewallOutcome),
            'score' => $trace->firewallScore,
            'rules' => $trace->firewallRules,
        ],
    ], static fn (mixed $value): bool => $value !== null);
}

test('every valid fixture decodes to its fields', function (): void {
    [$keyId, $key] = Traces::fixtureKey();
    $fixtures = Traces::fixtures('valid');

    expect($fixtures)->not->toBeEmpty();

    foreach ($fixtures as $name => $fixture) {
        $header = Traces::header($fixture, $key);

        if (is_string($fixture['header'] ?? null)) {
            expect($fixture['header'])->toBe($header, "{$name}: header differs from its recipe");
        }

        $trace = decodeTraceFixture($header, $keyId, $key);

        expect($trace)->toBeInstanceOf(DecodedTrace::class, $name);

        $want = $fixture['fields'];
        ksort($want);
        $got = traceFixtureFields($trace);
        ksort($got);

        expect($got)->toBe($want, $name);
    }
});

test('every invalid fixture is refused', function (): void {
    [$keyId, $key] = Traces::fixtureKey();
    $fixtures = Traces::fixtures('invalid');

    expect($fixtures)->not->toBeEmpty();

    foreach ($fixtures as $name => $fixture) {
        expect(decodeTraceFixture(Traces::header($fixture, $key), $keyId, $key))->toBeNull($name);
    }
});

test('a pasted header line or value with whitespace or quotes is accepted, anything else is not', function (): void {
    expect(TraceCodec::value("  lr1.AQAAACo-_x  \n"))->toBe('lr1.AQAAACo-_x')
        ->and(TraceCodec::value('X-Launchroom-Ray: lr1.AQAAACo-_x'))->toBe('lr1.AQAAACo-_x')
        ->and(TraceCodec::value('x-launchroom-ray:"lr1.AQAAACo"'))->toBe('lr1.AQAAACo')
        ->and(TraceCodec::value('"lr1.AQAAACo"'))->toBe('lr1.AQAAACo')
        ->and(TraceCodec::value("'lr2.AQAAACo'"))->toBe('lr2.AQAAACo')
        ->and(TraceCodec::value('Launchroom-Trace: AQAAACo-_x'))->toBe('AQAAACo-_x')
        ->and(TraceCodec::value('launchroom-trace:AQAAACo'))->toBe('AQAAACo')
        ->and(TraceCodec::value('X-Other: AQAAACo'))->toBeNull()
        ->and(TraceCodec::value('AQAA ACo'))->toBeNull()
        ->and(TraceCodec::value('lr.AQAAACo'))->toBeNull()
        ->and(TraceCodec::value('lr1.lr1.AQAAACo'))->toBeNull()
        ->and(TraceCodec::value(str_repeat('A', TraceCodec::MAX_INPUT + 1)))->toBeNull();
});

test('a ray reads with the lr1. prefix and without it, and another prefix is a newer format', function (): void {
    [$keyId, $key] = Traces::fixtureKey();
    $legacy = Traces::fixtures('valid')['legacy-unprefixed.json'];
    $newer = Traces::fixtures('invalid')['newer-prefix.json'];
    $value = Traces::header($legacy, $key);

    expect($value)->not->toStartWith('lr')
        ->and(decodeTraceFixture($value, $keyId, $key))->toBeInstanceOf(DecodedTrace::class)
        ->and(decodeTraceFixture(TraceCodec::PREFIX.$value, $keyId, $key))->toBeInstanceOf(DecodedTrace::class)
        ->and(decodeTraceFixture('lr2.'.$value, $keyId, $key))->toBeNull()
        ->and(decodeTraceFixture(Traces::header($newer, $key), $keyId, $key))->toBeNull()
        ->and(TraceCodec::newer(Traces::header($newer, $key)))->toBeTrue()
        ->and(TraceCodec::newer('lr10.AQAAACo'))->toBeTrue()
        ->and(TraceCodec::newer('lr1.AQAAACo'))->toBeFalse()
        ->and(TraceCodec::newer($value))->toBeFalse();
});

test('a wrong key fails authentication', function (): void {
    [$keyId, $key] = Traces::fixtureKey();
    $fixture = Traces::fixtures('valid')['minimal.json'];

    expect(decodeTraceFixture(Traces::header($fixture, $key), $keyId, str_repeat("\x01", 32)))->toBeNull()
        ->and(decodeTraceFixture(Traces::header($fixture, $key), $keyId, 'short'))->toBeNull();
});

test('the test encoder writes what the decoder reads', function (): void {
    [$keyId, $key, $edge] = Traces::fixtureKey();
    $fields = [
        'receivedAt' => 1795089600123,
        'requestId' => '3f1c9a2e-8b7d-4e6f-a5b4-c3d2e1f00a1b',
        'edge' => $edge,
        'upstreamServer' => $edge,
        'method' => 'GET',
        'path' => '/api/users/:id',
        'protocol' => 3,
        'status' => 502,
        'origin' => 3,
        'edgeTime' => 30_000_123,
        'upstreamTime' => 30_000_000,
        'attempts' => 3,
        'failure' => 2,
    ];

    $got = traceFixtureFields(decodeTraceFixture(Traces::encode($fields, $keyId, $key), $keyId, $key) ?? throw new RuntimeException('not decoded'));
    ksort($got);
    ksort($fields);

    expect($got)->toBe($fields);
});

<?php

declare(strict_types=1);

use App\Actions\Servers\RenderNodeState;
use App\Enums\ServerRole;
use App\Models\Server;
use App\Services\Crypto\ResponseSignature;
use App\Services\Crypto\Signer;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;

function onlineServer(string $seed, array $roles = [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build]): Server
{
    return Server::factory()->withSigningSeed($seed)->online()->create(['roles' => $roles]);
}

test('the daemon fetches a signed, schema-valid NodeState for itself', function (): void {
    $seed = random_bytes(32);
    $server = onlineServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);

    $response = $daemon->send($this, 'GET', '/daemon/v1/state?have=0')->assertOk();
    $body = (string) $response->getContent();
    $signer = resolve(Signer::class);
    $state = json_decode($body, true);

    expect(ResponseSignature::verify((string) $response->headers->get('X-LR-Signature'), $body, [$signer->kid => $signer->publicKey()]))->toBeTrue()
        ->and(ProtocolSchemas::errors('node-state', $body))->toBe([])
        ->and($state['serverId'])->toBe($server->public_id)
        ->and($state['version'])->toBe(1)
        ->and($state['system']['caddy']['enabled'])->toBeTrue()
        ->and($state['system']['buildkit']['enabled'])->toBeTrue()
        ->and($state['workloads'])->toBe([])
        // Maps stay maps (protocol/README.md PHP pitfall).
        ->and($body)->toContain('"registry":{}');
});

test('it answers 304 when the daemon already has the latest version', function (): void {
    $seed = random_bytes(32);
    $server = onlineServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);
    $daemon->send($this, 'GET', '/daemon/v1/state?have=0')->assertOk();

    $response = $daemon->send($this, 'GET', '/daemon/v1/state?have=1');

    $response->assertStatus(304);
    expect($response->getContent())->toBe('')
        ->and($response->headers->has('X-LR-Signature'))->toBeFalse();
});

test('system containers follow the server roles', function (): void {
    $seed = random_bytes(32);
    $server = onlineServer($seed, [ServerRole::Runtime]);

    $state = new FakeDaemon($seed)->as($server->public_id)->send($this, 'GET', '/daemon/v1/state?have=0')->json();

    expect($state['system']['caddy'])->toBe(['enabled' => false])
        ->and($state['system']['buildkit'])->toBe(['enabled' => false]);
});

test('re-rendering unchanged content keeps the version; changes bump it', function (): void {
    $server = onlineServer(random_bytes(32), [ServerRole::Runtime]);
    $render = resolve(RenderNodeState::class);

    $first = $render->handle($server)->version;
    $again = $render->handle($server)->version;

    $server->roles = collect([ServerRole::Runtime, ServerRole::Edge]);
    $server->save();
    $changed = $render->handle($server)->version;

    expect($again->version)->toBe($first->version)
        ->and($changed->version)->toBe($first->version + 1)
        ->and($server->refresh()->desired_version)->toBe($changed->version);
});

test('have must be a non-negative integer', function (): void {
    $seed = random_bytes(32);
    $server = onlineServer($seed);

    new FakeDaemon($seed)->as($server->public_id)->send($this, 'GET', '/daemon/v1/state?have=-1')->assertUnprocessable();
});

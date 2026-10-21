<?php

declare(strict_types=1);

use App\Actions\Servers\RevokeServer;
use App\Data\Audit\AuditActor;
use App\Models\Server;
use App\Services\Daemon\HttpMessageSignature;
use Tests\Support\FakeDaemon;

beforeEach(function (): void {
    $seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create();
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
});

test('a correctly signed request is authenticated and bound to its server', function (): void {
    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0')->assertOk();

    expect($this->server->refresh()->last_seen_at)->not->toBeNull();
});

test('unsigned requests are rejected', function (): void {
    $this->getJson('/daemon/v1/state?have=0')->assertUnauthorized();
});

test('a replayed nonce is rejected', function (): void {
    $first = $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0');
    $first->assertOk();

    $request = $first->baseRequest;
    $server = [];
    foreach (['Signature-Input', 'Signature', 'Content-Digest', 'Date', 'X-LR-Nonce'] as $name) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $request->headers->get($name);
    }

    $this->call('GET', '/daemon/v1/state?have=0', [], [], [], $server)->assertUnauthorized();
});

test('requests outside the clock skew are rejected', function (): void {
    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0', created: now()->getTimestamp() - 61)->assertUnauthorized();
    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0', created: now()->getTimestamp() + 61)->assertUnauthorized();
});

test('a body that does not match the content digest is rejected', function (): void {
    $response = $this->daemon->send($this, 'POST', '/daemon/v1/reports', ['a' => 1], ['Content-Digest' => HttpMessageSignature::contentDigest('{"a":2}')]);

    $response->assertUnauthorized();
});

test('the signature covers the raw path and query', function (): void {
    $signed = $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0')->baseRequest;
    $server = [];
    foreach (['Signature-Input', 'Signature', 'Content-Digest', 'Date'] as $name) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $signed->headers->get($name);
    }
    $server['HTTP_X_LR_NONCE'] = 'BBBBBBBBBBBBBBBBBBBBBB';

    $this->call('GET', '/daemon/v1/state?have=5', [], [], [], $server)->assertUnauthorized();
});

test('another key cannot sign for a server', function (): void {
    (new FakeDaemon)->as($this->server->public_id)->send($this, 'GET', '/daemon/v1/state?have=0')->assertUnauthorized();
});

test('unknown server ids are rejected', function (): void {
    $this->daemon->as('srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK')->send($this, 'GET', '/daemon/v1/state?have=0')->assertUnauthorized();
});

test('a revoked server is denied immediately', function (): void {
    resolve(RevokeServer::class)->handle($this->server, AuditActor::system());

    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0')->assertUnauthorized();
});

test('the enroll keyid is only accepted by the enroll endpoint', function (): void {
    $this->daemon->as(HttpMessageSignature::ENROLL_KEY_ID)->send($this, 'GET', '/daemon/v1/state?have=0')->assertUnauthorized();
});

test('the enroll endpoint rejects server keyids', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody('lr_jt_'.str_repeat('a', 43)))->assertUnauthorized();
});

test('error responses are not signed', function (): void {
    $response = $this->getJson('/daemon/v1/state?have=0');

    expect($response->headers->has('X-LR-Signature'))->toBeFalse();
});

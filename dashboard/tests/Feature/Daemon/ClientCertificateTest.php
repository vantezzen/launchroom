<?php

declare(strict_types=1);

use App\Actions\Crypto\RotateKek;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Servers\RevokeServer;
use App\Data\Audit\AuditActor;
use App\Enums\AuditAction;
use App\Enums\ClientCertificateMode;
use App\Enums\DaemonFeature;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\DaemonClientCa;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerClientCertificate;
use App\Models\Team;
use App\Models\User;
use App\Queries\Security\ClientCertificatesOverview;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\ResponseSignature;
use App\Services\Crypto\SecretKey;
use App\Services\Crypto\Signer;
use App\Services\Daemon\ClientCertificateAuthority;
use App\Services\Daemon\DaemonEdgeSecret;
use App\Services\NodeStates\DashboardRoute;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;
use Tests\TestCase;

/*
| Client certificates for daemon transport (mTLS, D50, docs/03 §4.3): the
| internal CA, issuance over the signed channel, the identity the edge passes
| along, Require, revocation, the system routes' clientAuth and the settings.
*/

/**
 * A fresh ECDSA key as launchroomd makes it: [base64 DER SPKI, private key].
 *
 * @return array{0: string, 1: OpenSSLAsymmetricKey}
 */
function daemonTlsKey(string $curve = 'prime256v1'): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
    assert($key instanceof OpenSSLAsymmetricKey);
    $pem = openssl_pkey_get_details($key)['key'];

    return [preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem), $key];
}

function certServer(string $seed, array $attributes = []): Server
{
    return Server::factory()->withSigningSeed($seed)->online()->create([
        'roles' => [ServerRole::Runtime],
        'daemon_features' => [DaemonFeature::ClientCertificates->value],
        ...$attributes,
    ]);
}

function setCertificateMode(ClientCertificateMode $mode): void
{
    InstanceSettings::current()->forceFill(['daemon_client_certificates' => $mode])->save();
}

/**
 * Issues a certificate for $server through the endpoint and returns its row.
 */
function issueThrough(TestCase $test, FakeDaemon $daemon, array $headers = []): ServerClientCertificate
{
    [$publicKey] = daemonTlsKey();
    $response = $daemon->send($test, 'POST', '/daemon/v1/client-certificate', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'ClientCertificateRequest', 'publicKey' => $publicKey], $headers);
    $response->assertOk();

    return ServerClientCertificate::query()->where('fingerprint', hash('sha256', base64_decode($response->json('certificate'))))->sole();
}

/**
 * The headers launchroomd's Caddy adds through the edge.
 *
 * @return array<string, string>
 */
function edgeHeaders(?string $fingerprint, ?string $secret = null): array
{
    return [
        'X-Lr-Edge' => $secret ?? resolve(DaemonEdgeSecret::class)->current(),
        'X-Lr-Client-Cert' => $fingerprint ?? '{http.request.tls.client.fingerprint}',
    ];
}

test('a server gets a signed 7-day client certificate for its own key, chained to the internal CA', function (): void {
    $seed = random_bytes(32);
    $server = certServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);
    [$publicKey] = daemonTlsKey();

    $response = $daemon->send($this, 'POST', '/daemon/v1/client-certificate', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'ClientCertificateRequest', 'publicKey' => $publicKey])->assertOk();
    $body = (string) $response->getContent();
    $signer = resolve(Signer::class);

    expect(ResponseSignature::verify((string) $response->headers->get('X-LR-Signature'), $body, [$signer->kid => $signer->publicKey()]))->toBeTrue()
        ->and(ProtocolSchemas::errors('client-certificate', $body))->toBe([]);

    $doc = $response->json();
    $leafPem = "-----BEGIN CERTIFICATE-----\n".chunk_split($doc['certificate'], 64, "\n")."-----END CERTIFICATE-----\n";
    $caPem = "-----BEGIN CERTIFICATE-----\n".chunk_split($doc['caCertificates'][0], 64, "\n")."-----END CERTIFICATE-----\n";
    $leaf = openssl_x509_parse($leafPem);
    $ca = openssl_x509_parse($caPem);

    expect($doc['serverId'])->toBe($server->public_id)
        ->and($leaf['subject']['CN'])->toBe($server->public_id)
        ->and($leaf['extensions']['subjectAltName'])->toBe('URI:urn:launchroom:server:'.$server->public_id)
        ->and($leaf['extensions']['extendedKeyUsage'])->toBe('TLS Web Client Authentication')
        ->and($leaf['extensions']['basicConstraints'])->toBe('CA:FALSE')
        ->and($leaf['validTo_time_t'] - $leaf['validFrom_time_t'])->toBe(7 * 86400 + 300)
        ->and($ca['extensions']['basicConstraints'])->toContain('CA:TRUE')
        ->and(openssl_x509_verify($leafPem, openssl_pkey_get_public($caPem)))->toBe(1)
        // The certificate is for the daemon's key, not one the dashboard made.
        ->and(preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details(openssl_pkey_get_public($leafPem))['key']))->toBe($publicKey)
        ->and(strtotime($doc['renewAfter']))->toBeGreaterThan(strtotime($doc['notBefore']))->toBeLessThan(strtotime($doc['notAfter']));

    $row = ServerClientCertificate::query()->sole();
    expect($row->server_id)->toBe($server->id)
        ->and($row->fingerprint)->toBe(hash('sha256', base64_decode($doc['certificate'])))
        ->and(AuditEvent::query()->where('action', AuditAction::ClientCertificateIssued->value)->sole()->meta['fingerprint'])->toBe($row->fingerprint);

    // The CA key is envelope-encrypted and hidden.
    $authority = DaemonClientCa::query()->sole();
    expect($authority->private_key)->not->toContain('PRIVATE KEY')
        ->and(json_encode($authority->toArray(), JSON_THROW_ON_ERROR))->not->toContain($authority->private_key);
});

test('only ECDSA P-256 public keys are accepted', function (string $publicKey): void {
    $seed = random_bytes(32);
    $daemon = new FakeDaemon($seed)->as(certServer($seed)->public_id);

    $daemon->send($this, 'POST', '/daemon/v1/client-certificate', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'ClientCertificateRequest', 'publicKey' => $publicKey])
        ->assertStatus(422);

    expect(ServerClientCertificate::query()->count())->toBe(0);
})->with([
    'P-384' => [fn (): string => daemonTlsKey('secp384r1')[0]],
    'not a key' => [base64_encode('definitely not a key')],
    'RSA' => [function (): string {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        return preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details($key)['key']);
    }],
]);

test('issuance is throttled per server', function (): void {
    config(['launchroom.daemon.client_certificates.max_per_hour' => 2]);
    $seed = random_bytes(32);
    $daemon = new FakeDaemon($seed)->as(certServer($seed)->public_id);

    issueThrough($this, $daemon);
    issueThrough($this, $daemon);

    $daemon->send($this, 'POST', '/daemon/v1/client-certificate', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'ClientCertificateRequest', 'publicKey' => daemonTlsKey()[0]])
        ->assertStatus(429);
});

test('while off, the edge headers are ignored', function (): void {
    $seed = random_bytes(32);
    $server = certServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);

    $daemon->send($this, 'GET', '/daemon/v1/config', null, edgeHeaders(str_repeat('a', 64), 'lr_edge_wrong'))->assertOk();
    expect($server->refresh()->client_certificate_missing_at)->toBeNull();
});

test('while checking, a verified certificate of the signing server passes and is recorded', function (): void {
    $seed = random_bytes(32);
    $server = certServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);
    $certificate = issueThrough($this, $daemon);
    setCertificateMode(ClientCertificateMode::Verify);

    $daemon->send($this, 'GET', '/daemon/v1/config', null, edgeHeaders($certificate->fingerprint))->assertOk();

    expect($server->refresh()->client_certificate_seen_at)->not->toBeNull()
        ->and($certificate->refresh()->last_presented_at)->not->toBeNull();

    // Without a certificate it still passes, but readiness notices.
    $daemon->send($this, 'GET', '/daemon/v1/config', null, edgeHeaders(null))->assertOk();
    $daemon->send($this, 'GET', '/daemon/v1/config')->assertOk();
    expect($server->refresh()->client_certificate_missing_at)->not->toBeNull();
});

test('a wrong edge secret, a malformed fingerprint, a foreign, revoked, expired or unknown certificate is refused', function (string $case): void {
    $seed = random_bytes(32);
    $server = certServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);
    $certificate = issueThrough($this, $daemon);
    setCertificateMode(ClientCertificateMode::Verify);

    $headers = match ($case) {
        'wrong edge secret' => edgeHeaders($certificate->fingerprint, 'lr_edge_'.str_repeat('x', 43)),
        'malformed fingerprint' => edgeHeaders('ZZ'.str_repeat('0', 62)),
        'unknown certificate' => edgeHeaders(str_repeat('ab', 32)),
        'another server\'s certificate' => (function (): array {
            $otherSeed = random_bytes(32);
            $other = certServer($otherSeed);
            $foreign = issueThrough($this, new FakeDaemon($otherSeed)->as($other->public_id));

            return edgeHeaders($foreign->fingerprint);
        })(),
        'revoked' => (function () use ($certificate): array {
            $certificate->forceFill(['revoked_at' => now(), 'revoked_reason' => 'test'])->save();

            return edgeHeaders($certificate->fingerprint);
        })(),
        'expired' => (function () use ($certificate): array {
            $certificate->forceFill(['not_after' => now()->subMinute()])->save();

            return edgeHeaders($certificate->fingerprint);
        })(),
    };

    $daemon->send($this, 'GET', '/daemon/v1/config', null, $headers)->assertUnauthorized();
})->with(['wrong edge secret', 'malformed fingerprint', 'unknown certificate', 'another server\'s certificate', 'revoked', 'expired']);

test('a direct request\'s fingerprint header is not a TLS fact', function (): void {
    $seed = random_bytes(32);
    $server = certServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);
    $certificate = issueThrough($this, $daemon);
    setCertificateMode(ClientCertificateMode::Require);

    // Someone reaching the dashboard's port without Caddy can't claim a certificate.
    $daemon->send($this, 'GET', '/daemon/v1/config', null, ['X-Lr-Client-Cert' => $certificate->fingerprint])
        ->assertUnauthorized()
        ->assertJson(['error' => 'client certificate required']);
});

test('requiring certificates refuses requests without one, except issuance and the control plane\'s loopback', function (): void {
    $seed = random_bytes(32);
    $server = certServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);
    $certificate = issueThrough($this, $daemon);
    setCertificateMode(ClientCertificateMode::Require);

    $daemon->send($this, 'GET', '/daemon/v1/config', null, edgeHeaders(null))->assertUnauthorized()->assertJson(['error' => 'client certificate required']);
    $daemon->send($this, 'GET', '/daemon/v1/config')->assertUnauthorized();
    $daemon->send($this, 'GET', '/daemon/v1/config', null, edgeHeaders($certificate->fingerprint))->assertOk();

    // Renewal while presenting the current certificate.
    issueThrough($this, $daemon, edgeHeaders($certificate->fingerprint));

    // The control-plane server's own launchroomd talks over loopback.
    $cpSeed = random_bytes(32);
    $cp = certServer($cpSeed, ['is_control_plane' => true]);
    $cpDaemon = new FakeDaemon($cpSeed)->as($cp->public_id);
    $cpDaemon->send($this, 'GET', '/daemon/v1/config')->assertOk();
    // Through the edge it gets no exception.
    $cpDaemon->send($this, 'GET', '/daemon/v1/config', null, edgeHeaders(null))->assertUnauthorized();
});

test('while required, a server that had a certificate gets a new one only by presenting it or in an owner-granted window', function (): void {
    $seed = random_bytes(32);
    $server = certServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);
    $certificate = issueThrough($this, $daemon);
    $certificate->forceFill(['not_after' => now()->subMinute()])->save();
    setCertificateMode(ClientCertificateMode::Require);
    $body = fn (): array => ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'ClientCertificateRequest', 'publicKey' => daemonTlsKey()[0]];

    $daemon->send($this, 'POST', '/daemon/v1/client-certificate', $body(), edgeHeaders(null))
        ->assertForbidden()
        ->assertJson(['error' => 'client certificate required']);

    $owner = User::factory()->instanceOwner()->create();
    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('client-certificates.reissue', $server->public_id))
        ->assertRedirect();
    expect($server->refresh()->client_certificate_reissue_until?->isFuture())->toBeTrue();

    $daemon->send($this, 'POST', '/daemon/v1/client-certificate', $body(), edgeHeaders(null))->assertOk();
    // The window closes with the new certificate.
    expect($server->refresh()->client_certificate_reissue_until)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::ClientCertificateReissueAllowed->value)->count())->toBe(1);

    // A server that never had one (a new enrollment) gets its first.
    $newSeed = random_bytes(32);
    $new = certServer($newSeed);
    issueThrough($this, new FakeDaemon($newSeed)->as($new->public_id), edgeHeaders(null));
});

test('revoking a server revokes its certificates', function (): void {
    $seed = random_bytes(32);
    $server = certServer($seed);
    $certificate = issueThrough($this, new FakeDaemon($seed)->as($server->public_id));

    resolve(RevokeServer::class)->handle($server, AuditActor::system());

    expect($certificate->refresh()->revoked_at)->not->toBeNull()
        ->and($certificate->revoked_reason)->toBe('server_revoked')
        ->and(AuditEvent::query()->where('action', AuditAction::ClientCertificatesRevoked->value)->sole()->meta['certificates'])->toBe(1);
});

test('the system routes carry clientAuth while certificates are checked, for a launchroomd that supports them', function (): void {
    config(['app.url' => 'https://dash.example.com', 'launchroom.control_plane.dashboard_upstream' => '127.0.0.1:8000']);
    $cp = Server::factory()->online()->create([
        'roles' => [ServerRole::Edge, ServerRole::Runtime],
        'is_control_plane' => true,
        'daemon_features' => [DaemonFeature::ClientCertificates->value],
    ]);
    $render = resolve(RenderNodeState::class);
    $routes = fn (): array => json_decode($render->handle($cp)->version->document, true, flags: JSON_THROW_ON_ERROR)['routes'];

    expect($routes()[0])->not->toHaveKey('clientAuth');

    setCertificateMode(ClientCertificateMode::Verify);
    $version = $render->handle($cp)->version;
    $document = $version->document;
    expect(ProtocolSchemas::errors('node-state', $document))->toBe([]);
    $route = json_decode($document, true, flags: JSON_THROW_ON_ERROR)['routes'][0];

    expect($route['id'])->toBe(DashboardRoute::ID)
        ->and($route['clientAuth']['mode'])->toBe('verify')
        ->and($route['clientAuth']['caCertificates'])->toBe(resolve(ClientCertificateAuthority::class)->trusted())
        ->and($route['clientAuth']['edgeSecret']['sealed'])->toBeString()
        ->and($document)->not->toContain(resolve(DaemonEdgeSecret::class)->current());

    // Sealing is randomized; an unchanged render keeps the version.
    expect($render->handle($cp)->version->version)->toBe($version->version);

    // Older launchroomd: never sent (strict decoding).
    $cp->forceFill(['daemon_features' => []])->save();
    expect($routes()[0])->not->toHaveKey('clientAuth');
});

test('instance owners move through Off, Check and Require; Require waits for ready servers unless forced', function (): void {
    $owner = User::factory()->instanceOwner()->create();
    $seed = random_bytes(32);
    certServer($seed, ['name' => 'fra-1']);

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('client-certificates.update'), ['mode' => 'require'])
        ->assertSessionHasErrors('mode');

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('client-certificates.update'), ['mode' => 'verify'])
        ->assertSessionHasNoErrors();
    expect(InstanceSettings::current()->daemon_client_certificates)->toBe(ClientCertificateMode::Verify);

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('client-certificates.update'), ['mode' => 'require'])
        ->assertSessionHasErrors(['mode' => 'Not every server is ready: fra-1.']);

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->put(route('client-certificates.update'), ['mode' => 'require', 'force' => true])
        ->assertSessionHasNoErrors();

    $audit = AuditEvent::query()->where('action', AuditAction::ClientCertificatesChanged->value)->orderByDesc('id')->first();
    expect(InstanceSettings::current()->daemon_client_certificates)->toBe(ClientCertificateMode::Require)
        ->and($audit->meta)->toMatchArray(['from' => 'verify', 'to' => 'require', 'forced' => true, 'notReady' => 1]);

    $this->actingAs($owner)->get(route('instance-security.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/instance-security')
            ->where('certificates.mode', 'require')
            ->where('certificates.servers.0.name', 'fra-1')
            ->where('certificates.servers.0.state', 'waiting')
            ->where('certificates.servers.0.canAllowReissue', true));
});

test('readiness follows what servers present', function (): void {
    $seed = random_bytes(32);
    $server = certServer($seed);
    $daemon = new FakeDaemon($seed)->as($server->public_id);
    $overview = fn (): string => resolve(ClientCertificatesOverview::class)->get()->servers[0]->state;

    expect($overview())->toBe('waiting');
    $certificate = issueThrough($this, $daemon);
    expect($overview())->toBe('has_certificate');

    setCertificateMode(ClientCertificateMode::Verify);
    $daemon->send($this, 'GET', '/daemon/v1/config')->assertOk();
    expect($overview())->toBe('not_presenting');

    $this->travel(1)->seconds();
    $daemon->send($this, 'GET', '/daemon/v1/config', null, edgeHeaders($certificate->fingerprint))->assertOk();
    expect($overview())->toBe('ready');

    $server->forceFill(['daemon_features' => []])->save();
    expect($overview())->toBe('update');
});

test('only instance owners see or change server certificates', function (TeamRole $role): void {
    $team = Team::factory()->create();
    $member = memberOf($team, $role);
    $server = certServer(random_bytes(32), ['team_id' => $team->id]);

    $this->actingAs($member)->withSession(passwordConfirmed())->get(route('instance-security.edit'))->assertForbidden();
    $this->actingAs($member)->withSession(passwordConfirmed())->put(route('client-certificates.update'), ['mode' => 'verify'])->assertForbidden();
    $this->actingAs($member)->withSession(passwordConfirmed())->post(route('client-certificates.reissue', $server->public_id))->assertForbidden();

    expect(InstanceSettings::current()->daemon_client_certificates)->toBeNull();
})->with(['team owner' => [TeamRole::Owner], 'admin' => [TeamRole::Admin], 'developer' => [TeamRole::Developer]]);

test('the artisan escape hatch turns certificates off', function (): void {
    setCertificateMode(ClientCertificateMode::Require);

    $this->artisan('launchroom:client-certificates', ['mode' => 'off'])->assertSuccessful();

    expect(InstanceSettings::current()->daemon_client_certificates)->toBe(ClientCertificateMode::Off);
    $this->artisan('launchroom:client-certificates')->expectsOutput('off')->assertSuccessful();
});

test('rotating the KEK re-wraps the CA key, which still signs', function (): void {
    resolve(ClientCertificateAuthority::class)->current();

    $current = resolve(Envelope::class);
    $next = new Envelope(SecretKey::generate());
    resolve(RotateKek::class)->handle($current, $next);
    app()->instance(Envelope::class, $next);
    app()->forgetInstance(ClientCertificateAuthority::class);

    $seed = random_bytes(32);
    issueThrough($this, new FakeDaemon($seed)->as(certServer($seed)->public_id));

    expect(DaemonClientCa::query()->sole()->kek_id)->toBe($next->kekId());
});

<?php

declare(strict_types=1);

use App\Data\Registry\RegistryScope;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\DaemonTask;
use App\Models\NodeStateVersion;
use App\Models\Server;
use App\Services\Crypto\ResponseSignature;
use App\Services\Crypto\Signer;
use App\Services\Registry\RegistryTokenIssuer;
use App\Services\Registry\RegistryTokenKeyFile;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;

const SVC = 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2';
const SVC_LOWER = 'svc_01j9z8x7w6v5t4s3r2q1p0n9n2';
const OTHER_SVC = 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9N3';

function registryTokenBody(array $scopes, ?string $task = null): array
{
    return array_filter([
        'apiVersion' => 'launchroom.vantezzen.io/v1',
        'kind' => 'RegistryTokenRequest',
        'scopes' => $scopes,
        'task' => $task,
    ], static fn (mixed $value): bool => $value !== null);
}

function scope(string $repository, string ...$actions): array
{
    return ['repository' => $repository, 'actions' => $actions];
}

/**
 * @return array<string, mixed>
 */
function jwtClaims(string $jwt): array
{
    $token = new Parser(new JoseEncoder)->parse($jwt);
    expect($token)->toBeInstanceOf(Plain::class);

    /** @var Plain $token */
    return $token->claims()->all();
}

function buildTask(Server $server, string $service = SVC, array $attributes = []): DaemonTask
{
    $task = DaemonTask::query()->create([
        'team_id' => $server->team_id,
        'type' => DaemonTaskType::Build,
        'spec' => (object) ['service' => $service],
        ...$attributes,
    ]);
    $task->forceFill(['server_id' => $server->id, 'attempt' => 1, 'leased_at' => now(), 'lease_expires_at' => now()->addMinute(), 'deadline_at' => now()->addMinutes(30)]);
    $task->transitionTo(DaemonTaskStatus::Leased);

    return $task;
}

beforeEach(function (): void {
    $this->keyDir = sys_get_temp_dir().'/launchroom-registry-'.bin2hex(random_bytes(6));
    config([
        'launchroom.registry.token_key_path' => $this->keyDir.'/registry-token.es256.pem',
        'launchroom.registry.token_public_key_path' => $this->keyDir.'/public/registry-token.pub.pem',
    ]);
    $this->registryKey = RegistryTokenKeyFile::create(config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path'));

    $seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create(['roles' => [ServerRole::Runtime, ServerRole::Build]]);
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
});

afterEach(function (): void {
    foreach ([config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path')] as $path) {
        if (is_file($path)) {
            chmod($path, 0600);
            unlink($path);
        }
    }

    @rmdir($this->keyDir.'/public');
    @rmdir($this->keyDir);
});

test('a mirror pull token is signed, schema-valid, short-lived and verifiable by the registry', function (): void {
    $response = $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody([
        scope('mirror/docker.io/library/postgres', 'pull'),
    ]))->assertOk();

    $body = (string) $response->getContent();
    $signer = resolve(Signer::class);
    $jwt = (string) $response->json('token');
    $claims = jwtClaims($jwt);

    expect(ResponseSignature::verify((string) $response->headers->get('X-LR-Signature'), $body, [$signer->kid => $signer->publicKey()]))->toBeTrue()
        ->and(ProtocolSchemas::errors('registry-token', $body))->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($claims['access'])->toBe([['type' => 'repository', 'name' => 'mirror/docker.io/library/postgres', 'actions' => ['pull']]])
        ->and($claims['aud'])->toBe(['launchroom-registry'])
        ->and($claims['sub'])->toBe($this->server->public_id)
        ->and($claims['exp']->getTimestamp() - $claims['iat']->getTimestamp())->toBeLessThanOrEqual(300);

    // Zot verifies with the public key file only.
    $token = new Parser(new JoseEncoder)->parse($jwt);
    $publicPem = (string) file_get_contents(config('launchroom.registry.token_public_key_path'));
    expect(new Validator()->validate($token, new SignedWith(new Sha256, InMemory::plainText($publicPem))))->toBeTrue()
        ->and(fileperms(config('launchroom.registry.token_key_path')) & 0777)->toBe(0400)
        ->and(fileperms(config('launchroom.registry.token_public_key_path')) & 0777)->toBe(0444)
        ->and($jwt)->toMatch('/^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/');
});

test('scope escalation attempts are refused as a whole', function (array $scopes, ?string $task = null): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody($scopes, $task))->assertForbidden();
})->with([
    'push to the mirror' => [[scope('mirror/docker.io/library/postgres', 'push')]],
    'mirror root' => [[scope('mirror/docker.io', 'pull')]],
    'app pull without a deployment' => [[scope('apps/'.SVC_LOWER, 'pull')]],
    'app push without a task' => [[scope('apps/'.SVC_LOWER, 'push')]],
    'cache without a task' => [[scope('cache/'.SVC_LOWER, 'pull')]],
    'foreign repository' => [[scope('library/node', 'pull')]],
    'nested apps path' => [[scope('apps/'.SVC_LOWER.'/x', 'pull')]],
    'allowed plus forbidden' => [[scope('mirror/docker.io/library/node', 'pull'), scope('apps/'.SVC_LOWER, 'push')]],
    'unknown task' => [[scope('apps/'.SVC_LOWER, 'push')], 'tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P9'],
]);

test('malformed scopes are rejected before authorization', function (array $scopes): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody($scopes))->assertUnprocessable();
})->with([
    'path traversal' => [[scope('../apps/x', 'pull')]],
    'uppercase' => [[scope('APPS/x', 'pull')]],
    'unknown action' => [[scope('mirror/docker.io/library/node', 'delete')]],
    'no actions' => [[['repository' => 'mirror/docker.io/x/y', 'actions' => []]]],
    'no scopes' => [[]],
]);

test('apps pulls are scoped to the images in the server\'s current NodeState', function (): void {
    NodeStateVersion::query()->create([
        'server_id' => $this->server->id, 'version' => 3, 'created_at' => now(),
        'document' => json_encode(['workloads' => [['image' => '127.0.0.1:5000/apps/'.SVC_LOWER.'@sha256:'.str_repeat('a', 64)]]]),
        'document_hash' => str_repeat('0', 64),
    ]);

    $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody([scope('apps/'.SVC_LOWER, 'pull')]))->assertOk();
    $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody([scope('apps/'.strtolower(OTHER_SVC), 'pull')]))->assertForbidden();
    $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody([scope('apps/'.SVC_LOWER, 'pull', 'push')]))->assertForbidden();
});

test('a leased build task may push its own service only', function (): void {
    $task = buildTask($this->server);

    $response = $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody([
        scope('apps/'.SVC_LOWER, 'pull', 'push'),
        scope('cache/'.SVC_LOWER, 'pull', 'push'),
        scope('mirror/ghcr.io/railwayapp/railpack-frontend', 'pull'),
    ], $task->public_id))->assertOk();

    expect(collect(jwtClaims((string) $response->json('token'))['access'])->pluck('name')->all())
        ->toBe(['apps/'.SVC_LOWER, 'cache/'.SVC_LOWER, 'mirror/ghcr.io/railwayapp/railpack-frontend']);

    $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody([scope('apps/'.strtolower(OTHER_SVC), 'push')], $task->public_id))->assertForbidden();
});

test('push requires a live lease of a build task held by this server', function (callable $makeTask): void {
    $task = $makeTask($this->server);

    $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody([scope('apps/'.SVC_LOWER, 'push')], $task->public_id))->assertForbidden();
})->with([
    'another server\'s task' => [fn (Server $server): DaemonTask => buildTask(Server::factory()->online()->create(['team_id' => $server->team_id]))],
    'pending task' => [fn (Server $server): DaemonTask => DaemonTask::query()->create(['team_id' => $server->team_id, 'type' => DaemonTaskType::Build, 'spec' => (object) ['service' => SVC], 'server_id' => $server->id])],
    'finished task' => [function (Server $server): DaemonTask {
        $task = buildTask($server);
        $task->transitionTo(DaemonTaskStatus::Succeeded);

        return $task;
    }],
    'expired lease' => [function (Server $server): DaemonTask {
        $task = buildTask($server);
        $task->forceFill(['lease_expires_at' => now()->subSecond()])->save();

        return $task;
    }],
    'not a build task' => [fn (Server $server): DaemonTask => buildTask($server, SVC, ['type' => DaemonTaskType::Logs])],
]);

test('servers that are not approved get no tokens', function (): void {
    $this->server->forceFill(['status' => ServerStatus::PendingApproval])->save();

    $this->daemon->send($this, 'POST', '/daemon/v1/registry-token', registryTokenBody([scope('mirror/docker.io/library/node', 'pull')]))->assertForbidden();
});

test('unsigned requests are rejected', function (): void {
    $this->postJson('/daemon/v1/registry-token', registryTokenBody([scope('mirror/docker.io/library/node', 'pull')]))->assertUnauthorized();
});

// ---- the public realm (GET /registry/token) ----

function realmGet(object $test, string $query, ?string $password): TestResponse
{
    $headers = $password === null ? [] : ['Authorization' => 'Basic '.base64_encode('launchroom:'.$password)];

    return $test->withHeaders($headers)->get('/registry/token?'.$query);
}

test('the realm requires a dashboard-issued token and never widens it', function (): void {
    $presented = resolve(RegistryTokenIssuer::class)->issue($this->server->public_id, [
        new RegistryScope('apps/'.SVC_LOWER, ['pull']),
    ], 120);

    realmGet($this, 'service=launchroom-registry&scope=repository:apps/'.SVC_LOWER.':pull', null)
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="launchroom registry"');

    $response = realmGet($this, 'service=launchroom-registry&scope=repository:apps/'.SVC_LOWER.':pull,push&scope=repository:apps/other:pull', $presented->token)->assertOk();
    $claims = jwtClaims((string) $response->json('token'));

    expect($claims['access'])->toBe([['type' => 'repository', 'name' => 'apps/'.SVC_LOWER, 'actions' => ['pull']]])
        ->and($claims['exp']->getTimestamp())->toBeLessThanOrEqual($presented->expiresAt->getTimestamp())
        ->and($response->json('access_token'))->toBe($response->json('token'))
        ->and($response->json('expires_in'))->toBeLessThanOrEqual(120);
    expect(array_key_exists('laravel_session', $response->headers->getCookies()))->toBeFalse();

    // Wrong service, forged and expired tokens are refused.
    realmGet($this, 'service=other&scope=repository:apps/'.SVC_LOWER.':pull', $presented->token)->assertUnauthorized();
    realmGet($this, 'scope=repository:apps/'.SVC_LOWER.':pull', substr($presented->token, 0, -4).'AAAA')->assertUnauthorized();

    $this->travel(121)->seconds();
    realmGet($this, 'scope=repository:apps/'.SVC_LOWER.':pull', $presented->token)->assertUnauthorized();
});

test('tokens signed with another key are not accepted by the realm', function (): void {
    $other = sys_get_temp_dir().'/launchroom-registry-other-'.bin2hex(random_bytes(6));
    $key = RegistryTokenKeyFile::create($other.'/key.pem', $other.'/pub/key.pem');
    config(['launchroom.registry.token_key_path' => $other.'/key.pem']);
    $forged = new RegistryTokenIssuer()->issue('attacker', [new RegistryScope('apps/'.SVC_LOWER, ['pull', 'push'])]);
    config(['launchroom.registry.token_key_path' => $this->keyDir.'/registry-token.es256.pem']);

    realmGet($this, 'scope=repository:apps/'.SVC_LOWER.':push', $forged->token)->assertUnauthorized();

    expect($key->kid())->not->toBe($this->registryKey->kid());
    foreach ([$other.'/key.pem', $other.'/pub/key.pem'] as $path) {
        chmod($path, 0600);
        unlink($path);
    }
    @rmdir($other.'/pub');
    @rmdir($other);
});

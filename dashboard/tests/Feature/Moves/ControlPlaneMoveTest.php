<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Moves\AdvanceControlPlaneMoves;
use App\Actions\Moves\TakeOverControlPlane;
use App\Contracts\ControlPlaneDumps;
use App\Data\Audit\AuditActor;
use App\Data\Moves\HandoverDatabaseData;
use App\Data\Moves\HandoverKeysData;
use App\Data\Moves\HandoverManifestData;
use App\Enums\AuditAction;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Moves\ServerMove;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Services\Backups\ControlPlaneKeyBundle;
use App\Services\Crypto\Signer;
use App\Services\Moves\HandoverStore;
use App\Services\Moves\MoveToken;
use App\Support\Crypto\PublicKeyFormat;
use App\Support\PublicId;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Projects;
use Tests\Support\Transfers;
use Tests\TestCase;

/*
| Settings → Move launchroom (D56, docs/14 §13.4): the new machine claims a
| one-time token with its own key, an owner compares the code, it joins as
| a server, everything moves to it, then the dashboard freezes, takes its
| final dump sealed to the new machine, and the new dashboard takes over.
*/

/**
 * A dump stream that never runs pg_dump: dump() yields $bytes, restore()
 * records what it was given.
 */
function fakeDumps(string $bytes): object
{
    $fake = new class($bytes) implements ControlPlaneDumps
    {
        public string $restored = '';

        public function __construct(private readonly string $bytes) {}

        public function dump(callable $sink, int $chunkBytes, int $timeout): int
        {
            foreach (str_split($this->bytes, $chunkBytes) as $chunk) {
                $sink($chunk);
            }

            return strlen($this->bytes);
        }

        public function restore(iterable $chunks, ?string $database, int $timeout): int
        {
            foreach ($chunks as $chunk) {
                $this->restored .= $chunk;
            }

            return strlen($this->restored);
        }
    };
    app()->instance(ControlPlaneDumps::class, $fake);

    return $fake;
}

/**
 * Key files where the dashboard reads them for the key bundle.
 */
function keyFilesForBundle(): void
{
    $dir = sys_get_temp_dir().'/lr-move-keys-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    file_put_contents($dir.'/kek', 'lrkek1:test-kek');
    file_put_contents($dir.'/signing.ed25519', 'lrsign1:'.TestCase::SIGNING_KID.':'.base64_encode(TestCase::signingSeed()));
    config([
        'launchroom.kek_path' => $dir.'/kek',
        'launchroom.signing_key_path' => $dir.'/signing.ed25519',
        'launchroom.registry.token_key_path' => $dir.'/none',
        'launchroom.registry.token_public_key_path' => $dir.'/none.pub',
        'launchroom.mesh.headscale_state_path' => null,
    ]);
}

/**
 * The new machine's request to the old dashboard.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function receiver(TestCase $test, string $method, string $path, string $secret, array $body = []): TestResponse
{
    return $test->call($method, $path, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));
}

function signedByControlPlane(TestResponse $response): bool
{
    preg_match('/sig=(\S+)$/', (string) $response->headers->get('X-LR-Signature'), $match);

    return isset($match[1]) && sodium_crypto_sign_verify_detached((string) base64_decode($match[1], true), (string) $response->getContent(), sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(TestCase::signingSeed())));
}

beforeEach(function (): void {
    Projects::useTestKek();
    Transfers::useTempStore();
    keyFilesForBundle();

    $this->team = Team::factory()->create();
    [$this->source] = Projects::server($this->team);
    $this->source->forceFill(['is_control_plane' => true, 'name' => 'droplet'])->save();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->receiverKeys = sodium_crypto_box_keypair();
    $this->receiverPublic = 'x25519:'.base64_encode(sodium_crypto_box_publickey($this->receiverKeys));
});

/**
 * Starts the move as the owner and returns the token shown once.
 */
function startMove(TestCase $test, User $owner): string
{
    $test->actingAs($owner)->post(route('move.start'))->assertRedirect(route('move.edit'));
    $token = session('moveToken');
    expect($token)->toBeString();

    return (string) $token;
}

/**
 * Claims as the new machine; returns [move, secret, code].
 *
 * @return array{ControlPlaneMove, string, string}
 */
function claimMove(TestCase $test, string $token, string $publicKey): array
{
    $parsed = MoveToken::parse($token) ?? throw new RuntimeException('bad token');
    $response = $test->postJson('/move/v1/claim', ['move' => $parsed['move'], 'secret' => $parsed['secret'], 'publicKey' => $publicKey, 'hostname' => 'netcup', 'arch' => 'aarch64'])->assertOk();
    expect(signedByControlPlane($response))->toBeTrue()
        ->and($response->json('kind'))->toBe('launchroom-move/1');

    return [ControlPlaneMove::query()->where('public_id', $parsed['move'])->sole(), $parsed['secret'], (string) $response->json('code')];
}

test('the move command is shown once to an instance owner, with a token only this move accepts', function (): void {
    $token = startMove($this, $this->owner);
    $move = ControlPlaneMove::query()->sole();

    expect($move->status)->toBe(ControlPlaneMoveStatus::WaitingForServer)
        ->and($move->token_hash)->not->toContain($token)
        ->and(MoveToken::parse($token)['signingKey'] ?? null)->toBe(resolve(Signer::class)->publicKey())
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneMoveStarted->value)->exists())->toBeTrue();

    $this->withSession(['moveToken' => $token])->get(route('move.edit'))
        ->assertInertia(fn ($page) => $page->component('settings/move')
            ->where('move.status', 'waiting_for_server')
            ->where('command', fn (string $command): bool => str_contains($command, '--move '.$token))
            ->has('preflight'));
    // Shown once: the next visit has no command.
    $this->get(route('move.edit'))->assertInertia(fn ($page) => $page->where('command', null));

    // Only instance owners.
    $admin = memberOf($this->team, TeamRole::Admin);
    $this->actingAs($admin)->get(route('move.edit'))->assertForbidden();
    $this->actingAs($admin)->post(route('move.start'))->assertForbidden();
});

test('the new machine claims with its key; a wrong secret or a second machine is refused', function (): void {
    $token = startMove($this, $this->owner);
    $parsed = MoveToken::parse($token) ?? [];

    $this->postJson('/move/v1/claim', ['move' => $parsed['move'], 'secret' => str_repeat('A', 43), 'publicKey' => $this->receiverPublic, 'hostname' => 'netcup', 'arch' => 'aarch64'])->assertForbidden();
    [$move, , $code] = claimMove($this, $token, $this->receiverPublic);

    expect($move->status)->toBe(ControlPlaneMoveStatus::Claimed)
        ->and($code)->toBe(MoveToken::claimCode($move->public_id, $this->receiverPublic))
        ->and($move->receiver?->hostname)->toBe('netcup');

    // The same machine again (a re-run installer): the same code.
    expect(claimMove($this, $token, $this->receiverPublic)[2])->toBe($code);
    $this->postJson('/move/v1/claim', ['move' => $parsed['move'], 'secret' => $parsed['secret'], 'publicKey' => 'x25519:'.base64_encode(random_bytes(32)), 'hostname' => 'x', 'arch' => 'x86_64'])->assertStatus(409);
    receiver($this, 'GET', "/move/v1/{$move->public_id}", 'wrong')->assertForbidden();
});

test('the owner confirms the code; only the new machine can open its join token; it joins as a server', function (): void {
    $token = startMove($this, $this->owner);
    [$move, $secret, $code] = claimMove($this, $token, $this->receiverPublic);

    $this->actingAs($this->owner)->post(route('move.approve'), ['code' => 'ABCD-EFGH'])->assertSessionHasErrors('code');
    $this->actingAs($this->owner)->post(route('move.approve'), ['code' => strtolower($code)])->assertRedirect();
    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::Joining);

    $status = receiver($this, 'GET', "/move/v1/{$move->public_id}", $secret)->assertOk();
    $joinToken = sodium_crypto_box_seal_open((string) base64_decode((string) $status->json('joinToken'), true), $this->receiverKeys);
    $enrollment = ServerEnrollment::query()->sole();
    expect($joinToken)->toStartWith('lr_jt_')
        ->and(hash_equals($enrollment->token_hash, hash('sha256', (string) $joinToken)))->toBeTrue()
        ->and($enrollment->is_control_plane)->toBeFalse()
        ->and($enrollment->roles->map(fn (ServerRole $role): string => $role->value)->sort()->values()->all())->toBe(['build', 'edge', 'runtime'])
        ->and($status->json('cpKey'))->toBe(resolve(Signer::class)->publicKey());

    // Its launchroomd enrolls (as EnrollServer would).
    [$target] = Projects::server($this->team, ip: '203.0.113.9');
    $target->forceFill(['status' => ServerStatus::PendingApproval->value])->saveQuietly();
    $enrollment->forceFill(['used_at' => now(), 'server_id' => $target->id])->save();
    receiver($this, 'GET', "/move/v1/{$move->public_id}", $secret)->assertOk()->assertJsonPath('status', 'joined');

    expect($move->refresh()->target_server_id)->toBe($target->id)
        ->and($move->sealed_join_token)->toBeNull()
        ->and($target->refresh()->status)->toBe(ServerStatus::Online);
});

test('the hand-over freezes this dashboard, seals the final dump to the new machine and points everyone there', function (): void {
    $dumps = fakeDumps(str_repeat('PGDMP', 500_000));
    $token = startMove($this, $this->owner);
    [$move, $secret, $code] = claimMove($this, $token, $this->receiverPublic);
    $this->actingAs($this->owner)->post(route('move.approve'), ['code' => $code]);
    [$target] = Projects::server($this->team, ip: '203.0.113.9');
    ServerEnrollment::query()->update(['used_at' => now(), 'server_id' => $target->id]);
    receiver($this, 'GET', "/move/v1/{$move->public_id}", $secret);

    // Nothing runs on the dashboard's server: the workload move is ready right away.
    $this->actingAs($this->owner)->post(route('move.workloads'))->assertRedirect();
    $this->get(route('move.edit'));
    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::ReadyToHandOver)
        ->and(ServerMove::query()->sole()->control_plane)->toBeTrue();

    // The installer on the new machine must be waiting.
    $move->forceFill(['receiver_seen_at' => now()->subMinutes(5)])->save();
    $this->actingAs($this->owner)->post(route('move.handover'), ['confirmation' => 'hand over'])->assertSessionHasErrors();
    receiver($this, 'GET', "/move/v1/{$move->public_id}", $secret);
    $this->actingAs($this->owner)->post(route('move.handover'), ['confirmation' => 'nope'])->assertSessionHasErrors('confirmation');
    $this->actingAs($this->owner)->post(route('move.handover'), ['confirmation' => 'Hand over'])->assertRedirect();

    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::HandoverReady)
        ->and(InstanceSettings::current()->frozen_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneHandoverStarted->value)->exists())->toBeTrue();

    // Frozen: changes wait, reads work, launchroomd and the move endpoints keep working.
    $this->actingAs($this->owner)->post(route('teams.store'), ['name' => 'New'])->assertSessionHasErrors();
    expect(Team::query()->where('name', 'New')->exists())->toBeFalse();
    $this->actingAs($this->owner)->get(route('servers.index'))->assertOk();
    $this->postJson('/api/v1/projects', [])->assertStatus(503)->assertJsonPath('error.code', 'moving');

    // The new machine fetches the dump: signed, opened only with its key, digests match.
    $status = receiver($this, 'GET', "/move/v1/{$move->public_id}", $secret)->assertOk();
    $manifest = $status->json('handover');
    $key = sodium_crypto_box_seal_open((string) base64_decode((string) $manifest['sealedKey'], true), $this->receiverKeys);
    expect($key)->toBeString()->and($manifest['database']['chunks'])->toBe(3);
    $dump = '';

    for ($i = 0; $i < $manifest['database']['chunks']; $i++) {
        $chunk = receiver($this, 'GET', "/move/v1/{$move->public_id}/chunks/{$i}", $secret)->assertOk();
        expect(signedByControlPlane($chunk))->toBeTrue();
        $plain = HandoverStore::open($move->public_id, $i, (string) $chunk->getContent(), (string) $key);
        expect(hash('sha256', (string) $plain))->toBe($manifest['database']['digests'][$i]);
        $dump .= $plain;
        // Swapped positions don't open.
        expect(HandoverStore::open($move->public_id, $i + 1, (string) $chunk->getContent(), (string) $key))->toBeNull();
    }

    expect($dump)->toBe(str_repeat('PGDMP', 500_000));
    $sealed = receiver($this, 'GET', "/move/v1/{$move->public_id}/keys", $secret)->assertOk()->getContent();
    $bundle = ControlPlaneKeyBundle::parse((string) sodium_crypto_box_seal_open((string) $sealed, $this->receiverKeys));
    expect($bundle['files']['signing.ed25519'])->toBe('lrsign1:'.TestCase::SIGNING_KID.':'.base64_encode(TestCase::signingSeed()))
        ->and(hash('sha256', (string) $sealed))->toBe($manifest['keys']['sha256']);

    // The new dashboard runs: this one points there for good.
    receiver($this, 'POST', "/move/v1/{$move->public_id}/complete", $secret, ['url' => 'https://dash.example.com'])->assertOk()->assertJsonPath('status', 'handed_over');
    // The old machine's own server is retired here too: its launchroomd stops serving.
    expect($this->source->refresh()->status)->toBe(ServerStatus::Revoked)
        ->and(Projects::nodeState($this->source)['routes'] ?? [])->toBe([])
        ->and(InstanceSettings::current()->moved_to)->toBe('https://dash.example.com')
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneHandedOver->value)->exists())->toBeTrue()
        ->and($dumps->restored)->toBe('');
    $this->actingAs($this->owner)->get(route('servers.index'))->assertInertia(fn ($page) => $page->component('moved')->where('movedTo', 'https://dash.example.com'));
    receiver($this, 'GET', "/move/v1/{$move->public_id}/chunks/0", $secret)->assertStatus(409);
});

test('an aborted hand-over thaws the dashboard', function (): void {
    fakeDumps('PGDMP');
    $token = startMove($this, $this->owner);
    [$move, $secret, $code] = claimMove($this, $token, $this->receiverPublic);
    $this->actingAs($this->owner)->post(route('move.approve'), ['code' => $code]);
    [$target] = Projects::server($this->team, ip: '203.0.113.9');
    ServerEnrollment::query()->update(['used_at' => now(), 'server_id' => $target->id]);
    receiver($this, 'GET', "/move/v1/{$move->public_id}", $secret);
    $this->actingAs($this->owner)->post(route('move.workloads'));
    $this->get(route('move.edit'));
    $this->actingAs($this->owner)->post(route('move.handover'), ['confirmation' => 'hand over']);
    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::HandoverReady);

    receiver($this, 'POST', "/move/v1/{$move->public_id}/abort", $secret, ['reason' => 'disk full'])->assertOk()->assertJsonPath('status', 'ready_to_hand_over');

    expect(InstanceSettings::current()->frozen_at)->toBeNull()
        ->and($move->refresh()->error)->toContain('disk full')
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneHandoverAborted->value)->exists())->toBeTrue();
    $this->actingAs($this->owner)->post(route('teams.store'), ['name' => 'After'])->assertSessionHasNoErrors();
});

test('the new dashboard takes over: its server becomes the control plane, the old one is retired, the freeze lifts', function (): void {
    // The state the final dump caught, as restored on the new machine.
    [$target] = Projects::server($this->team, [ServerRole::Runtime], ip: '203.0.113.9');
    $serverMove = ServerMove::query()->create(['team_id' => $this->team->id, 'source_server_id' => $this->source->id, 'target_server_id' => $target->id, 'control_plane' => true]);
    $serverMove->transitionTo(ServerMoveStatus::Ready);
    $move = new ControlPlaneMove(['source_server_id' => $this->source->id]);
    $move->forceFill(['token_hash' => str_repeat('a', 64), 'token_expires_at' => now()->addDay(), 'target_server_id' => $target->id, 'server_move_id' => $serverMove->id, 'receiver_public_key' => $this->receiverPublic, 'handover' => new HandoverManifestData('launchroom-move/1', 'cpm_x', '2026-11-15T00:00:00Z', 'https://1-2-3-4.sslip.io', 'https://1-2-3-4.sslip.io', new HandoverDatabaseData(0, 1, 0, [], str_repeat('0', 64)), 'sealed', new HandoverKeysData(0, str_repeat('0', 64)))]);
    $move->status = ControlPlaneMoveStatus::HandingOver;
    $move->save();
    InstanceSettings::current()->forceFill(['frozen_at' => now(), 'frozen_reason' => 'moving', 'recovery_passphrase_set_at' => now(), 'recovery_kit_confirmed_at' => now()])->save();

    $result = resolve(TakeOverControlPlane::class)->handle(AuditActor::system());

    expect($result['server'])->toBe($target->public_id)
        ->and($target->refresh()->is_control_plane)->toBeTrue()
        ->and($target->roles->map(fn (ServerRole $role): string => $role->value)->sort()->values()->all())->toBe(['build', 'edge', 'runtime'])
        ->and($this->source->refresh()->status)->toBe(ServerStatus::Revoked)
        ->and($this->source->is_control_plane)->toBeFalse()
        ->and($this->source->replaced_by_server_id)->toBe($target->id)
        ->and($move->refresh()->status)->toBe(ControlPlaneMoveStatus::HandedOver)
        ->and($serverMove->refresh()->status)->toBe(ServerMoveStatus::Completed)
        ->and(InstanceSettings::current()->frozen_at)->toBeNull()
        // The address changed (it was tied to the old IP): a new recovery kit, please.
        ->and(InstanceSettings::current()->recovery_kit_confirmed_at)->toBeNull()
        ->and(InstanceSettings::current()->move_checklist?->previousServerId)->toBe($this->source->id)
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneTakenOver->value)->exists())->toBeTrue();

    $this->actingAs($this->owner)->get(route('move.edit'))
        ->assertInertia(fn ($page) => $page->where('checklist.previous.name', 'droplet')->where('checklist.needsRecoveryKit', true)->where('checklist.openSteps', fn ($open): bool => in_array('recovery-kit', collect($open)->all(), true)));
    $this->actingAs($this->owner)->post(route('move.finish'), ['open' => ['<forged>']])->assertRedirect(route('servers.index'));
    expect(InstanceSettings::current()->move_checklist)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneMoveFinished->value)->sole()->meta['open'])->toContain('recovery-kit')->not->toContain('<forged>');

    // A second takeover finds nothing to take over.
    expect(fn () => resolve(TakeOverControlPlane::class)->handle(AuditActor::system()))->toThrow(DomainRuleViolation::class);
});

test('the new machine’s installer joins and receives the hand-over end to end (launchroom:move:receive)', function (): void {
    $dumps = fakeDumps(str_repeat('PGDMP-final-', 200_000));
    $token = startMove($this, $this->owner);
    $state = sys_get_temp_dir().'/lr-move-state-'.bin2hex(random_bytes(6));
    config(['launchroom.moves.token' => $token, 'launchroom.moves.hostname' => 'netcup']);
    Sleep::fake();
    $test = $this;
    $owner = $this->owner;
    $team = $this->team;

    // The old dashboard answers the receiver's HTTP; the owner approves as soon as the code shows.
    Http::fake(function (ClientRequest $request) use ($test, $owner, $team) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $response = $test->call($request->method(), $path, [], [], [], [
            'HTTP_AUTHORIZATION' => $request->header('Authorization')[0] ?? '',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $request->body() === '' ? null : $request->body());
        $move = ControlPlaneMove::query()->first();

        if ($path === '/move/v1/claim' && $move?->status === ControlPlaneMoveStatus::Claimed) {
            $test->actingAs($owner)->post(route('move.approve'), ['code' => (string) $move->claim_code]);
        }

        if ($move?->status === ControlPlaneMoveStatus::Joining && ServerEnrollment::query()->whereNull('used_at')->exists()) {
            [$target] = Projects::server($team, ip: '203.0.113.9');
            ServerEnrollment::query()->update(['used_at' => now(), 'server_id' => $target->id]);
        }

        return Http::response((string) $response->getContent(), $response->getStatusCode(), ['X-LR-Signature' => (string) $response->headers->get('X-LR-Signature'), 'Content-Type' => (string) $response->headers->get('Content-Type')]);
    });

    $this->artisan('launchroom:move:receive', ['--join' => true, '--state' => $state, '--json' => true])->assertSuccessful();
    expect(file_get_contents($state.'/join-token'))->toStartWith('lr_jt_')
        ->and(trim((string) file_get_contents($state.'/cp-key')))->toBe(resolve(Signer::class)->publicKey());

    // Joined; the owner moves the (no) apps and hands over.
    $this->artisan('launchroom:move:receive', ['--join' => true, '--state' => $state, '--json' => true])->assertSuccessful();
    $this->actingAs($this->owner)->post(route('move.workloads'));
    $this->get(route('move.edit'));
    $this->actingAs($this->owner)->post(route('move.handover'), ['confirmation' => 'hand over'])->assertRedirect();
    expect(ControlPlaneMove::query()->sole()->status)->toBe(ControlPlaneMoveStatus::HandoverReady);

    $this->artisan('launchroom:move:receive', ['--handover' => true, '--force' => true, '--state' => $state, '--json' => true])->assertSuccessful();
    expect($dumps->restored)->toBe(str_repeat('PGDMP-final-', 200_000))
        ->and(file_get_contents($state.'/restore/keys/signing.ed25519'))->toBe('lrsign1:'.TestCase::SIGNING_KID.':'.base64_encode(TestCase::signingSeed()))
        ->and(substr(sprintf('%o', fileperms($state.'/restore/keys/signing.ed25519')), -3))->toBe('400');

    $this->artisan('launchroom:move:receive', ['--complete' => 'https://dash.example.com', '--state' => $state, '--json' => true])->assertSuccessful();
    expect(ControlPlaneMove::query()->sole()->status)->toBe(ControlPlaneMoveStatus::HandedOver);
});

test('the receiver refuses answers that aren’t signed with the key in the token', function (): void {
    $token = startMove($this, $this->owner);
    config(['launchroom.moves.token' => $token]);
    Http::fake(['*' => Http::response(json_encode(['kind' => 'launchroom-move/1', 'status' => 'claimed', 'code' => 'AAAA-BBBB']), 200, ['X-LR-Signature' => 'kid=x, sig='.base64_encode(str_repeat("\0", 64))])]);

    $this->artisan('launchroom:move:receive', ['--join' => true, '--state' => sys_get_temp_dir().'/lr-move-state-'.bin2hex(random_bytes(6))])
        ->expectsOutputToContain('not signed with the key in the move token')
        ->assertFailed();
});

/*
| D103: a machine that is already a server of this dashboard (a cancelled
| move keeps the server it joined) claims with that server and proves it with
| its launchroomd identity key; the move continues with it instead of a join
| token it can't use. And no move starts, connects or moves apps when no
| build server could deploy them on the new server.
*/

/**
 * A server of the team whose launchroomd identity key the test holds.
 *
 * @return array{Server, string} [server, Ed25519 seed]
 */
function connectedServer(Team $team, string $name = 'netcup'): array
{
    $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
    [$server] = Projects::server($team, ip: '203.0.113.9');
    $server->forceFill([
        'name' => $name,
        'signing_public_key' => PublicKeyFormat::formatEd25519(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))),
    ])->save();

    return [$server->refresh(), $seed];
}

function serverProof(string $seed, string $move, string $server, string $receiverPublic): string
{
    return base64_encode(sodium_crypto_sign_detached(MoveToken::serverProofMessage($move, $server, $receiverPublic), sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed))));
}

/**
 * @param  array<string, mixed>  $extra
 * @return TestResponse<Response>
 */
function claimWith(TestCase $test, string $token, string $publicKey, array $extra): TestResponse
{
    $parsed = MoveToken::parse($token) ?? throw new RuntimeException('bad token');

    return $test->postJson('/move/v1/claim', ['move' => $parsed['move'], 'secret' => $parsed['secret'], 'publicKey' => $publicKey, 'hostname' => 'netcup', 'arch' => 'aarch64', ...$extra]);
}

/**
 * An app built from a repository running on $server.
 */
function builtAppOn(Team $team, Server $server): ServiceInstance
{
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web');
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    for ($i = 0; $i < 4; $i++) {
        Projects::report($server);
    }

    $web->forceFill(['kind' => ServiceKind::App])->saveQuietly();

    return ServiceInstance::query()->where('service_id', $web->id)->sole();
}

test('a machine that is already a server here claims as that server, and approval continues with it without a join token', function (): void {
    [$server, $seed] = connectedServer($this->team);
    $token = startMove($this, $this->owner);
    $moveId = MoveToken::parse($token)['move'] ?? '';

    $response = claimWith($this, $token, $this->receiverPublic, ['serverId' => $server->public_id, 'serverProof' => serverProof($seed, $moveId, $server->public_id, $this->receiverPublic)])->assertOk();
    expect(signedByControlPlane($response))->toBeTrue()
        ->and($response->json('status'))->toBe('claimed')
        ->and($response->json('joined'))->toBe('netcup');

    $move = ControlPlaneMove::query()->sole();
    expect($move->target_server_id)->toBe($server->id);

    // The owner sees which server it is before confirming the code.
    $this->actingAs($this->owner)->get(route('move.edit'))
        ->assertInertia(fn ($page) => $page->where('move.status', 'claimed')->where('move.target.name', 'netcup'));

    $this->actingAs($this->owner)->post(route('move.approve'), ['code' => (string) $move->claim_code])->assertRedirect()->assertSessionHasNoErrors();

    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::Joined)
        ->and($move->enrollment_id)->toBeNull()
        ->and($move->sealed_join_token)->toBeNull()
        ->and(ServerEnrollment::query()->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneMoveApproved->value)->sole()->meta)->toMatchArray(['server' => $server->public_id, 'connected' => true]);

    receiver($this, 'GET', "/move/v1/{$move->public_id}", MoveToken::parse($token)['secret'] ?? '')->assertOk()
        ->assertJsonPath('status', 'joined')
        ->assertJsonPath('joined', 'netcup')
        ->assertJsonMissingPath('joinToken');
});

test('a claimed server is refused unless it proves itself and can be the move’s target', function (): void {
    [$server, $seed] = connectedServer($this->team);
    $token = startMove($this, $this->owner);
    $moveId = MoveToken::parse($token)['move'] ?? '';
    $proof = fn (Server $as, ?string $key = null): array => ['serverId' => $as->public_id, 'serverProof' => serverProof($key ?? $seed, $moveId, $as->public_id, $this->receiverPublic)];

    // Another team's server (another launchroom, as far as this move knows).
    [$foreign] = Projects::server(Team::factory()->create());
    claimWith($this, $token, $this->receiverPublic, $proof($foreign))->assertForbidden();
    // A proof by another key, for another receiver key, or none.
    claimWith($this, $token, $this->receiverPublic, $proof($server, random_bytes(32)))->assertForbidden();
    claimWith($this, $token, $this->receiverPublic, ['serverId' => $server->public_id, 'serverProof' => serverProof($seed, $moveId, $server->public_id, 'x25519:'.base64_encode(random_bytes(32)))])->assertForbidden();
    claimWith($this, $token, $this->receiverPublic, ['serverId' => $server->public_id])->assertForbidden();

    // Offline, or part of another move.
    $server->transitionTo(ServerStatus::Offline);
    claimWith($this, $token, $this->receiverPublic, $proof($server))->assertStatus(409)->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'isn’t connected right now'));
    $server->refresh()->transitionTo(ServerStatus::Online);
    [$other] = Projects::server($this->team, ip: '203.0.113.10');
    $open = (new ServerMove)->forceFill(['public_id' => PublicId::generate('smv'), 'team_id' => $this->team->id, 'source_server_id' => $other->id, 'target_server_id' => $server->id, 'status' => ServerMoveStatus::Running->value]);
    $open->save();
    claimWith($this, $token, $this->receiverPublic, $proof($server))->assertStatus(409)->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'part of another server move'));
    $open->delete();

    // The dashboard's own server never is.
    $sourceSeed = random_bytes(32);
    $this->source->forceFill(['signing_public_key' => PublicKeyFormat::formatEd25519(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($sourceSeed)))])->save();
    claimWith($this, $token, $this->receiverPublic, $proof($this->source->refresh(), $sourceSeed))->assertStatus(409);

    // Nothing was claimed by the refusals: the token still works.
    expect(ControlPlaneMove::query()->sole()->status)->toBe(ControlPlaneMoveStatus::WaitingForServer);
    claimWith($this, $token, $this->receiverPublic, $proof($server->refresh()))->assertOk()->assertJsonPath('joined', 'netcup');

    // Gone offline before the owner confirms: approval refuses.
    $server->refresh()->transitionTo(ServerStatus::Offline);
    $this->actingAs($this->owner)->post(route('move.approve'), ['code' => (string) ControlPlaneMove::query()->sole()->claim_code])->assertSessionHasErrors('code');
    expect(ControlPlaneMove::query()->sole()->status)->toBe(ControlPlaneMoveStatus::Claimed);
});

test('joining that waits on an unused join token says why, and a re-run installer that proves its server continues the move', function (): void {
    [$server, $seed] = connectedServer($this->team);
    $token = startMove($this, $this->owner);
    [$move, $secret, $code] = claimMove($this, $token, $this->receiverPublic);
    $this->actingAs($this->owner)->post(route('move.approve'), ['code' => $code]);
    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::Joining);

    // A few minutes later nothing enrolled: the page and the installer say so.
    $this->travel(6)->minutes();
    receiver($this, 'GET', "/move/v1/{$move->public_id}", $secret)->assertOk()
        ->assertJsonPath('status', 'joining')
        ->assertJsonPath('message', AdvanceControlPlaneMoves::STALLED);
    $this->actingAs($this->owner)->get(route('move.edit'))
        ->assertInertia(fn ($page) => $page->where('move.status', 'joining')->where('move.error', AdvanceControlPlaneMoves::STALLED));

    // The installer runs again and proves it is already a server here.
    claimWith($this, $token, $this->receiverPublic, ['serverId' => $server->public_id, 'serverProof' => serverProof($seed, $move->public_id, $server->public_id, $this->receiverPublic)])
        ->assertOk()
        ->assertJsonPath('status', 'joined')
        ->assertJsonPath('joined', 'netcup');

    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::Joined)
        ->and($move->target_server_id)->toBe($server->id)
        ->and($move->error)->toBeNull()
        ->and($move->sealed_join_token)->toBeNull()
        // The join token it couldn't use is withdrawn.
        ->and(ServerEnrollment::query()->exists())->toBeFalse();
});

test('the installer of a machine that is already a server proves it with launchroomd’s key and continues the move (launchroom:move:receive --prove-server)', function (): void {
    [$server, $seed] = connectedServer($this->team);
    $token = startMove($this, $this->owner);
    $state = sys_get_temp_dir().'/lr-move-state-'.bin2hex(random_bytes(6));
    $daemon = sys_get_temp_dir().'/lr-move-daemon-'.bin2hex(random_bytes(6));
    mkdir($daemon.'/keys', 0700, true);
    file_put_contents($daemon.'/enrollment.json', json_encode(['serverId' => $server->public_id, 'controlPlaneUrl' => 'https://dash.example.com']));
    // As Go's x509.MarshalPKCS8PrivateKey + pem.EncodeToMemory write it.
    file_put_contents($daemon.'/keys/signing.key', "-----BEGIN PRIVATE KEY-----\n".base64_encode(hex2bin('302e020100300506032b657004220420').$seed)."\n-----END PRIVATE KEY-----\n");
    config(['launchroom.moves.token' => $token, 'launchroom.moves.hostname' => 'netcup']);
    Sleep::fake();
    $test = $this;
    $owner = $this->owner;

    Http::fake(function (ClientRequest $request) use ($test, $owner) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $response = $test->call($request->method(), $path, [], [], [], [
            'HTTP_AUTHORIZATION' => $request->header('Authorization')[0] ?? '',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $request->body() === '' ? null : $request->body());
        $move = ControlPlaneMove::query()->first();

        if ($path === '/move/v1/claim' && $move?->status === ControlPlaneMoveStatus::Claimed) {
            $test->actingAs($owner)->post(route('move.approve'), ['code' => (string) $move->claim_code]);
        }

        return Http::response((string) $response->getContent(), $response->getStatusCode(), ['X-LR-Signature' => (string) $response->headers->get('X-LR-Signature'), 'Content-Type' => (string) $response->headers->get('Content-Type')]);
    });

    $this->artisan('launchroom:move:receive', ['--prove-server' => $daemon, '--state' => $state, '--json' => true])->assertSuccessful();
    expect(json_decode((string) file_get_contents($state.'/server-proof'), true))->toHaveKeys(['move', 'publicKey', 'serverId', 'serverProof'])
        ->and((string) file_get_contents($state.'/server-proof'))->not->toContain(base64_encode($seed));

    $this->artisan('launchroom:move:receive', ['--join' => true, '--state' => $state, '--json' => true])
        ->expectsOutputToContain('"connected":"netcup"')
        ->assertSuccessful();

    $move = ControlPlaneMove::query()->sole();
    expect($move->status)->toBe(ControlPlaneMoveStatus::Joined)
        ->and($move->target_server_id)->toBe($server->id)
        ->and(file_exists($state.'/join-token'))->toBeFalse()
        ->and(trim((string) file_get_contents($state.'/cp-key')))->toBe(resolve(Signer::class)->publicKey());
});

test('no move starts, connects or moves apps while no build server could deploy them on the new server', function (): void {
    builtAppOn($this->team, $this->source);
    // The dashboard's server doesn't build, and nothing else does.
    $this->source->forceFill(['roles' => [ServerRole::Edge->value, ServerRole::Runtime->value]])->save();

    $this->actingAs($this->owner)->get(route('move.edit'))
        ->assertInertia(fn ($page) => $page->where('preflight', fn ($checks): bool => collect($checks)->contains(fn (array $check): bool => $check['key'] === 'builds'
            && $check['status'] === 'blocker'
            && str_contains($check['message'], 'No build server is online')
            && $check['href'] === route('servers.index', absolute: false))));
    $this->actingAs($this->owner)->post(route('move.start'))->assertSessionHasErrors();
    expect(ControlPlaneMove::query()->exists())->toBeFalse();

    // With the build role here, the new server takes it over: fine.
    $this->source->forceFill(['roles' => [ServerRole::Edge->value, ServerRole::Runtime->value, ServerRole::Build->value]])->save();
    $this->actingAs($this->owner)->get(route('move.edit'))
        ->assertInertia(fn ($page) => $page->where('preflight', fn ($checks): bool => collect($checks)->contains(fn (array $check): bool => $check['key'] === 'builds' && $check['status'] === 'ok')));
    $token = startMove($this, $this->owner);
    [$move, , $code] = claimMove($this, $token, $this->receiverPublic);

    // The build server went away before the code was confirmed; the new
    // machine wouldn't build (the dashboard's server lost the role).
    $this->source->forceFill(['roles' => [ServerRole::Edge->value, ServerRole::Runtime->value]])->save();
    $this->actingAs($this->owner)->post(route('move.approve'), ['code' => $code])->assertSessionHasErrors('code');
    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::Claimed);

    // A build server for the new machine's platform (arm64) is enough.
    [$builder] = Projects::server($this->team, [ServerRole::Build], ip: '203.0.113.20');
    $this->actingAs($this->owner)->post(route('move.approve'), ['code' => $code])->assertSessionHasNoErrors();
    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::Joining);

    // Joined; the builder goes offline before the apps move: refused again.
    [$target] = Projects::server($this->team, [ServerRole::Edge, ServerRole::Runtime], ip: '203.0.113.9');
    ServerEnrollment::query()->update(['used_at' => now(), 'server_id' => $target->id]);
    $this->actingAs($this->owner)->get(route('move.edit'));
    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::Joined);
    $builder->transitionTo(ServerStatus::Offline);
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => now()])->save();
    $this->actingAs($this->owner)->post(route('move.workloads'))->assertSessionHasErrors();
    expect($move->refresh()->status)->toBe(ControlPlaneMoveStatus::Joined);
    $this->actingAs($this->owner)->get(route('move.edit'))
        ->assertInertia(fn ($page) => $page->where('plan.blockers', fn ($blockers): bool => collect($blockers)->contains(fn (string $blocker): bool => str_contains($blocker, 'turn on Build for '.$target->name))));
});

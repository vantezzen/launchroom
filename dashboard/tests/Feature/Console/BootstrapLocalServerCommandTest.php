<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\SigningKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Crypto\Signer;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeDaemon;

function bootstrapLocal(array $options = []): array
{
    Artisan::call('launchroom:servers:bootstrap-local', $options);

    return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $this->owner = User::factory()->instanceOwner()->create();
    $this->team = Team::factory()->withMember($this->owner, TeamRole::Owner)->create();
});

test('it issues a control-plane join token and prints the CP key', function (): void {
    $out = bootstrapLocal();
    $signer = resolve(Signer::class);

    expect($out['token'])->toMatch('/^lr_jt_[A-Za-z0-9_-]{43}$/')
        ->and($out['cpKey'])->toBe($signer->publicKey())
        ->and($out['kid'])->toBe($signer->kid)
        ->and($out['serverId'])->toBeNull()
        ->and(ServerEnrollment::query()->sole()->is_control_plane)->toBeTrue()
        ->and(SigningKey::query()->sole()->public_key)->toBe($signer->publicKey());
});

test('running it again replaces the open token; after enrollment it reports the server', function (): void {
    $first = bootstrapLocal()['token'];
    $second = bootstrapLocal()['token'];

    $daemon = new FakeDaemon;
    $daemon->send($this, 'POST', '/daemon/v1/enroll', $daemon->enrollBody($first))->assertForbidden();
    $daemon->send($this, 'POST', '/daemon/v1/enroll', $daemon->enrollBody($second))->assertCreated();

    $server = Server::query()->sole();
    $again = bootstrapLocal();

    expect($server->is_control_plane)->toBeTrue()
        ->and($server->roles->map->value->all())->toBe(['edge', 'runtime', 'build'])
        ->and($again['serverId'])->toBe($server->public_id)
        ->and($again['token'])->toBeNull();
});

test('--force revokes the enrolled control-plane server and issues a new token', function (): void {
    $daemon = new FakeDaemon;
    $daemon->send($this, 'POST', '/daemon/v1/enroll', $daemon->enrollBody(bootstrapLocal()['token']))->assertCreated();

    $out = bootstrapLocal(['--force' => true]);

    expect($out['token'])->not->toBeNull()
        ->and(Server::query()->sole()->status)->toBe(ServerStatus::Revoked);
});

test('it fails without a team', function (): void {
    $this->owner->forceFill(['current_team_id' => null])->save();

    $this->artisan('launchroom:servers:bootstrap-local')->assertFailed();
});

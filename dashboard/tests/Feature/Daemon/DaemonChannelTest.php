<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Daemons' private nudge channels (docs/03 §4) are only reachable through the
// signed /daemon/v1/broadcasting/auth, never with a session or unsigned.

test('unsigned requests and browser sessions never get a daemon channel', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test', 'options' => ['host' => 'reverb', 'port' => 6001, 'scheme' => 'http']],
    ]);
    Broadcast::forgetDrivers();
    $server = Server::factory()->online()->create();
    $owner = User::factory()->create();
    Team::query()->findOrFail($server->team_id)->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

    $this->postJson('/daemon/v1/broadcasting/auth', [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'BroadcastAuthRequest', 'socketId' => '1234.5678', 'channel' => 'private-daemon.'.$server->public_id,
    ])->assertUnauthorized();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-daemon.'.$server->public_id])
        ->assertForbidden();
});

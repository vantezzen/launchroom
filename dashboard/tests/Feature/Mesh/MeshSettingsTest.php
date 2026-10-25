<?php

declare(strict_types=1);

use App\Actions\Servers\RenderNodeState;
use App\Enums\AuditAction;
use App\Enums\Mesh\MeshCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Mesh\MeshCredential;
use App\Models\NodeStateVersion;
use App\Models\Server;
use App\Models\Team;
use Tests\Support\FakeHeadscale;
use Tests\Support\ProtocolSchemas;

beforeEach(function (): void {
    $this->headscale = FakeHeadscale::install();
    $this->headscale->writeBootstrapFile();
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->admin = memberOf($this->team, TeamRole::Admin);
});

test('only instance owners see and change the private network settings', function (): void {
    $this->actingAs($this->admin)->get(route('servers.mesh.show'))->assertForbidden();
    $this->actingAs($this->admin)->post(route('servers.mesh.enable'))->assertForbidden();
    $this->actingAs($this->admin)->put(route('servers.mesh.own-tailscale'), ['loginServer' => 'https://controlplane.tailscale.com', 'authKey' => 'tskey-auth-x'])->assertForbidden();

    $this->actingAs($this->admin)->get(route('network-settings.edit'))->assertForbidden();

    // The old page moved into Settings → Network.
    $this->actingAs($this->owner)->get(route('servers.mesh.show'))
        ->assertRedirect(route('network-settings.edit').'#private-network');

    $this->actingAs($this->owner)->get(route('network-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/network')
            ->where('mesh.enabled', false)
            ->where('mesh.builtInAvailable', true)
            ->where('mesh.canManage', true)
            ->has('members')
            ->has('recommendedPolicy')
            ->has('ingress'));
});

test('the owner can switch the built-in mesh on before a second server joins', function (): void {
    $this->actingAs($this->owner)->post(route('servers.mesh.enable'))->assertRedirect();

    expect(InstanceSettings::current()->mesh_mode)->toBe(MeshMode::Embedded);
});

test('Headscale failures come back as a form error', function (): void {
    $this->headscale->down = true;

    $this->actingAs($this->owner)->from(route('network-settings.edit'))
        ->post(route('servers.mesh.enable'))
        ->assertRedirect(route('network-settings.edit'))
        ->assertSessionHasErrors('mesh');
});

test('bring your own Tailscale stores the key encrypted and advertises role tags', function (): void {
    $pair = sodium_crypto_box_keypair();
    $server = Server::factory()->online()->create([
        'team_id' => $this->team->id,
        'roles' => [ServerRole::Runtime],
        'sealing_public_key' => 'x25519:'.base64_encode(sodium_crypto_box_publickey($pair)),
        // Only servers an instance owner admitted get the tailnet key (review L1).
        'approved_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)
        ->put(route('servers.mesh.own-tailscale'), ['loginServer' => 'https://ControlPlane.Tailscale.com/', 'authKey' => ' tskey-auth-k123-SECRETSECRET '])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = InstanceSettings::current();
    $credential = MeshCredential::query()->where('purpose', MeshCredentialPurpose::TailscaleAuthKey->value)->sole();
    expect($settings->mesh_mode)->toBe(MeshMode::OwnTailscale)
        ->and($settings->mesh_login_server)->toBe('https://controlplane.tailscale.com')
        ->and($credential->ciphertext)->not->toContain('SECRETSECRET');

    $audit = AuditEvent::query()->where('action', AuditAction::MeshOwnTailscaleConfigured->value)->sole();
    expect(json_encode($audit->toArray()))->not->toContain('SECRETSECRET');

    resolve(RenderNodeState::class)->handle($server);
    $raw = NodeStateVersion::query()->where('server_id', $server->id)->orderByDesc('version')->firstOrFail()->document;
    $tailscale = json_decode($raw, true)['system']['tailscale'];

    expect(ProtocolSchemas::errors('node-state', $raw))->toBe([])
        ->and($tailscale['loginServer'])->toBe('https://controlplane.tailscale.com')
        ->and($tailscale['tags'])->toBe(['tag:lr-runtime'])
        ->and(json_decode((string) sodium_crypto_box_seal_open(base64_decode($tailscale['authKey']['sealed'], true), $pair), true)['authKey'])->toBe('tskey-auth-k123-SECRETSECRET');
});

test('own Tailscale input is validated', function (array $input, string $field): void {
    $this->actingAs($this->owner)
        ->put(route('servers.mesh.own-tailscale'), $input)
        ->assertSessionHasErrors($field);

    expect(InstanceSettings::current()->mesh_mode)->toBeNull();
})->with([
    'plain http' => [['loginServer' => 'http://controlplane.tailscale.com', 'authKey' => 'tskey-auth-x'], 'loginServer'],
    'path' => [['loginServer' => 'https://example.com/headscale', 'authKey' => 'tskey-auth-x'], 'loginServer'],
    'empty key' => [['loginServer' => 'https://controlplane.tailscale.com', 'authKey' => ''], 'authKey'],
    'key with spaces' => [['loginServer' => 'https://controlplane.tailscale.com', 'authKey' => 'tskey auth'], 'authKey'],
]);

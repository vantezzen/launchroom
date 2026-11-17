<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Enums\AuditAction;
use App\Enums\Registry\RegistryCredentialStatus;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Registry\RegistryCredential;
use App\Models\Team;
use App\Services\Crypto\SealedColumns;
use App\Support\PublicId;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Projects;

/*
| Settings → Private registries (D91): team-scoped sign-in details,
| envelope-encrypted, never sent back, checked against the registry.
*/

const REGISTRY_SECRET = 'ghp_registry-secret-token-0001';

beforeEach(function (): void {
    Projects::useTestKek();
    app()->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $hostname): array
        {
            return ['93.184.216.34'];
        }
    });
});

/**
 * A registry with a Bearer token flow that accepts exactly $password.
 */
function fakeSignIn(string $password = REGISTRY_SECRET): void
{
    Http::fake(function (Request $request) use ($password) {
        $url = $request->url();

        if (str_starts_with($url, 'https://ghcr.io/token')) {
            return ($request->header('Authorization')[0] ?? '') === 'Basic '.base64_encode('octocat:'.$password)
                ? Http::response(['token' => 'upstream-token'])
                : Http::response(['errors' => [['code' => 'DENIED']]], 401);
        }

        if ($url === 'https://ghcr.io/v2/') {
            return ($request->header('Authorization')[0] ?? '') === 'Bearer upstream-token'
                ? Http::response('{}')
                : Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io"']);
        }

        return Http::response('', 404);
    });
}

test('owners add a registry: the token is sealed, checked, audited without it and never rendered', function (): void {
    fakeSignIn();
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);

    $this->actingAs($owner)
        ->post(route('registry-credentials.store'), ['host' => 'https://GHCR.io/', 'username' => 'octocat', 'password' => REGISTRY_SECRET])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $credential = RegistryCredential::query()->sole();

    expect($credential->host)->toBe('ghcr.io')
        ->and($credential->team_id)->toBe($team->id)
        ->and($credential->status)->toBe(RegistryCredentialStatus::Ok)
        ->and($credential->secret)->toStartWith('lr1:')
        ->and($credential->secret)->not->toContain(REGISTRY_SECRET)
        ->and(resolve(SealedColumns::class)->reveal($credential, 'secret'))->toBe(REGISTRY_SECRET)
        ->and($credential->toJson())->not->toContain('lr1:')->not->toContain(REGISTRY_SECRET);

    $audit = AuditEvent::query()->where('action', AuditAction::RegistryCredentialCreated->value)->sole();
    expect(json_encode($audit->toArray(), JSON_THROW_ON_ERROR))->not->toContain(REGISTRY_SECRET);

    $page = $this->actingAs($owner)->get(route('registry-credentials.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/registries')
            ->where('registries.credentials.0.host', 'ghcr.io')
            ->where('registries.credentials.0.status', 'ok')
            ->where('registries.canManage', true));

    expect($page->getContent())->not->toContain(REGISTRY_SECRET)->not->toContain('lr1:');
});

test('a refused sign-in is saved as failing with a message that never holds the token', function (): void {
    fakeSignIn('another-token');
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);

    $this->actingAs($owner)
        ->post(route('registry-credentials.store'), ['host' => 'ghcr.io', 'username' => 'octocat', 'password' => REGISTRY_SECRET])
        ->assertRedirect();

    $credential = RegistryCredential::query()->sole();

    expect($credential->status)->toBe(RegistryCredentialStatus::Failed)
        ->and($credential->last_error)->toContain('refused the sign-in for octocat on ghcr.io')
        ->and($credential->last_error)->not->toContain(REGISTRY_SECRET);
});

test('a blank password keeps the stored one; hosts are checked by grammar and once per team', function (): void {
    fakeSignIn();
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $this->actingAs($owner)->post(route('registry-credentials.store'), ['host' => 'ghcr.io', 'username' => 'octocat', 'password' => REGISTRY_SECRET]);
    $credential = RegistryCredential::query()->sole();

    $this->actingAs($owner)
        ->put(route('registry-credentials.update', $credential), ['host' => 'ghcr.io', 'username' => 'octocat', 'password' => ''])
        ->assertSessionHasNoErrors();

    expect(resolve(SealedColumns::class)->reveal($credential->refresh(), 'secret'))->toBe(REGISTRY_SECRET);

    $this->actingAs($owner)->post(route('registry-credentials.store'), ['host' => 'ghcr.io', 'username' => 'x', 'password' => 'y'])->assertSessionHasErrors('host');
    $this->actingAs($owner)->post(route('registry-credentials.store'), ['host' => 'registry:5000', 'username' => 'x', 'password' => 'y'])->assertSessionHasErrors('host');
    $this->actingAs($owner)->post(route('registry-credentials.store'), ['host' => 'localhost', 'username' => 'x', 'password' => 'y'])->assertSessionHasErrors('host');
});

test('owners and admins manage registries; developers and viewers only see them', function (TeamRole $role, bool $manage): void {
    fakeSignIn();
    $team = Team::factory()->create();
    $user = memberOf($team, $role);
    $existing = RegistryCredential::query()->forceCreate(['public_id' => PublicId::generate('rgc'), 'team_id' => $team->id, 'host' => 'docker.io', 'username' => 'me', 'wrapped_dek' => 'x', 'kek_id' => 'x', 'secret' => 'x']);

    $this->actingAs($user)->get(route('registry-credentials.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('registries.canManage', $manage));

    $store = $this->actingAs($user)->post(route('registry-credentials.store'), ['host' => 'ghcr.io', 'username' => 'octocat', 'password' => REGISTRY_SECRET]);
    $delete = $this->actingAs($user)->delete(route('registry-credentials.destroy', $existing));
    $check = $this->actingAs($user)->post(route('registry-credentials.check', $existing));

    if ($manage) {
        $store->assertRedirect();
        $delete->assertRedirect();
        expect(RegistryCredential::query()->pluck('host')->all())->toBe(['ghcr.io']);
    } else {
        $store->assertForbidden();
        $delete->assertForbidden();
        $check->assertForbidden();
        expect(RegistryCredential::query()->count())->toBe(1);
    }
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'developer' => [TeamRole::Developer, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('another team’s registry is not reachable, even for its own members acting in another team', function (): void {
    $theirs = Team::factory()->create();
    $credential = RegistryCredential::query()->forceCreate(['public_id' => PublicId::generate('rgc'), 'team_id' => $theirs->id, 'host' => 'ghcr.io', 'username' => 'them', 'wrapped_dek' => 'x', 'kek_id' => 'x', 'secret' => 'x']);
    $owner = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($owner)->put(route('registry-credentials.update', $credential), ['host' => 'ghcr.io', 'username' => 'me', 'password' => 'x'])->assertNotFound();
    $this->actingAs($owner)->delete(route('registry-credentials.destroy', $credential))->assertNotFound();
    $this->actingAs($owner)->post(route('registry-credentials.check', $credential))->assertNotFound();

    expect($credential->refresh()->username)->toBe('them');
});

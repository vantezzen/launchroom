<?php

declare(strict_types=1);

use App\Actions\Domains\AddDomain;
use App\Actions\Servers\RecordReport;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\Report;
use App\Enums\ActorType;
use App\Enums\AuditAction;
use App\Enums\DashboardDomainStatus;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\DashboardDomainChange;
use App\Models\Domain;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Services\Http\DashboardProbe;
use App\Services\Instance\DashboardOrigin;
use App\Services\NodeStates\DashboardRoute;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Passkeys\Passkey;
use Tests\Support\FakeDns;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

/*
| Settings → Dashboard domain (docs/05 §4.1, D43): enter a domain, DNS points
| at the main server, Caddy routes it and gets a certificate, the HTTPS test
| reaches this dashboard, then switch (and back); earlier origins keep
| answering launchroomd and, during the transition window, the API.
*/

beforeEach(function (): void {
    config([
        'app.url' => 'https://1-2-3-4.sslip.io',
        'launchroom.control_plane.dashboard_upstream' => '127.0.0.1:8000',
        'launchroom.dashboard.transition_days' => 30,
    ]);
    FakeDns::install();

    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->cp = Server::factory()->online()->create([
        'team_id' => $this->team->id,
        'roles' => [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build],
        'is_control_plane' => true,
        'public_ipv4' => '1.2.3.4',
    ]);
});

/**
 * The real dashboard answering the HTTPS test (Http::fake: the probe's own
 * endpoint, so the proof is the one this dashboard computes).
 */
function ddFakeProbe(bool $valid = true): void
{
    Http::fake(function (ClientRequest $request) use ($valid) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $nonce = is_string($query['nonce'] ?? null) ? $query['nonce'] : '';

        return Http::response(['proof' => $valid ? DashboardProbe::proof($nonce) : str_repeat('0', 64)]);
    });
}

function ddStart(User $owner, string $hostname = 'dash.example.com'): DashboardDomainChange
{
    test()->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('dashboard-domain.store'), ['hostname' => $hostname])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    return DashboardDomainChange::query()->latest('id')->firstOrFail();
}

function ddReady(User $owner, string $hostname = 'dash.example.com'): DashboardDomainChange
{
    FakeDns::set($hostname, ['1.2.3.4']);
    ddFakeProbe();

    $change = ddStart($owner, $hostname)->refresh();
    expect($change->status)->toBe(DashboardDomainStatus::Ready);

    return $change;
}

function ddSwitch(User $owner): void
{
    test()->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('dashboard-domain.activate'))
        ->assertRedirect('https://dash.example.com/settings/dashboard-domain');
}

/**
 * @return array<string, mixed>
 */
function ddRender(Server $server): array
{
    resolve(DashboardOrigin::class)->refresh();
    $render = resolve(RenderNodeState::class)->handle($server->refresh());
    expect($render->error)->toBeNull()
        ->and(ProtocolSchemas::errors('node-state', $render->version->document))->toBe([]);

    return json_decode($render->version->document, true, 64, JSON_THROW_ON_ERROR);
}

test('only instance owners reach Settings → Dashboard domain', function (): void {
    $admin = memberOf($this->team, TeamRole::Admin);

    $this->actingAs($admin)->get(route('dashboard-domain.edit'))->assertForbidden();
    $this->actingAs($admin)->withSession(passwordConfirmed())->post(route('dashboard-domain.store'), ['hostname' => 'dash.example.com'])->assertForbidden();
    $this->actingAs($admin)->withSession(passwordConfirmed())->post(route('dashboard-domain.activate'))->assertForbidden();
    $this->actingAs($admin)->withSession(passwordConfirmed())->post(route('dashboard-domain.revert'))->assertForbidden();
    $this->actingAs($admin)->withSession(passwordConfirmed())->delete(route('dashboard-domain.destroy'))->assertForbidden();
    $this->actingAs($admin)->post(route('dashboard-domain.check'))->assertForbidden();

    expect(DashboardDomainChange::query()->count())->toBe(0);

    $this->actingAs($this->owner)->get(route('dashboard-domain.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/dashboard-domain')
            ->where('settings.current.host', '1-2-3-4.sslip.io')
            ->where('settings.current.switched', false)
            ->where('settings.records.0.type', 'A')
            ->where('settings.records.0.value', '1.2.3.4'));
});

test('names the dashboard can’t use are refused', function (string $hostname, string $message): void {
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();
    Projects::useTestKek();
    $project = Projects::create($this->team);
    $web = Projects::image($project);
    $instance = ServiceInstance::query()->where('service_id', $web->id)->where('environment_id', $project->production()->sole()->id)->sole();
    resolve(AddDomain::class)->handle($instance, 'shop.example.com', AuditActor::system());
    expect(Domain::query()->where('hostname', 'shop.example.com')->exists())->toBeTrue();

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('dashboard-domain.store'), ['hostname' => $hostname])
        ->assertSessionHasErrors(['hostname' => $message]);

    expect(DashboardDomainChange::query()->count())->toBe(0);
})->with([
    'not a domain' => ['not a domain', 'Enter a domain such as dash.example.com (without https:// and without a path).'],
    'an IP' => ['10.0.0.5', 'Enter a domain such as dash.example.com (without https:// and without a path).'],
    'a single label' => ['dashboard', 'Enter a domain such as dash.example.com (without https:// and without a path).'],
    'sslip.io' => ['dash.5-6-7-8.sslip.io', 'sslip.io names are launchroom’s automatic fallback. Enter a domain you own.'],
    'the current address' => ['1-2-3-4.sslip.io', 'sslip.io names are launchroom’s automatic fallback. Enter a domain you own.'],
    'under the platform domain' => ['dash.apps.example.com', 'Names under the platform domain apps.example.com belong to apps. Use a name outside it, such as dash.example.com.'],
    'a project’s custom domain' => ['shop.example.com', 'A project already uses shop.example.com as a domain. Remove it there first, or pick another name.'],
]);

test('the checks wait for DNS, then route the name, test HTTPS and make the change ready', function (): void {
    ddFakeProbe();

    $change = ddStart($this->owner, 'https://Dash.Example.com/')->refresh();
    expect($change->hostname)->toBe('dash.example.com')
        ->and($change->status)->toBe(DashboardDomainStatus::Pending)
        ->and($change->dns['status'])->toBe('missing')
        ->and(resolve(DashboardRoute::class)->hosts())->toBe(['1-2-3-4.sslip.io']);
    Http::assertNothingSent();

    FakeDns::set('dash.example.com', ['9.9.9.9']);
    $this->actingAs($this->owner)->post(route('dashboard-domain.check'))->assertRedirect();
    expect($change->refresh()->dns['status'])->toBe('mismatch')
        ->and($change->status)->toBe(DashboardDomainStatus::Pending);

    FakeDns::set('dash.example.com', ['1.2.3.4']);
    $this->actingAs($this->owner)->post(route('dashboard-domain.check'))->assertRedirect();

    expect($change->refresh()->status)->toBe(DashboardDomainStatus::Ready)
        ->and($change->probe['ok'])->toBeTrue()
        ->and($change->probe['via'])->toBe('1.2.3.4')
        ->and(InstanceSettings::current()->dashboard_pending_host)->toBe('dash.example.com');

    // The test request is pinned to the main server and asks the probe path.
    Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://dash.example.com/.well-known/launchroom/dashboard?nonce='));

    $routes = ddRender($this->cp)['routes'];
    expect($routes[0])->toBe([
        'id' => DashboardRoute::ID,
        'hosts' => ['1-2-3-4.sslip.io', 'dash.example.com'],
        'tls' => 'acme',
        'upstreams' => [['address' => '127.0.0.1:8000']],
    ]);

    $this->actingAs($this->owner)->get(route('dashboard-domain.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('settings.change.status', 'ready')
            ->where('settings.change.steps.0.state', 'done')
            ->where('settings.change.steps.1.state', 'done')
            ->where('settings.change.steps.2.state', 'done'));
});

test('an answer that isn’t this dashboard never passes the HTTPS test', function (): void {
    FakeDns::set('dash.example.com', ['1.2.3.4']);
    ddFakeProbe(valid: false);

    $change = ddStart($this->owner)->refresh();

    expect($change->status)->toBe(DashboardDomainStatus::Pending)
        ->and($change->probe['ok'])->toBeFalse()
        ->and($change->probe['message'])->toBe('Something else answered at this address, not this dashboard.');

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('dashboard-domain.activate'))
        ->assertSessionHasErrors('hostname');
    expect(InstanceSettings::current()->dashboard_url)->toBeNull();
});

test('the probe endpoint answers the MAC of a well-formed nonce only', function (): void {
    $this->get('/.well-known/launchroom/dashboard?nonce=abcdefghijklmnopqrstuvwx')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['proof' => DashboardProbe::proof('abcdefghijklmnopqrstuvwx')]);

    $this->get('/.well-known/launchroom/dashboard?nonce=short')->assertNotFound();
    $this->get('/.well-known/launchroom/dashboard')->assertNotFound();
});

test('switching makes the domain canonical, keeps the old origin and tells every server', function (): void {
    [$remote] = Projects::server($this->team, [ServerRole::Runtime]);
    $remote->forceFill(['daemon_features' => ['controlPlaneUrl']])->save();
    [$old] = Projects::server($this->team, [ServerRole::Runtime]);

    ddReady($this->owner);

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('dashboard-domain.activate'), [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://dash.example.com/settings/dashboard-domain');

    $settings = InstanceSettings::current();
    expect($settings->dashboard_url)->toBe('https://dash.example.com')
        ->and($settings->dashboard_pending_host)->toBeNull()
        ->and($settings->dashboard_legacy_urls[0]['url'])->toBe('https://1-2-3-4.sslip.io')
        ->and(DashboardDomainChange::active()?->hostname)->toBe('dash.example.com')
        ->and(AuditEvent::query()->where('action', AuditAction::DashboardDomainSwitched->value)->sole()->meta)
        ->toMatchArray(['from' => 'https://1-2-3-4.sslip.io', 'to' => 'https://dash.example.com']);

    // The main server routes both; the canonical host first.
    expect(ddRender($this->cp)['routes'][0]['hosts'])->toBe(['dash.example.com', '1-2-3-4.sslip.io'])
        ->and(ddRender($this->cp)['system'])->not->toHaveKey('controlPlane');

    // launchroomd that follows gets the new origin in its signed state; older ones don't.
    expect(ddRender($remote)['system']['controlPlane'])->toBe(['url' => 'https://dash.example.com'])
        ->and(ddRender($old)['system'])->not->toHaveKey('controlPlane');

    // Links, the installer script and passkeys follow (app.url per request).
    $this->get('https://dash.example.com/up')->assertOk();
    expect(config('app.url'))->toBe('https://dash.example.com')
        ->and(url('/settings'))->toBe('https://dash.example.com/settings')
        ->and(config('passkeys.relying_party_id'))->toBe('dash.example.com')
        ->and(config('passkeys.allowed_origins'))->toBe(['https://dash.example.com'])
        ->and(config('session.cookie'))->toStartWith('__Host-')
        ->and(config('session.secure'))->toBeTrue();
});

test('the old origin redirects browsers but keeps launchroomd, and the API until the window ends', function (): void {
    ddReady($this->owner);
    ddSwitch($this->owner);

    $this->get('https://1-2-3-4.sslip.io/login?next=1')
        ->assertStatus(308)
        ->assertRedirect('https://dash.example.com/login?next=1')
        ->assertHeader('Cache-Control', 'no-store, private');

    // launchroomd's endpoints answer there for good (unsigned → 401, not a redirect).
    $this->get('https://1-2-3-4.sslip.io/daemon/v1/state')->assertUnauthorized();
    $this->get('https://1-2-3-4.sslip.io/up')->assertOk();
    $this->get('https://1-2-3-4.sslip.io/auth/preview', ['X-Forwarded-Host' => 'nothing.example.com'])->assertForbidden();

    // During the transition window: API and installer too.
    $this->getJson('https://1-2-3-4.sslip.io/api/v1/me')->assertUnauthorized();
    expect($this->get('https://1-2-3-4.sslip.io/install/server.sh')->getStatusCode())->not->toBe(308);

    $this->travel(31)->days();

    $this->getJson('https://1-2-3-4.sslip.io/api/v1/me')->assertStatus(308);
    $this->get('https://1-2-3-4.sslip.io/install/server.sh')->assertStatus(308);
    $this->get('https://1-2-3-4.sslip.io/daemon/v1/state')->assertUnauthorized();

    // Loopback (the main server's own launchroomd) and unknown hosts pass.
    expect($this->get('http://127.0.0.1:8000/projects')->getStatusCode())->not->toBe(308)
        ->and($this->get('http://dashboard:8000/projects')->getStatusCode())->not->toBe(308);
});

test('a domain still being verified only answers the test request', function (): void {
    FakeDns::set('dash.example.com', ['1.2.3.4']);
    ddFakeProbe();
    ddStart($this->owner);

    $this->get('https://dash.example.com/login')->assertStatus(308)->assertRedirect('https://1-2-3-4.sslip.io/login');
    $this->get('https://dash.example.com/.well-known/launchroom/dashboard?nonce=abcdefghijklmnopqrstuvwx')->assertOk();
});

test('switching back returns to the previous origin; the domain keeps forwarding', function (): void {
    ddReady($this->owner);
    ddSwitch($this->owner);

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post('https://dash.example.com/settings/dashboard-domain/switch-back', [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://1-2-3-4.sslip.io/settings/dashboard-domain');

    $settings = InstanceSettings::current();
    expect($settings->dashboard_url)->toBeNull()
        ->and(array_column($settings->dashboard_legacy_urls ?? [], 'url'))->toBe(['https://dash.example.com'])
        ->and(DashboardDomainChange::query()->sole()->status)->toBe(DashboardDomainStatus::Reverted)
        ->and(AuditEvent::query()->where('action', AuditAction::DashboardDomainReverted->value)->count())->toBe(1);

    $this->get('https://dash.example.com/projects')->assertStatus(308)->assertRedirect('https://1-2-3-4.sslip.io/projects');
});

test('the reset command makes APP_URL canonical again (the lock-out escape hatch)', function (): void {
    ddReady($this->owner);
    ddSwitch($this->owner);
    resolve(DashboardOrigin::class)->refresh();

    $this->artisan('launchroom:dashboard-domain:reset', ['--force' => true])
        ->expectsOutputToContain('https://1-2-3-4.sslip.io')
        ->assertSuccessful();

    resolve(DashboardOrigin::class)->refresh();
    expect(InstanceSettings::current()->dashboard_url)->toBeNull()
        ->and(resolve(DashboardOrigin::class)->canonical())->toBe('https://1-2-3-4.sslip.io')
        ->and(AuditEvent::query()->where('action', AuditAction::DashboardDomainReset->value)->sole()->actor_type)->toBe(ActorType::System)
        ->and(ddRender($this->cp)['routes'][0]['hosts'])->toBe(['1-2-3-4.sslip.io', 'dash.example.com']);

    $this->artisan('launchroom:dashboard-domain:reset', ['--force' => true, '--forget-old' => true])->assertSuccessful();
    resolve(DashboardOrigin::class)->refresh();
    expect(ddRender($this->cp)['routes'][0]['hosts'])->toBe(['1-2-3-4.sslip.io']);
});

test('passkeys keep their relying party when the new host is under it, and are counted otherwise', function (): void {
    config(['app.url' => 'https://example.com']);
    Passkey::query()->forceCreate(['user_id' => $this->owner->id, 'name' => 'Laptop', 'credential_id' => 'cred-1', 'credential' => [], 'rp_id' => 'example.com']);

    FakeDns::set('dash.example.com', ['1.2.3.4']);
    ddFakeProbe();
    ddStart($this->owner);

    $this->actingAs($this->owner)->get(route('dashboard-domain.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('settings.change.impact.keepsPasskeys', true)
            ->where('settings.change.impact.rpId', 'example.com'));

    ddSwitch($this->owner);
    expect(InstanceSettings::current()->passkey_rp_id)->toBe('example.com');

    FakeDns::set('dashboard.other.dev', ['1.2.3.4']);
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post('https://dash.example.com/settings/dashboard-domain', ['hostname' => 'dashboard.other.dev']);

    $this->actingAs($this->owner)->get('https://dash.example.com/settings/dashboard-domain')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('settings.change.impact.keepsPasskeys', false)
            ->where('settings.change.impact.rpId', 'dashboard.other.dev')
            ->where('settings.change.impact.passkeysAffected', 1)
            ->where('settings.change.impact.yourPasskeysAffected', 1));
});

test('reports record which address launchroomd uses, and preview forward auth follows it', function (): void {
    [$remote] = Projects::server($this->team, [ServerRole::Runtime]);
    $report = fn (array $controlPlane) => resolve(RecordReport::class)->handle($remote->refresh(), Report::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1',
        'kind' => 'Report',
        'serverId' => $remote->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'seq' => 1,
        'full' => true,
        'appliedVersion' => $remote->desired_version,
        'workloads' => [],
        'host' => ['cpus' => 2, 'memoryMb' => 1024, 'diskTotalMb' => 100, 'diskFreeMb' => 50, 'daemonVersion' => '0.1.0',
            'features' => ['controlPlaneUrl'], 'controlPlane' => $controlPlane],
    ]));

    ddReady($this->owner);
    ddSwitch($this->owner);
    resolve(DashboardOrigin::class)->refresh();

    $report(['state' => 'configured', 'configured' => 'https://1-2-3-4.sslip.io', 'active' => 'https://1-2-3-4.sslip.io']);
    expect(resolve(DashboardOrigin::class)->forServer($remote->refresh()))->toBe('https://1-2-3-4.sslip.io');

    $report(['state' => 'adopted', 'configured' => 'https://1-2-3-4.sslip.io', 'adopted' => 'https://dash.example.com', 'active' => 'https://dash.example.com']);
    expect($remote->refresh()->daemon_control_plane)->toMatchArray(['state' => 'adopted', 'adopted' => 'https://dash.example.com'])
        ->and(resolve(DashboardOrigin::class)->forServer($remote))->toBe('https://dash.example.com');

    // A URL that isn't one of the dashboard's origins never becomes a forward-auth target.
    $report(['state' => 'adopted', 'configured' => 'https://evil.example.net', 'adopted' => 'https://evil.example.net']);
    expect(resolve(DashboardOrigin::class)->forServer($remote->refresh()))->toBe('https://1-2-3-4.sslip.io');

    $this->actingAs($this->owner)->get('https://dash.example.com/settings/dashboard-domain')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('settings.servers.0.state', 'adopted')
            ->where('settings.legacy.0.host', '1-2-3-4.sslip.io')
            ->where('settings.legacy.0.inTransition', true));
});

test('cancelling drops the domain from the routes', function (): void {
    FakeDns::set('dash.example.com', ['1.2.3.4']);
    ddFakeProbe();
    ddStart($this->owner);
    resolve(DashboardOrigin::class)->refresh();
    expect(resolve(DashboardRoute::class)->hosts())->toContain('dash.example.com');

    $this->actingAs($this->owner)->withSession(passwordConfirmed())->delete(route('dashboard-domain.destroy'))->assertRedirect();

    resolve(DashboardOrigin::class)->refresh();
    expect(DashboardDomainChange::query()->sole()->status)->toBe(DashboardDomainStatus::Cancelled)
        ->and(resolve(DashboardRoute::class)->hosts())->toBe(['1-2-3-4.sslip.io']);
});

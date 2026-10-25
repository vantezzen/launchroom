<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Domains\AddDomain;
use App\Actions\Domains\SetDomainHsts;
use App\Actions\Ingress\SetDnsProvider;
use App\Actions\Servers\RecordReport;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\Report;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\AuditAction;
use App\Enums\CertStatus;
use App\Enums\DaemonFeature;
use App\Enums\DnsProvider;
use App\Enums\IngressCredentialPurpose;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\IngressCredential;
use App\Models\InstanceSettings;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\IngressSettingsView;
use App\Services\Ingress\DnsCredentials;
use App\Services\Ingress\IngressSecrets;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

/*
| Multi-edge ingress (docs/05 §4–5): every edge routes every service of the
| team, DNS-01 wildcard certificates for the platform domain, shared
| certificate storage, certificate reports and HSTS.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.ingress.caddy_modules' => true]);
});

function deployIngress(ServiceInstance|Environment $environment): void
{
    $environment = $environment instanceof ServiceInstance ? $environment->environment : $environment;
    resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());
}

function renderFor(Server $server): array
{
    $document = resolve(RenderNodeState::class)->handle($server)->version->document;
    expect(ProtocolSchemas::errors('node-state', $document))->toBe([]);

    return json_decode($document, true, 64, JSON_THROW_ON_ERROR);
}

/**
 * Opens a sealed ingress credential with the server's key pair.
 *
 * @return array{serverId: string, purpose: string, values: array<string, string>}
 */
function openIngressSecret(string $sealed, string $keypair): array
{
    $plaintext = sodium_crypto_box_seal_open((string) base64_decode($sealed, true), $keypair);
    expect($plaintext)->toBeString();

    return json_decode((string) $plaintext, true, 8, JSON_THROW_ON_ERROR);
}

/**
 * @param  list<array<string, mixed>>  $certificates
 */
function reportCertificates(Server $server, array $certificates, ?array $storage = null): void
{
    resolve(RecordReport::class)->handle($server, Report::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1',
        'kind' => 'Report',
        'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'seq' => (int) (microtime(true) * 1_000_000) + random_int(1, 1000),
        'full' => true,
        'appliedVersion' => $server->refresh()->desired_version,
        'workloads' => [],
        'ingress' => array_filter(['state' => 'applied', 'routes' => 1, 'certificates' => $certificates, 'storage' => $storage]),
    ]));
}

test('every edge routes the team’s services; replicas elsewhere need a mesh-published port', function (): void {
    Log::spy();
    $team = Team::factory()->create();
    [$runtime] = Projects::server($team);
    [$edge] = Projects::server($team, [ServerRole::Edge], ip: '203.0.113.8');
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web');
    deployIngress($project->production()->sole());
    Projects::report($runtime);
    Projects::report($runtime);

    $placement = Placement::query()->where('server_id', $runtime->id)->sole();

    // No mesh address and port yet: the edge has no route for it.
    expect(renderFor($edge)['routes'])->toBe([]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'mesh-published port'))->once();

    $runtime->forceFill(['mesh_ip' => '100.64.0.7'])->save();
    $placement->forceFill(['mesh_port' => 31001])->save();
    $all = renderFor($edge)['routes'];
    $routes = array_values(array_filter($all, fn (array $route): bool => str_starts_with($route['hosts'][0], 'web-production')));
    $immutable = array_values(array_filter($all, fn (array $route): bool => ! str_starts_with($route['hosts'][0], 'web-production')));

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['upstreams'])->toBe([['address' => '100.64.0.7:31001']])
        ->and($routes[0])->not->toHaveKey('loadBalancing')
        // Every edge also serves the immutable per-deployment URL (docs/05 §5).
        ->and($immutable)->toHaveCount(1)
        ->and($immutable[0]['upstreams'])->toBe([['address' => '100.64.0.7:31001']])
        // The runtime server (also an edge) still routes its own replica.
        ->and(renderFor($runtime)['routes'][0]['upstreams'])->toBe([['workload' => $placement->workload_id, 'port' => 80]]);

    // Outside the mesh range or the published-port range: never routed.
    $runtime->forceFill(['mesh_ip' => '10.0.0.7'])->save();
    expect(renderFor($edge)['routes'])->toBe([]);
    $runtime->forceFill(['mesh_ip' => '100.64.0.7'])->save();
    $placement->forceFill(['mesh_port' => 5432])->save();
    expect(renderFor($edge)['routes'])->toBe([]);
});

test('edges prefer their own replicas when every edge runs one', function (): void {
    $team = Team::factory()->create();
    [$one] = Projects::server($team);
    [$two] = Projects::server($team, [ServerRole::Edge, ServerRole::Runtime], ip: '203.0.113.8');
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web');
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['scaling' => new ScalingSettings(replicas: 2)]));
    deployIngress($instance);
    Projects::report($one);
    Projects::report($two);

    $placements = Placement::query()->orderBy('id')->get();
    expect($placements->pluck('server_id')->unique()->count())->toBe(2);

    foreach ([$one, $two] as $i => $server) {
        $server->forceFill(['mesh_ip' => '100.64.0.'.(10 + $i)])->save();
    }
    $placements->each(fn (Placement $placement, int $i) => $placement->forceFill(['mesh_port' => 31000 + $i])->save());

    foreach ([$one, $two] as $server) {
        renderFor($server);
        $route = Projects::domainRoutes($server)[0];
        expect($route['loadBalancing'])->toBe('prefer_local')
            ->and(collect($route['upstreams'])->filter(fn (array $u): bool => isset($u['workload']))->count())->toBe(1)
            ->and(collect($route['upstreams'])->filter(fn (array $u): bool => isset($u['address']))->count())->toBe(1);
    }
});

test('a DNS provider gives generated domains the wildcard certificate, sealed to edges only', function (): void {
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();
    $team = Team::factory()->create();
    [$edge, $keypair] = Projects::server($team);
    $edge->forceFill(['shared_edge_approved_at' => now()])->save();
    [$builder] = Projects::server($team, [ServerRole::Build], ip: '203.0.113.9');
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web');
    resolve(AddDomain::class)->handle(ServiceInstance::query()->where('service_id', $web->id)->sole(), 'shop.example.org', AuditActor::system());
    resolve(SetDnsProvider::class)->handle(DnsProvider::Cloudflare, ['token' => 'cf-token-SECRET'], AuditActor::system());
    deployIngress($project->production()->sole());
    Projects::report($edge);
    Projects::report($edge);

    $state = renderFor($edge);
    $routes = collect($state['routes'])->keyBy(fn (array $route): string => $route['hosts'][0]);

    expect($routes['web-production.shop.apps.example.com']['tls'])->toBe('wildcard')
        ->and($routes['shop.example.org']['tls'])->toBe('on_demand')
        // Immutable deployment URLs under the platform domain use the wildcard too.
        ->and($routes->filter(fn (array $route, string $host): bool => str_ends_with($host, '.shop.apps.example.com'))->pluck('tls')->unique()->all())->toBe(['wildcard'])
        ->and($state['ingress']['dns']['provider'])->toBe('cloudflare')
        ->and($state['ingress']['dns']['domains'])->toBe(['apps.example.com'])
        ->and($state['ingress'])->not->toHaveKey('storage')
        ->and(openIngressSecret($state['ingress']['dns']['credentials']['sealed'], $keypair))->toBe([
            'serverId' => $edge->public_id, 'purpose' => 'dns', 'values' => ['token' => 'cf-token-SECRET'],
        ])
        // Never in the database in plaintext; never on non-edge servers.
        ->and(IngressCredential::query()->sole()->ciphertext)->not->toContain('cf-token-SECRET')
        ->and(renderFor($builder))->not->toHaveKey('ingress');

    // Sealing is randomized, the fingerprint isn't: no new version.
    $version = $edge->refresh()->desired_version;
    renderFor($edge);
    expect($edge->refresh()->desired_version)->toBe($version);

    // A new token is a new version.
    resolve(SetDnsProvider::class)->handle(DnsProvider::Cloudflare, ['token' => 'cf-token-NEW'], AuditActor::system());
    renderFor($edge);
    expect($edge->refresh()->desired_version)->toBe($version + 1);
});

test('without launchroom’s Caddy image nothing asks for modules', function (): void {
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();
    $team = Team::factory()->create();
    [$edge] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    Projects::image($project, 'web');
    resolve(SetDnsProvider::class)->handle(DnsProvider::Hetzner, ['token' => 'hz-token'], AuditActor::system());
    // The image changed to stock Caddy afterwards.
    config(['launchroom.ingress.caddy_modules' => false, 'launchroom.ingress.cert_storage.address' => '100.64.0.1:6379', 'launchroom.ingress.cert_storage.password' => 'pw']);
    deployIngress($project->production()->sole());
    Projects::report($edge);
    Projects::report($edge);

    $state = renderFor($edge);

    expect($state)->not->toHaveKey('ingress')
        ->and(collect($state['routes'])->pluck('tls')->unique()->all())->not->toContain('wildcard');
});

test('shared certificate storage is sealed to every edge with one generated key', function (): void {
    config([
        'launchroom.ingress.cert_storage.address' => '100.64.0.1:6379',
        'launchroom.ingress.cert_storage.password' => 'valkey-SECRET',
        'launchroom.ingress.cert_storage.username' => 'lr-caddy',
    ]);
    $team = Team::factory()->create();
    [$one, $keyOne] = Projects::server($team);
    [$two, $keyTwo] = Projects::server($team, [ServerRole::Edge], ip: '203.0.113.8');
    $one->forceFill(['is_control_plane' => true])->save();
    $two->forceFill(['shared_edge_approved_at' => now()])->save();

    $a = openIngressSecret(renderFor($one)['ingress']['storage']['credentials']['sealed'], $keyOne);
    $b = openIngressSecret(renderFor($two)['ingress']['storage']['credentials']['sealed'], $keyTwo);

    expect(renderFor($one)['ingress']['storage'])->toMatchArray(['address' => '100.64.0.1:6379', 'keyPrefix' => 'lr-caddy'])
        ->and($a['purpose'])->toBe('cert_storage')
        ->and($a['serverId'])->toBe($one->public_id)
        ->and($b['serverId'])->toBe($two->public_id)
        ->and($a['values']['password'])->toBe('valkey-SECRET')
        ->and($a['values']['username'])->toBe('lr-caddy')
        ->and(strlen($a['values']['encryptionKey']))->toBe(32)
        ->and($b['values']['encryptionKey'])->toBe($a['values']['encryptionKey'])
        ->and(resolve(IngressSecrets::class)->get(IngressCredentialPurpose::StorageKey))->toBe($a['values']['encryptionKey']);

    config(['launchroom.ingress.cert_storage.address' => 'valkey:6379']);
    expect(renderFor($one))->not->toHaveKey('ingress');
});

test('edges report certificates; HSTS follows an issued certificate', function (): void {
    Event::fake([NodeStateInvalidated::class]);
    $team = Team::factory()->create();
    [$edge] = Projects::server($team);
    [$other] = Projects::server($team, [ServerRole::Edge], ip: '203.0.113.8');
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web');
    $domain = resolve(AddDomain::class)->handle(ServiceInstance::query()->where('service_id', $web->id)->sole(), 'shop.example.org', AuditActor::system());
    $otherProject = Projects::create(Team::factory()->create(), 'other');
    $foreign = resolve(AddDomain::class)->handle(ServiceInstance::query()->where('service_id', Projects::image($otherProject, 'web')->id)->sole(), 'theirs.example.org', AuditActor::system());
    deployIngress($project->production()->sole());
    Projects::report($edge);
    Projects::report($edge);

    expect(collect(renderFor($edge)['routes'])->firstWhere('hosts.0', 'shop.example.org'))->not->toHaveKey('headers');

    reportCertificates($edge, [
        ['host' => 'shop.example.org', 'state' => 'issued', 'issuer' => "Let's Encrypt R12", 'notBefore' => '2026-10-01T00:00:00Z', 'notAfter' => '2026-12-30T00:00:00Z'],
        ['host' => 'theirs.example.org', 'state' => 'failed', 'lastError' => 'nope'],
    ], ['state' => 'shared', 'mirrored' => 1]);

    $domain->refresh();
    expect($domain->cert_status)->toBe(CertStatus::Issued)
        ->and($domain->cert_issuer)->toBe("Let's Encrypt R12")
        ->and($domain->cert_expires_at?->toIso8601ZuluString())->toBe('2026-12-30T00:00:00Z')
        ->and($domain->cert_server_id)->toBe($edge->id)
        ->and($foreign->refresh()->cert_status)->toBeNull()
        ->and($edge->refresh()->ingress_status)->toBe(['state' => 'applied', 'routes' => 1, 'storage' => ['state' => 'shared', 'mirrored' => 1]]);
    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $event): bool => in_array($edge->id, $event->serverIds, true) && in_array($other->id, $event->serverIds, true));

    $route = collect(renderFor($edge)['routes'])->firstWhere('hosts.0', 'shop.example.org');
    expect($route['headers'])->toBe(['Strict-Transport-Security' => 'max-age=31536000']);

    // Another edge that hasn't loaded it yet doesn't override a fresh "issued".
    reportCertificates($other, [['host' => 'shop.example.org', 'state' => 'pending']]);
    expect($domain->refresh()->cert_status)->toBe(CertStatus::Issued);

    // The same edge reporting a failure does.
    reportCertificates($edge, [['host' => 'shop.example.org', 'state' => 'failed', 'lastError' => 'HTTP 400 urn:ietf:params:acme:error:dns', 'lastErrorAt' => '2026-10-18T12:00:00Z']]);
    expect($domain->refresh()->cert_status)->toBe(CertStatus::Failed)
        ->and($domain->cert_error)->toBe('HTTP 400 urn:ietf:params:acme:error:dns')
        ->and(collect(renderFor($edge)['routes'])->firstWhere('hosts.0', 'shop.example.org'))->not->toHaveKey('headers');
});

test('HSTS can be switched off per custom domain', function (): void {
    $team = Team::factory()->create();
    [$edge] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web');
    $domain = resolve(AddDomain::class)->handle(ServiceInstance::query()->where('service_id', $web->id)->sole(), 'shop.example.org', AuditActor::system());
    deployIngress($project->production()->sole());
    Projects::report($edge);
    Projects::report($edge);
    $domain->forceFill(['cert_status' => CertStatus::Issued])->save();

    resolve(SetDomainHsts::class)->handle($domain, false, AuditActor::system());

    expect($domain->refresh()->hsts)->toBeFalse()
        ->and(collect(renderFor($edge)['routes'])->firstWhere('hosts.0', 'shop.example.org'))->not->toHaveKey('headers');

    $generated = Domain::query()->where('kind', 'generated')->firstOrFail();
    expect(fn () => resolve(SetDomainHsts::class)->handle($generated, true, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class);
});

test('instance-wide certificate secrets reach only edges an instance owner admitted; team admins can’t admit (review M2)', function (): void {
    config([
        'launchroom.ingress.cert_storage.address' => '100.64.0.1:6379',
        'launchroom.ingress.cert_storage.password' => 'valkey-SECRET',
    ]);
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();
    resolve(SetDnsProvider::class)->handle(DnsProvider::Cloudflare, ['token' => 'cf-token-SECRET'], AuditActor::system());
    $team = Team::factory()->create();
    [$edge] = Projects::server($team, [ServerRole::Edge, ServerRole::Runtime], ip: '203.0.113.8');
    $project = Projects::create($team, 'shop');
    Projects::image($project, 'web');
    deployIngress($project->production()->sole());
    Projects::report($edge);
    Projects::report($edge);

    // A team admin enrolled it and gave it the edge role: it serves routes
    // with its own certificates, but gets no storage or DNS credentials.
    $state = renderFor($edge);
    expect($state)->not->toHaveKey('ingress')
        ->and(collect($state['routes'])->pluck('tls')->unique()->all())->not->toContain('wildcard');

    $admin = memberOf($team, TeamRole::Admin);
    $this->actingAs($admin)
        ->put(route('servers.ingress.edges.update', $edge), ['shared' => true])->assertForbidden();
    expect($edge->refresh()->shared_edge_approved_at)->toBeNull();

    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    $this->actingAs($owner)
        ->put(route('servers.ingress.edges.update', $edge), ['shared' => true])->assertRedirect()->assertSessionHasNoErrors();

    $state = renderFor($edge->refresh());
    expect($edge->shared_edge_approved_by)->toBe($owner->id)
        ->and($state['ingress'])->toHaveKeys(['storage', 'dns'])
        ->and(AuditEvent::query()->where('action', AuditAction::SharedEdgeChanged->value)->count())->toBe(1);

    $this->actingAs($owner)
        ->put(route('servers.ingress.edges.update', $edge), ['shared' => false])->assertRedirect();
    expect(renderFor($edge->refresh()))->not->toHaveKey('ingress');
});

test('every provider seals exactly its own credential values to edges', function (DnsProvider $provider, array $values): void {
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();
    $team = Team::factory()->create();
    [$edge, $keypair] = Projects::server($team);
    $edge->forceFill(['shared_edge_approved_at' => now()])->save();
    [$oldEdge] = Projects::server($team, ip: '203.0.113.20');
    $oldEdge->forceFill(['shared_edge_approved_at' => now(), 'daemon_features' => []])->save();
    $edge->forceFill(['daemon_features' => [DaemonFeature::DnsProviders->value]])->save();
    $project = Projects::create($team, 'shop');
    Projects::image($project, 'web');
    resolve(SetDnsProvider::class)->handle($provider, $values, AuditActor::system());
    deployIngress($project->production()->sole());
    Projects::report($edge);
    Projects::report($edge);

    // A launchroomd from before D47 would refuse the provider: per-host certificates there.
    $old = renderFor($oldEdge);
    expect($old['ingress'] ?? [])->not->toHaveKey('dns')
        ->and(collect($old['routes'] ?? [])->pluck('tls')->unique()->all())->not->toContain('wildcard');

    $state = renderFor($edge);
    $opened = openIngressSecret($state['ingress']['dns']['credentials']['sealed'], $keypair);
    ksort($values);
    ksort($opened['values']);

    expect($state['ingress']['dns']['provider'])->toBe($provider->value)
        ->and($opened)->toBe([
            'serverId' => $edge->public_id, 'purpose' => 'dns', 'values' => $values,
        ])
        ->and(collect($state['routes'])->pluck('tls')->unique()->all())->toContain('wildcard');
})->with([
    'route 53' => [DnsProvider::Route53, ['accessKeyId' => 'AKIAEXAMPLE123456', 'secretAccessKey' => 'r53-SECRET']],
    'porkbun' => [DnsProvider::Porkbun, ['apiKey' => 'pk1_key', 'secretKey' => 'sk1_SECRET']],
    'ovh' => [DnsProvider::Ovh, ['endpoint' => 'ovh-ca', 'applicationKey' => 'ak', 'applicationSecret' => 'as-SECRET', 'consumerKey' => 'ck-SECRET']],
    'netcup' => [DnsProvider::Netcup, ['customerNumber' => '123456', 'apiKey' => 'nk', 'apiPassword' => 'np-SECRET']],
    'deSEC' => [DnsProvider::Desec, ['token' => 'desec-SECRET']],
]);

test('providers the edge image lacks are neither offered nor rendered', function (): void {
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();
    $team = Team::factory()->create();
    [$edge] = Projects::server($team);
    $edge->forceFill(['shared_edge_approved_at' => now()])->save();
    $project = Projects::create($team, 'shop');
    Projects::image($project, 'web');
    resolve(SetDnsProvider::class)->handle(DnsProvider::Gandi, ['token' => 'gandi-SECRET'], AuditActor::system());

    // An older launchroom-caddy build: Cloudflare and Hetzner only.
    config(['launchroom.ingress.dns_providers' => ['cloudflare', 'hetzner']]);
    deployIngress($project->production()->sole());
    Projects::report($edge);
    Projects::report($edge);

    expect(renderFor($edge))->not->toHaveKey('ingress')
        ->and(collect(resolve(IngressSettingsView::class)->get()->providers)->pluck('value')->all())->toBe([DnsProvider::Cloudflare, DnsProvider::Hetzner]);

    expect(fn () => resolve(SetDnsProvider::class)->handle(DnsProvider::Porkbun, ['apiKey' => 'a', 'secretKey' => 'b'], AuditActor::system()))
        ->toThrow(DomainRuleViolation::class);
});

test('credentials stored before per-provider fields still work', function (): void {
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com', 'dns_provider' => DnsProvider::Cloudflare])->save();
    resolve(IngressSecrets::class)->put(IngressCredentialPurpose::DnsToken, 'legacy-cf-token');

    expect(resolve(DnsCredentials::class)->for(DnsProvider::Cloudflare))->toBe(['token' => 'legacy-cf-token'])
        ->and(resolve(DnsCredentials::class)->for(DnsProvider::Route53))->toBeNull();

    // Keeping the provider and sending nothing keeps the stored token.
    resolve(SetDnsProvider::class)->handle(DnsProvider::Cloudflare, [], AuditActor::system());
    expect(resolve(DnsCredentials::class)->for(DnsProvider::Cloudflare))->toBe(['token' => 'legacy-cf-token']);

    // Switching providers needs every field.
    expect(fn () => resolve(SetDnsProvider::class)->handle(DnsProvider::Route53, ['accessKeyId' => 'AKIAEXAMPLE123456'], AuditActor::system()))
        ->toThrow(DomainRuleViolation::class);
    expect(fn () => resolve(SetDnsProvider::class)->handle(DnsProvider::Netcup, ['customerNumber' => 'abc', 'apiKey' => 'k', 'apiPassword' => 'p'], AuditActor::system()))
        ->toThrow(DomainRuleViolation::class);
});

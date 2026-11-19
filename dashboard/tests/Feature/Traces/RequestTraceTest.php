<?php

declare(strict_types=1);

use App\Actions\Servers\RenderNodeState;
use App\Actions\Traces\DecodeTrace;
use App\Actions\Traces\ProvisionTraceKeys;
use App\Data\Protocol\V1\IngressSpec;
use App\Data\Protocol\V1\NodeState;
use App\Data\Protocol\V1\RouteSpec;
use App\Data\Protocol\V1\SystemContainer;
use App\Data\Protocol\V1\SystemSpec;
use App\Data\Protocol\V1\TailscaleSpec;
use App\Enums\DaemonFeature;
use App\Enums\DomainKind;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Events\NodeStateInvalidated;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Traces\TraceKey;
use App\Models\User;
use App\Services\NodeStates\DashboardRoute;
use App\Services\NodeStates\FeatureGate;
use App\Services\Traces\TraceKeys;
use App\Support\Ingress\RouteId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;
use Tests\Support\Security;
use Tests\Support\Traces;

/*
| Request trace on the dashboard (docs/23 §4–6): per-edge keys (created,
| rotated after 30 days, readable 90 days after retirement), what the NodeState carries
| for edges with `requestTrace` (and not for others), the decoder against
| real keys, and who sees what on GET /trace.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    CarbonImmutable::setTestNow('2026-11-19 12:00:00');
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    [$this->server, $this->keypair] = Projects::server($this->team);
    $this->server->forceFill(['name' => 'fra-1', 'daemon_features' => [DaemonFeature::Waf->value, DaemonFeature::RequestTrace->value]])->save();
    $this->project = Projects::create($this->team, 'shop');
    [$this->service, $this->instance] = Security::liveService($this->project);
    Projects::report($this->server);
    resolve(ProvisionTraceKeys::class)->handle();
    $this->key = TraceKey::query()->where('server_id', $this->server->id)->sole();
    $this->domain = Domain::query()->where('service_instance_id', $this->instance->id)->where('kind', DomainKind::Custom)->sole();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A trace header of the test edge with $fields over the defaults of a 200
 * from the app.
 *
 * @param  array<string, mixed>  $fields
 */
function traceHeader(array $fields = [], ?TraceKey $key = null): string
{
    $key ??= test()->key;

    return Traces::encode(array_filter([
        'receivedAt' => now()->getTimestampMs(),
        'requestId' => '3f1c9a2e-8b7d-4e6f-a5b4-c3d2e1f00a1b',
        'edge' => test()->server->public_id,
        'route' => RouteId::forDomain(test()->domain->public_id),
        'service' => test()->instance->public_id,
        'method' => 'GET',
        'path' => '/api/users/:id',
        'protocol' => 3,
        'status' => 200,
        'origin' => 1,
        'edgeTime' => 12_400,
        'upstreamTime' => 11_000,
        ...$fields,
    ], static fn (mixed $value): bool => $value !== null), $key->id, resolve(TraceKeys::class)->reveal($key));
}

/**
 * @return array<string, mixed>
 */
function tracePage(User $user, string $t): array
{
    $props = [];
    test()->actingAs($user)->get('/trace?t='.rawurlencode($t))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props): void {
            $page->component('trace');
            $props = $page->toArray()['props'];
        });

    return $props;
}

/**
 * @return array<string, mixed>
 */
function traceNodeState(Server $server): array
{
    $document = resolve(RenderNodeState::class)->handle($server->refresh())->version->document;
    expect(ProtocolSchemas::errors('node-state', $document))->toBe([]);

    return json_decode($document, true, 64, JSON_THROW_ON_ERROR);
}

test('every edge with requestTrace gets its own key, and only those', function (): void {
    [$other] = Projects::server($this->team, ip: '203.0.113.8');
    $other->forceFill(['daemon_features' => [DaemonFeature::Waf->value]])->save();
    [$builder] = Projects::server($this->team, roles: [ServerRole::Runtime], ip: '203.0.113.9');
    $builder->forceFill(['daemon_features' => [DaemonFeature::RequestTrace->value]])->save();

    expect(resolve(ProvisionTraceKeys::class)->handle())->toBe([])
        ->and(TraceKey::query()->count())->toBe(1)
        ->and($this->key->id)->toBeGreaterThan(0)->toBeLessThanOrEqual(TraceKey::MAX_ID)
        ->and(strlen(resolve(TraceKeys::class)->reveal($this->key)))->toBe(32)
        ->and($this->key->toArray())->not->toHaveKeys(['ciphertext', 'wrapped_dek']);
});

test('keys rotate after 30 days, old ones stay readable until 90 days after their retirement', function (): void {
    Event::fake([NodeStateInvalidated::class]);
    $old = traceHeader();

    CarbonImmutable::setTestNow(now()->addDays(31));
    expect(resolve(ProvisionTraceKeys::class)->handle())->toBe([$this->server->id]);
    Event::assertDispatched(NodeStateInvalidated::class, static fn (NodeStateInvalidated $e): bool => in_array(test()->server->id, $e->serverIds, true));

    $current = resolve(TraceKeys::class)->current($this->server);
    expect($current?->id)->not->toBe($this->key->id)
        ->and($this->key->refresh()->retired_at)->not->toBeNull()
        ->and(resolve(DecodeTrace::class)->handle($old, $this->team))->not->toBeNull()
        ->and(resolve(DecodeTrace::class)->handle(traceHeader(key: $current), $this->team))->not->toBeNull();

    // A trace from the key's last day stays readable for 90 days …
    CarbonImmutable::setTestNow(now()->addDays(89));
    expect(resolve(DecodeTrace::class)->handle($old, $this->team))->not->toBeNull();

    // … then the old key can't open anything, even before the cleanup ran …
    CarbonImmutable::setTestNow(now()->addDays(1));
    expect(resolve(DecodeTrace::class)->handle($old, $this->team))->toBeNull();

    // … and the scheduled run deletes it.
    resolve(ProvisionTraceKeys::class)->handle();
    expect(TraceKey::query()->whereKey($this->key->id)->exists())->toBeFalse();
});

test('the NodeState carries the sealed key, the route service and nothing for edges without the feature', function (): void {
    $state = traceNodeState($this->server);
    $box = sodium_crypto_box_seal_open((string) base64_decode((string) $state['ingress']['trace']['sealed'], true), $this->keypair);
    $secret = json_decode((string) $box, true, 8, JSON_THROW_ON_ERROR);

    expect($secret)->toBe([
        'serverId' => $this->server->public_id,
        'purpose' => 'trace',
        'values' => ['key' => base64_encode(resolve(TraceKeys::class)->reveal($this->key)), 'keyId' => (string) $this->key->id],
    ]);

    expect($state['routes'])->not->toBeEmpty()
        ->and(array_unique(array_column($state['routes'], 'service')))->toBe([$this->instance->public_id]);

    // A re-render with the same key is the same version (fingerprints, not the randomized box).
    $version = $this->server->refresh()->desired_version;
    traceNodeState($this->server);
    expect($this->server->refresh()->desired_version)->toBe($version);

    $this->server->forceFill(['daemon_features' => [DaemonFeature::Waf->value]])->save();
    $without = traceNodeState($this->server);

    expect($without)->not->toHaveKey('ingress.trace')
        ->and(collect($without['routes'])->pluck('service')->filter()->all())->toBe([]);
});

test('servers without the feature lose the upstream server too', function (): void {
    $state = new NodeState(
        serverId: $this->server->public_id,
        version: 1,
        issuedAt: now()->toImmutable(),
        system: new SystemSpec(new SystemContainer(false), new SystemContainer(false), new TailscaleSpec(enabled: false)),
        routes: [new RouteSpec('rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9', ['shop.example.com'], 'acme', [['address' => '100.64.0.9:31002', 'server' => 'srv_01J9Z8X7W6V5T4S3R2Q1P0N9MM']], service: $this->instance->public_id)],
        ingress: new IngressSpec(trace: ['sealed' => 'x', 'fingerprint' => 'y']),
    );

    $this->server->forceFill(['daemon_features' => []])->save();
    $down = FeatureGate::downgrade($state, $this->server);

    expect($down->routes[0]->upstreams)->toBe([['address' => '100.64.0.9:31002']])
        ->and($down->routes[0]->service)->toBeNull()
        ->and($down->ingress)->toBeNull();
});

test('without t the page shows the paste field', function (): void {
    $this->actingAs($this->owner)->get('/trace')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('trace')->missing('trace')->missing('error')->where('team.name', $this->team->name));
});

test('an answer from the app shows the path, the service and its logs around the request', function (): void {
    $props = tracePage($this->owner, 'Launchroom-Trace: '.traceHeader());
    $trace = $props['trace'];

    expect($trace['summary'])->toBe($this->service->name.' answered with 200.')
        ->and($trace['requestClass'])->toBe('success')
        ->and($trace['endsAt'])->toBe('service')
        ->and($trace['origin'])->toBe('app')
        ->and($trace['receivedAt'])->toBe('2026-11-19T12:00:00.000Z')
        ->and($trace['host'])->toBe($this->domain->hostname)
        ->and($trace['edge']['id'])->toBe($this->server->public_id)
        ->and($trace['edge']['name'])->toBe('fra-1')
        ->and($trace['upstreamServer']['id'])->toBe($this->server->public_id)
        ->and($trace['sameServer'])->toBeTrue()
        ->and($trace['service']['name'])->toBe($this->service->name)
        ->and($trace['service']['projectName'])->toBe($this->project->name)
        ->and($trace['dashboard'])->toBeFalse()
        ->and($trace['firewall'])->toBeNull()
        ->and($trace['allow'])->toBeNull()
        ->and($trace['logsHref'])->toContain('q=3f1c9a2e-8b7d-4e6f-a5b4-c3d2e1f00a1b')
        ->and($trace['logsHref'])->toContain('from='.(now()->getTimestamp() - 60))
        ->and($trace['logsHref'])->toContain('to='.(now()->getTimestamp() + 60))
        ->and($props)->not->toHaveKey('error');
});

test('a blocked request names its rules, and only members who may add exceptions get the draft', function (): void {
    $header = traceHeader([
        'method' => 'POST', 'path' => '/api/search/:id', 'status' => 403, 'origin' => 2, 'upstreamTime' => null,
        'firewall' => ['mode' => 2, 'outcome' => 2, 'score' => 15, 'rules' => [942100, 941100]],
    ]);

    $trace = tracePage($this->owner, $header)['trace'];

    expect($trace['summary'])->toBe('The firewall blocked this request. 2 rules matched.')
        ->and($trace['requestClass'])->toBe('blocked')
        ->and($trace['endsAt'])->toBe('firewall')
        ->and($trace['upstreamServer'])->toBeNull()
        ->and($trace['firewall']['mode'])->toBe('block')
        ->and($trace['firewall']['outcome'])->toBe('blocked')
        ->and(array_column($trace['firewall']['rules'], 'id'))->toBe([942100, 941100])
        ->and($trace['firewall']['rules'][0]['message'])->not->toBe('')
        ->and($trace['firewallHref'])->toEndWith('/settings/firewall')
        ->and($trace['allow']['path'])->toBe('/api/search')
        ->and(array_column($trace['allow']['rules'], 'rule'))->toBe([942100, 941100])
        ->and($trace['allow']['allowUrl'])->toEndWith('/firewall/exclusions/allow');

    $viewer = tracePage(memberOf($this->team, TeamRole::Viewer), $header)['trace'];

    expect($viewer['firewall']['rules'])->toHaveCount(2)
        ->and($viewer['allow'])->toBeNull();
});

test('upstream failures say why and how often the edge tried', function (): void {
    $trace = tracePage($this->owner, traceHeader(['status' => 504, 'origin' => 3, 'failure' => 2, 'attempts' => 3, 'upstreamServer' => $this->server->public_id]))['trace'];

    expect($trace['summary'])->toBe($this->service->name." didn't answer in time. The edge tried 3 times.")
        ->and($trace['failure'])->toBe('timeout')
        ->and($trace['endsAt'])->toBe('app_server')
        ->and($trace['requestClass'])->toBe('serverError');
});

test('anything that cannot be shown gets one answer', function (callable $input, ?TeamRole $role = null): void {
    $user = $role instanceof TeamRole ? memberOf($this->team, $role) : $this->owner;
    $t = $input();
    $props = tracePage($user, $t);

    expect($props['error'])->toBe(DecodeTrace::NOT_READABLE)
        ->and($props)->not->toHaveKey('trace')
        ->and($props['input'])->toBe(trim($t));
})->with([
    'not a trace' => [fn (): string => 'hello'],
    'tampered' => [fn (): string => substr(traceHeader(), 0, -2).'AA'],
    'edge differs from the key' => [fn (): string => traceHeader(['edge' => 'srv_0000000000000000000000EDGE'])],
    'service of another team' => [fn (): string => traceHeader(['service' => ServiceInstance::query()->where('team_id', '!=', test()->team->id)->value('public_id') ?? 'sin_0000000000000000000000SVC1'])],
    'dashboard route without servers.manage' => [fn (): string => traceHeader(['route' => DashboardRoute::ID, 'service' => null]), TeamRole::Developer],
]);

test('traces of another team\'s edge are refused, even for a member of both', function (): void {
    $header = traceHeader();
    $other = Team::factory()->create();
    $both = memberOf($other, TeamRole::Owner);
    Membership::query()->create(['team_id' => $this->team->id, 'user_id' => $both->id, 'role' => TeamRole::Owner]);

    expect(tracePage($both, $header)['error'])->toBe(DecodeTrace::NOT_READABLE)
        ->and(tracePage(memberOf($other, TeamRole::Owner), $header)['error'])->toBe(DecodeTrace::NOT_READABLE);
});

test('the dashboard\'s own routes are shown to members who manage servers', function (): void {
    $trace = tracePage(memberOf($this->team, TeamRole::Admin), traceHeader(['route' => DashboardRoute::ID, 'service' => null]))['trace'];

    expect($trace['summary'])->toBe('The dashboard answered with 200.')
        ->and($trace['dashboard'])->toBeTrue()
        ->and($trace['service'])->toBeNull()
        ->and($trace['logsHref'])->toBeNull();
});

test('a request no route matched is shown to every member', function (): void {
    $trace = tracePage(memberOf($this->team, TeamRole::Viewer), traceHeader(['route' => null, 'service' => null, 'status' => 404, 'origin' => 6, 'upstreamTime' => null]))['trace'];

    expect($trace['summary'])->toBe('No domain on this server matched the address.')
        ->and($trace['endsAt'])->toBe('edge')
        ->and($trace['host'])->toBeNull()
        ->and($trace['upstreamServer'])->toBeNull();
});

test('the page is rate limited', function (): void {
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($this->owner)->get('/trace?t=x')->assertOk();
    }

    $this->actingAs($this->owner)->get('/trace?t=x')->assertTooManyRequests();
});

test('a replica on another server names that server, domain and deployment routes alike', function (): void {
    [$edge] = Projects::server($this->team, [ServerRole::Edge], ip: '203.0.113.8');
    $edge->forceFill(['daemon_features' => [DaemonFeature::RequestTrace->value]])->save();
    $placement = Placement::query()->where('server_id', $this->server->id)->sole();
    $this->server->forceFill(['mesh_ip' => '100.64.0.7'])->save();
    $placement->forceFill(['mesh_port' => 31001])->save();

    $routes = traceNodeState($edge)['routes'];

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        expect($route['upstreams'])->toBe([['address' => '100.64.0.7:31001', 'server' => $this->server->public_id]])
            ->and($route['service'])->toBe($this->instance->public_id);
    }
});

test('the scheduled command provisions keys', function (): void {
    TraceKey::query()->delete();

    $this->artisan('launchroom:traces:keys')->expectsOutput('New trace keys for 1 server.')->assertSuccessful();
    $this->artisan('launchroom:traces:keys')->expectsOutput('Trace keys are up to date.')->assertSuccessful();
});

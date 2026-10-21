<?php

declare(strict_types=1);

use App\Actions\Servers\RenderNodeState;
use App\Data\Protocol\V1\RouteSpec;
use App\Enums\ServerRole;
use App\Models\Server;
use App\Services\NodeStates\DashboardRoute;
use Tests\Support\ProtocolSchemas;

/*
| The dashboard's system route on the control-plane server (docs/05 §4,
| deploy/control-plane/compose.yml sets LAUNCHROOM_DASHBOARD_UPSTREAM).
*/

function controlPlaneServer(array $attributes = []): Server
{
    return Server::factory()->online()->create([
        'roles' => [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build],
        'is_control_plane' => true,
        ...$attributes,
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function renderedRoutes(Server $server): array
{
    $document = resolve(RenderNodeState::class)->handle($server)->version->document;
    expect(ProtocolSchemas::errors('node-state', $document))->toBe([]);

    return json_decode($document, true, flags: JSON_THROW_ON_ERROR)['routes'];
}

beforeEach(function (): void {
    config([
        'app.url' => 'https://dash.example.com',
        'launchroom.control_plane.dashboard_upstream' => '127.0.0.1:8000',
    ]);
});

test('the control-plane server routes the dashboard host to the dashboard upstream', function (): void {
    expect(renderedRoutes(controlPlaneServer()))->toBe([[
        'id' => DashboardRoute::ID,
        'hosts' => ['dash.example.com'],
        'tls' => 'acme',
        'upstreams' => [['address' => '127.0.0.1:8000']],
    ]]);
});

test('a *.localhost dashboard uses the internal CA', function (): void {
    config(['app.url' => 'https://test.localhost']);

    expect(renderedRoutes(controlPlaneServer())[0]['tls'])->toBe('internal');
});

test('there is no dashboard route without an upstream, off the control plane, without edge or for IP URLs', function (callable $setup): void {
    $server = $setup();

    expect(renderedRoutes($server))->toBe([]);
})->with([
    'no upstream configured' => [function (): Server {
        config(['launchroom.control_plane.dashboard_upstream' => null]);

        return controlPlaneServer();
    }],
    'malformed upstream' => [function (): Server {
        config(['launchroom.control_plane.dashboard_upstream' => 'dashboard:8000']);

        return controlPlaneServer();
    }],
    'not the control plane' => [fn (): Server => controlPlaneServer(['is_control_plane' => false])],
    'no edge role' => [fn (): Server => controlPlaneServer(['roles' => [ServerRole::Runtime]])],
    'APP_URL is an IP' => [function (): Server {
        config(['app.url' => 'http://203.0.113.7:8000']);

        return controlPlaneServer();
    }],
]);

test('an app domain cannot take over the dashboard host', function (): void {
    $routes = resolve(DashboardRoute::class)->apply(controlPlaneServer(), [
        new RouteSpec('rt_01JABCDEFGHJKMNPQRSTVWXYZ0', ['dash.example.com'], 'acme', [['workload' => 'wl_01JABCDEFGHJKMNPQRSTVWXYZ0', 'port' => 3000]]),
        new RouteSpec('rt_01JABCDEFGHJKMNPQRSTVWXYZ1', ['dash.example.com', 'shop.example.com'], 'acme', [['workload' => 'wl_01JABCDEFGHJKMNPQRSTVWXYZ1', 'port' => 3000]], redirectWwwTo: 'dash.example.com'),
    ]);

    expect($routes)->toHaveCount(2)
        ->and($routes[0]->id)->toBe(DashboardRoute::ID)
        ->and($routes[1]->hosts)->toBe(['shop.example.com'])
        ->and($routes[1]->redirectWwwTo)->toBeNull();
});

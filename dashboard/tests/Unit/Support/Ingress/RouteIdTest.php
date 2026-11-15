<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Support\Ingress\DomainRoute;
use App\Support\Ingress\RouteId;

test('route ids map to and from domain and deployment public ids', function (): void {
    expect(RouteId::forDomain('dom_01JB7Z6X9Q3T8M5N2P4R6S8V0W'))->toBe('rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W')
        ->and(RouteId::forDeployment('dpl_01JB7Z6X9Q3T8M5N2P4R6S8V0W'))->toBe('rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W')
        ->and(RouteId::domainPublicId('rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W'))->toBe('dom_01JB7Z6X9Q3T8M5N2P4R6S8V0W')
        ->and(RouteId::deploymentPublicId('rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W'))->toBe('dpl_01JB7Z6X9Q3T8M5N2P4R6S8V0W');
});

test('malformed route ids have no owner', function (string $route): void {
    expect(RouteId::domainPublicId($route))->toBeNull()
        ->and(RouteId::deploymentPublicId($route))->toBeNull();
})->with(['dom_01JB7Z6X9Q3T8M5N2P4R6S8V0W', "rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W\n", '']);

test('network ids map back to environment public ids', function (): void {
    expect(Environment::publicIdFromNetworkId('net_01JB7Z6X9Q3T8M5N2P4R6S8V0W'))->toBe('env_01JB7Z6X9Q3T8M5N2P4R6S8V0W')
        ->and(Environment::publicIdFromNetworkId('lr-e_01jb7z6x9q3t8m5n2p4r6s8v0w'))->toBeNull()
        ->and(Environment::publicIdFromNetworkId('net_nope'))->toBeNull();
});

test('domain routes choose tls, www redirect and noindex in one place', function (): void {
    $route = DomainRoute::serving('rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'shop.example.com', ['shop.example.com', 'www.shop.example.com'], onDemand: true, indexed: false, upstreams: [['workload' => 'wl_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'port' => 3000]]);

    expect($route->tls)->toBe('on_demand')
        ->and($route->redirectWwwTo)->toBe('shop.example.com')
        ->and($route->headers)->toBe(['X-Robots-Tag' => 'noindex'])
        ->and(DomainRoute::serving('rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'app.localhost', ['app.localhost'], onDemand: true, indexed: true, upstreams: [])->toProtocol())
        ->toBe(['id' => 'rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'hosts' => ['app.localhost'], 'tls' => 'internal', 'upstreams' => []])
        ->and(DomainRoute::stopped('rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'web.apps.example.com', ['web.apps.example.com'], onDemand: false, indexed: true)->toProtocol())
        ->toBe(['id' => 'rt_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'hosts' => ['web.apps.example.com'], 'tls' => 'acme', 'upstreams' => [], 'stopped' => true]);
});

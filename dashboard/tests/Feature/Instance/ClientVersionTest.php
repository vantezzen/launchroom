<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;

it('answers the asset version without a session, never cached', function (): void {
    $expected = resolve(HandleInertiaRequests::class)->version(Request::create('/'));

    $response = $this->getJson('/up/version');

    $response->assertOk()
        ->assertExactJson(['version' => $expected])
        ->assertHeader('X-Robots-Tag', 'noindex');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([]);
});

it('answers the version the pages carry, so a new build is detected', function (): void {
    config()->set('app.asset_url', 'https://assets.example.test/build-42');

    $version = $this->getJson('/up/version')->assertOk()->json('version');

    expect($version)->toBe(hash('xxh128', 'https://assets.example.test/build-42'));

    // A page built from the same assets loads; an older one is told to reload.
    $this->get('/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->assertOk()
        ->assertJsonPath('version', $version);
    $this->get('/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'old-build'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

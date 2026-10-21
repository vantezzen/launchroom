<?php

declare(strict_types=1);

use App\Services\Crypto\Signer;

/*
| /install/server.sh (docs/11 §3). Uses the real deploy/install/server.sh and
| deploy/daemon/compose.yml, mounted at launchroom.install.scripts_path.
*/

const INSTALL_TEST_DAEMON_IMAGE = 'ghcr.io/vantezzen/launchroomd@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    config([
        'app.url' => 'https://dash.example.com',
        'launchroom.daemon.image' => INSTALL_TEST_DAEMON_IMAGE,
        'launchroom.version' => '1.4.2',
    ]);
});

test('it serves the daemon installer with the instance URL, CP key and daemon image filled in', function (): void {
    $response = $this->get('/install/server.sh');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertCookieMissing(config()->string('session.cookie'));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $script = (string) $response->getContent();
    $key = resolve(Signer::class)->publicKey();

    expect($script)->toStartWith("#!/bin/sh\n")
        ->toContain('LR_CONTROL_PLANE_URL="${LR_CONTROL_PLANE_URL:-https://dash.example.com}"')
        ->toContain('LR_CP_KEY="${LR_CP_KEY:-'.$key.'}"')
        ->toContain('LR_DAEMON_IMAGE="${LR_DAEMON_IMAGE:-'.INSTALL_TEST_DAEMON_IMAGE.'}"')
        // The release whose signed bundle the script verifies (docs/11 §3).
        ->toContain('LR_RELEASE_VERSION="${LR_RELEASE_VERSION:-1.4.2}"')
        // The daemon compose file is embedded in the heredoc.
        ->toContain("<<'LAUNCHROOM_DAEMON_COMPOSE_EOF'\n# launchroomd for servers")
        ->toContain('network_mode: host')
        ->not->toContain("\n#__LAUNCHROOM_DAEMON_COMPOSE__\n")
        ->not->toContain('__LAUNCHROOM_URL__')
        ->not->toContain('__LAUNCHROOM_CP_KEY__')
        ->not->toContain('__LAUNCHROOM_DAEMON_IMAGE__')
        ->not->toContain('__LAUNCHROOM_VERSION__')
        // Never a join token.
        ->not->toMatch('/lr_jt_[A-Za-z0-9_-]{43}/');
});

test('it needs no session and is rate limited', function (): void {
    foreach (range(1, 30) as $_) {
        $this->get('/install/server.sh')->assertOk();
    }

    $this->get('/install/server.sh')->assertTooManyRequests();
});

test('it refuses to template values that are not safe in a shell script', function (string $key, string $value): void {
    config([$key => $value]);

    $response = $this->get('/install/server.sh');

    $response->assertServiceUnavailable();
    expect((string) $response->getContent())->toContain('exit 1')
        ->not->toContain($value);
})->with([
    'url with a quote' => ['app.url', "https://dash.example.com/'\$(id)"],
    'url with a path' => ['app.url', 'https://dash.example.com/x;id'],
    'url with a newline' => ['app.url', "https://dash.example.com\nid"],
    'non-http url' => ['app.url', 'file:///etc/passwd'],
    'image with a space' => ['launchroom.daemon.image', 'ghcr.io/x/daemon; id'],
    'image with a quote' => ['launchroom.daemon.image', 'ghcr.io/x/daemon"$(id)"'],
    'version with a quote' => ['launchroom.version', "1.0.0'\$(id)"],
    'version with a v' => ['launchroom.version', 'v1.0.0'],
    'version with a newline' => ['launchroom.version', "1.0.0\nid"],
]);

test('it answers 503 without a daemon image or the script files', function (string $key): void {
    config([$key => $key === 'launchroom.daemon.image' ? null : sys_get_temp_dir().'/launchroom-missing-'.bin2hex(random_bytes(4))]);

    $this->get('/install/server.sh')->assertServiceUnavailable();
})->with(['launchroom.daemon.image', 'launchroom.install.scripts_path']);

test('it answers 503 before the signing key exists', function (): void {
    $this->app->forgetInstance(Signer::class);
    config(['launchroom.signing_key_path' => sys_get_temp_dir().'/launchroom-missing-'.bin2hex(random_bytes(4))]);

    $this->get('/install/server.sh')->assertServiceUnavailable();
});

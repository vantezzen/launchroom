<?php

declare(strict_types=1);

use App\Support\DocsUrl;

it('links into the public docs by page path', function (): void {
    config()->set('launchroom.docs_url', 'https://launchroom.vantezzen.io/docs');

    expect(DocsUrl::base())->toBe('https://launchroom.vantezzen.io/docs')
        ->and(DocsUrl::to('servers/high-availability-ingress'))->toBe('https://launchroom.vantezzen.io/docs/servers/high-availability-ingress')
        ->and(DocsUrl::to('/builds/git-providers'))->toBe('https://launchroom.vantezzen.io/docs/builds/git-providers');
});

it('follows a configured docs mirror without a trailing slash', function (): void {
    config()->set('launchroom.docs_url', 'https://docs.internal.example/launchroom/');

    expect(DocsUrl::to('operations/updates'))->toBe('https://docs.internal.example/launchroom/operations/updates');
});

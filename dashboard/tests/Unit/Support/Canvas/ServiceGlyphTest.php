<?php

declare(strict_types=1);

use App\Data\Services\GitSource;
use App\Enums\ServiceKind;
use App\Models\Service;
use App\Support\Canvas\ServiceGlyph;

it('names an image by repository and tag, without its digest', function (): void {
    expect(ServiceGlyph::imageName('nginx:1.27'))->toBe('nginx:1.27')
        ->and(ServiceGlyph::imageName('ghcr.io/acme/shop-web@sha256:'.str_repeat('4b', 32)))->toBe('acme/shop-web');
});

it('shows an app with the framework its build detected, else its git host (D108)', function (string $framework, string $glyph): void {
    $app = new Service;
    $app->forceFill(['kind' => ServiceKind::App, 'source' => new GitSource(1, 'acme/shop')]);

    expect(ServiceGlyph::logo($app, $framework))->toBe($glyph)
        ->and(in_array($glyph, ServiceGlyph::KNOWN, true))->toBeTrue();
})->with([
    ['Next.js', 'nextjs'],
    ['Nuxt', 'nuxt'],
    ['Astro', 'astro'],
    ['SvelteKit', 'svelte'],
    ['React Router', 'remix'],
    ['Laravel', 'laravel'],
    ['Symfony', 'php'],
    ['Rails', 'rails'],
    ['Express', 'node'],
    ['Go', 'go'],
    ['Phoenix', 'github'],
]);

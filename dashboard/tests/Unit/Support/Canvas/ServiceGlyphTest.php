<?php

declare(strict_types=1);

use App\Support\Canvas\ServiceGlyph;

it('names an image by repository and tag, without its digest', function (): void {
    expect(ServiceGlyph::imageName('nginx:1.27'))->toBe('nginx:1.27')
        ->and(ServiceGlyph::imageName('ghcr.io/acme/shop-web@sha256:'.str_repeat('4b', 32)))->toBe('acme/shop-web');
});

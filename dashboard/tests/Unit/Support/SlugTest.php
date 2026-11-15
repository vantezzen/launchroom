<?php

declare(strict_types=1);

use App\Support\Slug;

$vectors = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/slug-vectors.json'), true, flags: JSON_THROW_ON_ERROR);

test('Slug::from matches the shared vectors the TypeScript mirror reads', function (string $name, string $fallback, string $slug): void {
    expect(Slug::from($name, $fallback))->toBe($slug);
})->with(array_map(static fn (array $vector): array => [$vector['name'], $vector['fallback'], $vector['slug']], $vectors));

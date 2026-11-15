<?php

declare(strict_types=1);

use App\Support\PublicId;
use Illuminate\Database\Eloquent\Model;

test('every model has its own public-id prefix', function (): void {
    $prefixes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Models'), FilesystemIterator::SKIP_DOTS)) as $file) {
        $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(app_path('Models')) + 1));

        if (class_exists($class) && is_subclass_of($class, Model::class) && method_exists($class, 'publicIdPrefix')) {
            $prefixes[$class::publicIdPrefix()][] = $class;
        }
    }

    expect(array_filter($prefixes, static fn (array $classes): bool => count($classes) > 1))->toBe([])
        ->and(array_keys($prefixes))->each(fn ($prefix) => $prefix->toMatch('/^'.PublicId::PREFIX_PATTERN.'$/D'));
});

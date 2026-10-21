<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

// CI runs `vendor/bin/rector --dry-run` (composer rector:check); apply locally
// with `vendor/bin/rector`. Pint runs after Rector to normalise formatting.
return RectorConfig::configure()
    // Cache on the bind mount: `make` runs Rector in throwaway containers (/tmp is lost).
    ->withCache(__DIR__.'/storage/framework/cache/rector')
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/bootstrap/providers.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_130,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])
    ->withSkip([
        // Arch rules read better with namespaces/classes as plain strings.
        StringClassNameToClassConstantRector::class => [__DIR__.'/tests/Arch'],
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    // Bounded parallelism: with many workers, the dev stack's bind mount
    // (Docker Desktop/OrbStack file sharing) intermittently fails file opens.
    ->withParallel(timeoutSeconds: 300, maxNumberOfProcess: 4, jobSize: 16);

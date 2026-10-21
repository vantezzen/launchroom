<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * What the launchroom Next.js deployment adapter reported about a build
 * (protocol NextBuildInfo, docs/04 §3): versions, standalone entry,
 * middleware, image optimization, cache handler and route counts.
 */
final class NextBuildInfo extends Data
{
    /**
     * @param  list<string>|null  $isrRoutes  prerendered paths with time-based revalidation (first 32)
     */
    public function __construct(
        #[Max(64), Regex('/^v?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D')]
        public string $adapterVersion,
        #[Max(64), Regex('/^v?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D')]
        public string $nextVersion,
        public bool $middleware,
        public bool $imageOptimization,
        public bool $sharp,
        public bool $cacheHandler,
        public NextRouteCounts $routes,
        #[Max(128)]
        public ?string $deploymentId = null,
        /** The standalone server relative to the app directory (".next/standalone/server.js"). */
        #[Max(1024)]
        public ?string $serverEntry = null,
        /** "nodejs" or "edge". */
        #[Max(64)]
        public ?string $middlewareRuntime = null,
        public ?array $isrRoutes = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['isrRoutes' => ['nullable', 'array', 'max:32'], 'isrRoutes.*' => ['string', 'max:1024']];
    }
}

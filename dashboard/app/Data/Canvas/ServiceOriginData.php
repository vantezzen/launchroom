<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * Where a service comes from, for the service panel's meta line (docs/08
 * §13): the repository (at the branch it deploys), the image, the database
 * engine, or the app a worker or cron belongs to.
 */
final class ServiceOriginData extends Data
{
    public function __construct(
        /** `repository`, `image`, `database` or `process`. */
        public string $kind,
        /** `acme/shop`, `nginx:1.27`, `PostgreSQL 17`, `Worker of web`. */
        public string $label,
        /** The provider's or registry's page; null when there is none (plain git servers, private registries). */
        public ?string $href = null,
        /** The branch a repository deploys, when one is set. */
        public ?string $branch = null,
        /** Where it lives in words, for the tooltip: "GitHub", "Docker Hub". */
        public ?string $host = null,
    ) {}
}

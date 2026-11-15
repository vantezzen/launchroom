<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\DatabaseEngine;
use App\Enums\Git\GitProviderKind;
use App\Enums\ServiceSourceType;
use Spatie\LaravelData\Data;

/**
 * The service source as the Settings tab shows it (one shape for all kinds).
 */
final class ServiceSourceData extends Data
{
    /**
     * @param  list<string>  $versions  available engine versions
     */
    public function __construct(
        public ServiceSourceType $type,
        public ?string $repository = null,
        public ?string $rootDirectory = null,
        /** Effective branch in this environment. */
        public ?string $branch = null,
        /** Set when this environment overrides the environment's branch. */
        public ?string $branchOverride = null,
        public ?string $environmentBranch = null,
        public ?string $image = null,
        public ?DatabaseEngine $engine = null,
        public ?string $version = null,
        public array $versions = [],
        /** Name of the parent app of a worker or cron. */
        public ?string $parentName = null,
        public ?GitProviderKind $provider = null,
    ) {}
}

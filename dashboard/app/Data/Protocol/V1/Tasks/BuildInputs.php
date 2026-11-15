<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\Sealed;
use App\Enums\DaemonFeature;
use Spatie\LaravelData\Data;

/**
 * The inputs build and plan tasks share (protocol BuildInputs, embedded in
 * both specs). `cloneToken`, `sshKey` and `env` are sealed at lease time.
 */
final class BuildInputs extends Data
{
    /**
     * @param  list<string>|null  $buildArgs
     */
    public function __construct(
        public string $service,
        public string $environment,
        public RepoSpec $repo,
        public BuilderSpec $builder,
        public string $platform,
        public string $cacheKey,
        public ?string $deployment = null,
        public ?string $rootDir = null,
        public ?array $buildArgs = null,
        public ?Sealed $cloneToken = null,
        public ?Sealed $sshKey = null,
        public ?Sealed $env = null,
    ) {}

    /**
     * The repository's (RepoSpec::needsGitProviders()), an inline Dockerfile
     * (docs/20 §6), PHP database drivers (docs/04 §3.1).
     *
     * @return list<DaemonFeature>
     */
    public function requiredFeatures(): array
    {
        return array_values(array_filter([
            $this->repo->needsGitProviders() ? DaemonFeature::GitProviders : null,
            $this->builder->dockerfile !== null ? DaemonFeature::GeneratedDockerfile : null,
            $this->builder->phpExtensions !== null ? DaemonFeature::PhpExtensions : null,
        ]));
    }
}

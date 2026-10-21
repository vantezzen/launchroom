<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Enums\Git\GitProviderKind;
use Spatie\LaravelData\Data;

/**
 * One ref to watch: `installationId` is the provider's connection row
 * (`github_installations.id` or `git_connections.id`), `repositoryId` the
 * provider's numeric repository id, `ref` a full ref (`refs/heads/main`).
 */
final class TrackedRef extends Data
{
    public function __construct(
        public int $installationId,
        public int $repositoryId,
        public string $ref,
        public GitProviderKind $provider = GitProviderKind::GitHub,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Data\Services\GitSource;
use App\Enums\Git\GitProviderKind;

/**
 * A repository as the provider contract sees it (docs/19 §2): provider, the
 * connection row (`github_installations.id` or `git_connections.id`), the
 * provider's numeric repository id (0 for plain git) and the team that owns
 * it. Implementations look their rows up within `teamId` only (docs/09 R18).
 */
final readonly class RepositoryRef
{
    public function __construct(
        public GitProviderKind $provider,
        public int $teamId,
        public ?int $connectionId,
        public int $repositoryId,
        public string $fullName,
        /** Plain git only: the remote URL. */
        public ?string $cloneUrl = null,
    ) {}

    public static function fromSource(int $teamId, GitSource $source): self
    {
        return new self($source->kind(), $teamId, $source->installationId, $source->repositoryId, $source->fullName, $source->cloneUrl);
    }

    /** Stable key for caches and locks. */
    public function key(): string
    {
        return $this->provider->value.':'.($this->connectionId ?? 0).':'.$this->repositoryId;
    }
}

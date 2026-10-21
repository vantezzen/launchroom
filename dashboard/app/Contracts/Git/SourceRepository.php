<?php

declare(strict_types=1);

namespace App\Contracts\Git;

use App\Data\Git\GitRepositoryData;
use App\Data\Git\RepositoryRef;
use App\Models\Git\GitHubRepository;
use App\Models\Git\GitRepository;

/**
 * A repository in the picker and the import flow, from any provider
 * (docs/19 §2): {@see GitHubRepository} or {@see GitRepository}.
 */
interface SourceRepository
{
    public function repositoryRef(): RepositoryRef;

    public function repositoryPublicId(): string;

    public function repositoryTeamId(): int;

    public function repositoryFullName(): string;

    public function repositoryDefaultBranch(): ?string;

    /**
     * The `GitSource` array a service built from this repository stores.
     *
     * @return array<string, mixed>
     */
    public function gitSource(string $rootDirectory = '', ?string $branch = null): array;

    public function pickerData(): GitRepositoryData;
}

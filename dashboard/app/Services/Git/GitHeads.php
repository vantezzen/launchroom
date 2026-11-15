<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Exceptions\GitHeadUnavailable;
use App\Models\ServiceInstance;
use App\Support\Git\DeployBranch;
use App\Support\Git\GitRef;

/**
 * Which branch a service instance deploys, and which commit that branch
 * points at now (for Deploy of staged changes and plan previews; pushes carry
 * their commit), asked of the source's provider (docs/19 §2).
 */
final readonly class GitHeads
{
    public function __construct(
        private GitProviders $providers,
    ) {}

    public function branchFor(ServiceInstance $instance, GitSource $source): string
    {
        $branch = DeployBranch::for($instance, $source)
            ?? $this->providers->forSource($source)->defaultBranch(RepositoryRef::fromSource($instance->team_id, $source));

        return DeployBranch::isBranch($branch) ? $branch : 'main';
    }

    /**
     * @throws GitHeadUnavailable
     */
    public function head(int $teamId, GitSource $source, string $branch): string
    {
        $sha = $this->providers->forSource($source)->head(RepositoryRef::fromSource($teamId, $source), $branch)->sha;

        if (! GitRef::isSha($sha)) {
            throw new GitHeadUnavailable("Branch {$branch} of {$source->fullName} has no commit launchroom can build.");
        }

        return $sha;
    }
}

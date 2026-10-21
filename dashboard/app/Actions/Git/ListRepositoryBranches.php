<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\GitBranchData;
use App\Models\Git\GitHubRepository;
use App\Services\GitHub\Dto\BranchInfo;
use App\Services\GitHub\GitHubClientFactory;
use App\Services\GitHub\JsonObject;
use App\Services\GitHub\TokenScope;

/**
 * Branches of a cached repository for the repo picker (first 300).
 */
final readonly class ListRepositoryBranches
{
    private const int MAX_PAGES = 3;

    public function __construct(
        private GitHubClientFactory $clients,
    ) {}

    /**
     * @return list<GitBranchData>
     */
    public function handle(GitHubRepository $repository): array
    {
        $installation = $repository->installation;
        $client = $this->clients->for($installation->app);
        $branches = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $client->asInstallation(
                $installation->installation_id,
                TokenScope::read($repository->github_repo_id),
                'GET',
                "/repos/{$repository->full_name}/branches",
                query: ['per_page' => 100, 'page' => $page],
            );

            $items = JsonObject::decodeList($response->body());

            foreach ($items as $item) {
                $branches[] = GitBranchData::fromBranch(BranchInfo::fromJson($item), $repository->default_branch);
            }

            if (count($items) < 100) {
                break;
            }
        }

        return $branches;
    }
}

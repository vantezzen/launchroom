<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Models\ServiceInstance;
use App\Support\Git\GitRef;
use App\Support\Git\RefAdvertisement;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Which branch a service instance deploys, and which commit that branch
 * points at now (for Deploy of staged changes and plan previews; pushes carry
 * their commit). Connected sources ask their provider (docs/19 §2); plain
 * unconnected git sources (local dev) read the smart-HTTP ref advertisement.
 */
final readonly class GitHeads
{
    public function __construct(
        private GitProviders $providers,
        private HttpFactory $http,
    ) {}

    /**
     * Branch override → environment trigger branch (unless it is a glob) →
     * the source's default branch → the repository's default → main.
     */
    public function branchFor(ServiceInstance $instance, GitSource $source): string
    {
        $candidates = [
            $instance->branch_override,
            $instance->environment->triggers->branch,
            $source->branch,
            $source->isUnconnectedPlainGit() ? null : $this->providers->forSource($source)->defaultBranch(RepositoryRef::fromSource($instance->team_id, $source)),
        ];

        foreach ($candidates as $branch) {
            if (is_string($branch) && $branch !== '' && strpbrk($branch, '*?[') === false) {
                return $branch;
            }
        }

        return 'main';
    }

    /**
     * @throws GitHeadUnavailable
     */
    public function head(int $teamId, GitSource $source, string $branch): string
    {
        $sha = $source->isUnconnectedPlainGit()
            ? $this->plainHead($source, $branch)
            : $this->providers->forSource($source)->head(RepositoryRef::fromSource($teamId, $source), $branch)->sha;

        if (! GitRef::isSha($sha)) {
            throw new GitHeadUnavailable("Branch {$branch} of {$source->fullName} has no commit launchroom can build.");
        }

        return $sha;
    }

    /**
     * `GET <url>/info/refs?service=git-upload-pack` and find refs/heads/<branch>
     * in the pkt-line advertisement.
     */
    private function plainHead(GitSource $source, string $branch): string
    {
        try {
            $source->assertUsable();
        } catch (DomainRuleViolation $e) {
            throw new GitHeadUnavailable($e->getMessage(), $e->getCode(), $e);
        }

        try {
            $response = $this->http
                ->timeout(config()->integer('launchroom.git.head_timeout_seconds'))
                ->withoutRedirecting()
                ->get(rtrim((string) $source->cloneUrl, '/').'/info/refs', ['service' => 'git-upload-pack']);
        } catch (Throwable) {
            throw new GitHeadUnavailable("The git remote of {$source->fullName} is not reachable.");
        }

        if (! $response->successful()) {
            throw new GitHeadUnavailable("The git remote of {$source->fullName} answered {$response->status()}.");
        }

        $sha = self::refFromAdvertisement($response->body(), 'refs/heads/'.$branch);

        if ($sha === null) {
            throw new GitHeadUnavailable("Branch {$branch} does not exist in {$source->fullName}.");
        }

        return $sha;
    }

    /**
     * The SHA a ref points at in a smart-HTTP ref advertisement, or null.
     */
    public static function refFromAdvertisement(string $body, string $ref): ?string
    {
        return RefAdvertisement::parse($body)[$ref] ?? null;
    }
}

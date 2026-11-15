<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Contracts\Git\ApiConnectionProvider;
use App\Contracts\Git\ConnectionProvider;
use App\Contracts\Git\GitProvider;
use App\Contracts\Git\SourceRepository;
use App\Data\Services\GitSource;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitHubRepository;
use App\Models\Git\GitRepository;
use App\Models\Team;
use App\Services\Git\Providers\GiteaProvider;
use App\Services\Git\Providers\GitHub\GitHubProvider;
use App\Services\Git\Providers\GitLabProvider;
use App\Services\Git\Providers\PlainGitProvider;
use App\Services\Git\Providers\UnconnectedGitProvider;
use App\Support\PublicId;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The provider registry (docs/19 §2): one {@see GitProvider} per kind, and
 * team-scoped lookup of picker repositories by public id (`ghr_…` GitHub,
 * `gir_…` everything else).
 */
final readonly class GitProviders
{
    public const string REPOSITORY_ID_PATTERN = '/^(?:ghr|gir)_'.PublicId::ULID_PATTERN.'$/D';

    public function __construct(
        private Container $container,
    ) {}

    public function for(GitProviderKind $kind): GitProvider
    {
        return $this->container->make(match ($kind) {
            GitProviderKind::GitHub => GitHubProvider::class,
            GitProviderKind::GitLab => GitLabProvider::class,
            GitProviderKind::Gitea => GiteaProvider::class,
            GitProviderKind::Git => PlainGitProvider::class,
        });
    }

    public function connection(GitProviderKind $kind): ConnectionProvider
    {
        $provider = $this->for($kind);

        return $provider instanceof ConnectionProvider ? $provider : throw new LogicException("{$kind->label()} has no git connections.");
    }

    public function api(GitProviderKind $kind): ApiConnectionProvider
    {
        $provider = $this->for($kind);

        return $provider instanceof ApiConnectionProvider ? $provider : throw new LogicException("{$kind->label()} connections have no API.");
    }

    public function forSource(GitSource $source): GitProvider
    {
        if ($source->isUnconnectedPlainGit()) {
            return $this->container->make(UnconnectedGitProvider::class);
        }

        return $this->for($source->provider);
    }

    /**
     * @return list<GitProvider>
     */
    public function all(): array
    {
        return array_map($this->for(...), GitProviderKind::cases());
    }

    /**
     * A repository of $team usable for new services: its installation must
     * not be removed (GitHub) or its connection must be active.
     */
    public function repository(Team $team, string $publicId): ?SourceRepository
    {
        if (PublicId::isValid($publicId, GitHubRepository::publicIdPrefix())) {
            return GitHubRepository::query()
                ->where('team_id', $team->id)
                ->where('public_id', $publicId)
                ->whereRelation('installation', 'removed_at')
                ->with('installation')
                ->first();
        }

        if (PublicId::isValid($publicId, GitRepository::publicIdPrefix())) {
            return GitRepository::query()
                ->where('team_id', $team->id)
                ->where('public_id', $publicId)
                ->whereRelation('gitConnection', 'status', GitConnectionStatus::Active->value)
                ->with('gitConnection')
                ->first();
        }

        return null;
    }
}

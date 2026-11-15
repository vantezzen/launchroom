<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\Git\GitProviderKind;
use Spatie\LaravelData\Data;

/**
 * The commit to clone (protocol RepoSpec). `knownHosts` is filled at lease
 * time for ssh remotes, with the credential.
 */
final class RepoSpec extends Data
{
    /**
     * @param  list<HostKey>|null  $knownHosts
     */
    public function __construct(
        public string $provider,
        public string $cloneUrl,
        public string $sha,
        public ?string $ref = null,
        public ?array $knownHosts = null,
    ) {}

    /**
     * GitLab/Gitea, ssh remotes (pinned host keys, a deploy key) and a
     * fallback ref (docs/19 §6).
     */
    public function needsGitProviders(): bool
    {
        return in_array(GitProviderKind::tryFrom($this->provider), GitProviderKind::apiConnections(), true) || str_starts_with($this->cloneUrl, 'ssh://') || $this->ref !== null;
    }
}

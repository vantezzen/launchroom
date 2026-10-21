<?php

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Contracts\Git\GitProvider;
use App\Contracts\Git\SourceRepository;
use App\Contracts\Git\SshRemotes;
use App\Data\Git\CommitData;
use App\Data\Git\DeploymentStatusReport;
use App\Data\Git\GitBranchData;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Models\Previews\Preview;
use App\Models\Team;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\Exceptions\RepositoryFileUnavailable;
use App\Services\Git\GitConnectionSecrets;
use App\Services\Git\GitCredentials;
use App\Services\Git\Ssh\DeployKey;
use App\Services\Http\SafeHttpClient;
use App\Support\Git\HostKey;
use App\Support\Git\RefAdvertisement;
use App\Support\Git\SshUrl;
use Illuminate\Contracts\Database\Query\Builder;
use Throwable;

/**
 * Any git server (docs/19 §4.2): one connection per repository, SSH with a
 * deploy key and pinned host keys, or a public HTTPS remote. No API: heads
 * come from the ref advertisement, files aren't readable, and there is no
 * deploy feedback.
 */
final readonly class PlainGitProvider implements GitProvider
{
    public function __construct(
        private SshRemotes $ssh,
        private GitConnectionSecrets $secrets,
        private SafeHttpClient $http,
    ) {}

    public function kind(): GitProviderKind
    {
        return GitProviderKind::Git;
    }

    public function searchRepositories(Team $team, string $search): array
    {
        $query = GitRepository::query()
            ->with('gitConnection')
            ->where('team_id', $team->id)
            ->whereHas('gitConnection', static fn (Builder $connection) => $connection->where('provider', GitProviderKind::Git->value)->where('status', GitConnectionStatus::Active->value));

        if ($search !== '') {
            $query->whereLike('full_name', '%'.addcslashes($search, '%_\\').'%');
        }

        return array_values($query->latest('updated_at')->limit(50)->get()->all());
    }

    public function branches(SourceRepository $repository): array
    {
        if (! $repository instanceof GitRepository) {
            return [];
        }

        $branches = [];

        foreach ($this->refs($repository->gitConnection) as $ref => $sha) {
            if (str_starts_with($ref, 'refs/heads/')) {
                $name = substr($ref, 11);
                $branches[] = new GitBranchData($name, $sha, false, $name === $repository->default_branch);
            }
        }

        return $branches;
    }

    public function defaultBranch(RepositoryRef $repository): ?string
    {
        return $this->row($repository)?->default_branch;
    }

    public function head(RepositoryRef $repository, string $branch): CommitData
    {
        $row = $this->row($repository);

        if (! $row instanceof GitRepository || ! $row->gitConnection->isActive()) {
            throw new GitHeadUnavailable("launchroom can't access {$repository->fullName}. Check it in Settings → Git.");
        }

        try {
            $refs = $this->refs($row->gitConnection);
        } catch (GitConnectionUnusable $e) {
            throw new GitHeadUnavailable($e->getMessage(), $e->getCode(), previous: $e);
        }

        $sha = $refs['refs/heads/'.$branch] ?? null;

        if ($sha === null) {
            throw new GitHeadUnavailable("Branch {$branch} does not exist in {$repository->fullName}.");
        }

        return new CommitData($sha, '', null, null, null);
    }

    /**
     * Every advertised ref of a plain git connection (`refs/heads/main` => sha).
     *
     * @return array<string, string>
     *
     * @throws GitConnectionUnusable
     */
    public function refs(GitConnection $connection): array
    {
        if (str_starts_with($connection->base_url, 'ssh://')) {
            $url = SshUrl::parse($connection->base_url);
            $key = $this->secrets->get($connection, 'ssh_private_key');

            if (! $url instanceof SshUrl || $key === null) {
                throw new GitConnectionUnusable('This git server has no deploy key yet.');
            }

            return $this->ssh->refs($url, self::hostKeys($connection), $key, $connection->allow_private_network);
        }

        try {
            $client = $connection->allow_private_network ? $this->http->withPrivateNetwork() : $this->http;
            $response = $client->get(rtrim($connection->base_url, '/').'/info/refs', ['service' => 'git-upload-pack']);
        } catch (Throwable $e) {
            throw new GitConnectionUnusable("{$connection->host()} is not reachable: ".mb_substr($e->getMessage(), 0, 160), $e->getCode(), $e);
        }

        if (! $response->successful()) {
            throw new GitConnectionUnusable("{$connection->host()} answered {$response->status()}. Is the repository public?");
        }

        return RefAdvertisement::parse($response->body());
    }

    /**
     * @return list<HostKey>
     */
    public static function hostKeys(GitConnection $connection): array
    {
        if ($connection->host_keys_confirmed_at === null) {
            return [];
        }

        return array_values(array_filter(array_map(HostKey::fromArray(...), $connection->host_keys ?? [])));
    }

    public function tree(RepositoryRef $repository, string $ref): RepositoryTree
    {
        throw new RepositoryFileUnavailable('Plain git servers have no file API; launchroom looks at the files when it builds.');
    }

    public function fileAtRef(RepositoryRef $repository, string $path, string $ref): ?string
    {
        throw new RepositoryFileUnavailable('Plain git servers have no file API; launchroom looks at the files when it builds.');
    }

    public function cloneUrl(RepositoryRef $repository): string
    {
        $row = $this->row($repository);

        if (! $row instanceof GitRepository) {
            throw DomainRuleViolation::on('source', "launchroom can't access {$repository->fullName} anymore. Check it in Settings → Git.");
        }

        return $row->gitConnection->base_url;
    }

    public function cloneCredentials(RepositoryRef $repository): GitCredentials
    {
        $row = $this->row($repository);
        $connection = $row?->gitConnection;

        if (! $row instanceof GitRepository || ! $connection instanceof GitConnection || ! $connection->isActive()) {
            throw DomainRuleViolation::on('source', "launchroom can't access {$repository->fullName} anymore. Check it in Settings → Git.");
        }

        if ($connection->auth_method !== GitAuthMethod::DeployKey) {
            return new GitCredentials($connection->base_url);
        }

        $key = $this->secrets->get($connection, 'ssh_private_key');
        $hostKeys = self::hostKeys($connection);

        if ($key === null || $hostKeys === []) {
            throw DomainRuleViolation::on('source', "{$repository->fullName} has no confirmed host key or deploy key. Finish it in Settings → Git.");
        }

        return new GitCredentials($connection->base_url, sshKeySeed: DeployKey::seed($key), knownHosts: $hostKeys);
    }

    public function changedFiles(RepositoryRef $repository, int $number, string $baseRef, string $headSha): ?array
    {
        return null;
    }

    public function pullRequestOpen(Preview $preview): ?bool
    {
        return null;
    }

    public function compareUrl(RepositoryRef $repository, string $from, string $to): ?string
    {
        return null;
    }

    public function reportDeployment(DeploymentStatusReport $report): void {}

    public function reportPreview(Preview $preview): void {}

    private function row(RepositoryRef $repository): ?GitRepository
    {
        if ($repository->connectionId === null) {
            return null;
        }

        return GitRepository::query()
            ->with('gitConnection')
            ->where('team_id', $repository->teamId)
            ->where('git_connection_id', $repository->connectionId)
            ->whereRelation('gitConnection', 'provider', GitProviderKind::Git->value)
            ->first();
    }
}

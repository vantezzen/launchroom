<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Enums\Git\GitProviderKind;
use App\Exceptions\DomainRuleViolation;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A Git repository (+ root directory) that launchroom builds (docs/19 §2.1).
 *
 * - `github` (default): a repository of a GitHub App installation
 *   (`installationId` = `github_installations.id`). The numeric repository id
 *   is what webhooks match on (docs/09 R18); clone tokens are minted per build.
 * - `gitlab`, `gitea`: a repository of a git connection (`installationId` =
 *   `git_connections.id`, `repositoryId` = GitLab project id / Gitea
 *   repository id).
 * - `git` with `installationId`: a connected plain git server (`cloneUrl`
 *   `ssh://…` with a deploy key and pinned host keys, or a public `https://`
 *   remote); `repositoryId` is 0.
 * - `git` without `installationId`: a plain smart-HTTP remote without
 *   credentials (e.g. the dev `git-fixtures` server). Only allowed where
 *   `launchroom.git.plain_sources` is on (local dev); pushes are not observed
 *   (deploy, or `launchroom:dev:git-push`).
 *
 * `branch` is the repository's default branch, used when neither the
 * environment trigger nor a branch override names one.
 */
final class GitSource extends Data implements ServiceSource
{
    public const string PROVIDER_GITHUB = 'github';

    public const string PROVIDER_GITLAB = 'gitlab';

    public const string PROVIDER_GITEA = 'gitea';

    public const string PROVIDER_GIT = 'git';

    /** `group/subgroup/project` paths (GitLab nests groups), 2–8 segments. */
    public const string FULL_NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,100}(?:\/[A-Za-z0-9_.-]{1,100}){1,7}$/D';

    /** https/http remotes, or `ssh://user@host[:port]/path` (no quotes or spaces: the path goes into the remote's `git-upload-pack '<path>'`). */
    public const string CLONE_URL_PATTERN = '/^(?:https?:\/\/[a-z0-9.-]+(?::[0-9]{1,5})?(?:\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*)?|ssh:\/\/[a-z_][a-z0-9_.-]{0,31}@[a-z0-9.-]+(?::[0-9]{1,5})?\/[A-Za-z0-9._~\/-]{1,1024})$/D';

    public function __construct(
        public int $repositoryId,
        #[Regex(self::FULL_NAME_PATTERN)]
        public string $fullName,
        public ?int $installationId = null,
        /** Repo-relative, containment-checked by the build daemon (docs/09 R4). */
        #[Regex('/^(?:[A-Za-z0-9_.@+=,-]+(?:\/[A-Za-z0-9_.@+=,-]+)*)?$/D'), Max(1024)]
        public string $rootDirectory = '',
        #[In([self::PROVIDER_GITHUB, self::PROVIDER_GITLAB, self::PROVIDER_GITEA, self::PROVIDER_GIT])]
        public string $provider = self::PROVIDER_GITHUB,
        /** Plain git only: `https://…`, `ssh://user@host/path`, or `http://` for hosts in `launchroom.git.insecure_hosts`. */
        #[Regex(self::CLONE_URL_PATTERN), Max(2048)]
        public ?string $cloneUrl = null,
        #[Regex('/^[A-Za-z0-9._\/-]{1,255}$/D')]
        public ?string $branch = null,
    ) {}

    public function type(): string
    {
        return 'git';
    }

    public function isGitHub(): bool
    {
        return $this->provider === self::PROVIDER_GITHUB;
    }

    public function kind(): GitProviderKind
    {
        return GitProviderKind::tryFrom($this->provider) ?? GitProviderKind::GitHub;
    }

    /**
     * A local-dev plain remote (no connection, no credentials).
     */
    public function isUnconnectedPlainGit(): bool
    {
        return $this->provider === self::PROVIDER_GIT && $this->installationId === null;
    }

    public function isSsh(): bool
    {
        return $this->cloneUrl !== null && str_starts_with($this->cloneUrl, 'ssh://');
    }

    /**
     * Refuses sources this installation can't build: plain git outside local
     * dev, plain http to hosts not allow-listed, GitHub sources with a URL.
     *
     * @throws DomainRuleViolation
     */
    public function assertUsable(): void
    {
        if ($this->isGitHub()) {
            if ($this->cloneUrl !== null) {
                throw DomainRuleViolation::on('source', 'GitHub repositories are cloned through their GitHub App installation, not a URL.');
            }

            return;
        }

        if ($this->provider === self::PROVIDER_GITLAB || $this->provider === self::PROVIDER_GITEA) {
            if ($this->installationId === null || $this->cloneUrl !== null) {
                throw DomainRuleViolation::on('source', 'This repository is cloned through its connection in Settings → Git, not a URL.');
            }

            return;
        }

        if ($this->installationId !== null) {
            // A connected git server: the URL was checked when it was added.
            if ($this->cloneUrl === null) {
                throw DomainRuleViolation::on('source', 'A git server repository needs its clone URL.');
            }

            return;
        }

        if (! config()->boolean('launchroom.git.plain_sources')) {
            throw DomainRuleViolation::on('source', 'Plain git repositories are only available in local development. Connect GitHub instead.');
        }

        $url = $this->cloneUrl === null ? false : parse_url($this->cloneUrl);

        if (! is_array($url) || ! isset($url['scheme'], $url['host'])) {
            throw DomainRuleViolation::on('source', 'A plain git source needs an http(s) clone URL.');
        }

        /** @var list<string> $insecure */
        $insecure = config()->array('launchroom.git.insecure_hosts');

        if ($url['scheme'] === 'http' && ! in_array(strtolower($url['host']), $insecure, true)) {
            throw DomainRuleViolation::on('source', "Plain http is only allowed for {$this->hostList($insecure)}.");
        }
    }

    /**
     * @param  list<string>  $hosts
     */
    private function hostList(array $hosts): string
    {
        return $hosts === [] ? 'no hosts' : implode(', ', $hosts);
    }
}

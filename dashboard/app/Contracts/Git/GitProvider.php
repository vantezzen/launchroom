<?php

declare(strict_types=1);

namespace App\Contracts\Git;

use App\Data\Git\CommitData;
use App\Data\Git\DeploymentStatusReport;
use App\Data\Git\GitBranchData;
use App\Data\Git\GitCredentials;
use App\Data\Git\PolledHead;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Enums\Git\GitProviderKind;
use App\Exceptions\Git\RepositoryFileUnavailable;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Previews\Preview;
use App\Models\Team;
use RuntimeException;

/**
 * One git provider (docs/19 §2): GitHub, GitLab, Gitea/Forgejo or a plain git
 * server, resolved by kind through `GitProviders`. Every repository
 * argument names its team; implementations look their rows up within it
 * (docs/09 R18).
 *
 * Reads throw (`RuntimeException` subclasses carrying a user-safe message);
 * reporting never throws (it logs), so it can't affect a deployment.
 */
interface GitProvider
{
    public function kind(): GitProviderKind;

    /**
     * Repositories of the team's connections of this provider matching
     * $search (all recent ones for an empty search), newest push first.
     *
     * @return list<SourceRepository>
     */
    public function searchRepositories(Team $team, string $search): array;

    /**
     * @return list<GitBranchData>
     *
     * @throws RuntimeException
     */
    public function branches(SourceRepository $repository): array;

    /**
     * The repository's default branch as the provider knows it (null: unknown).
     */
    public function defaultBranch(RepositoryRef $repository): ?string;

    /**
     * The commit $branch points at now.
     *
     * @throws GitHeadUnavailable
     */
    public function head(RepositoryRef $repository, string $branch): CommitData;

    /**
     * {@see head()} for polling: providers with conditional requests send
     * $etag and answer an unchanged head without the commit.
     *
     * @throws GitHeadUnavailable
     */
    public function pollHead(RepositoryRef $repository, string $branch, ?string $etag): PolledHead;

    /**
     * Whether {@see tree()} and {@see fileAtRef()} can answer (plain git
     * servers have no file API: their files are read at build time).
     */
    public function canReadFiles(): bool;

    /**
     * @throws RepositoryFileUnavailable
     */
    public function tree(RepositoryRef $repository, string $ref): RepositoryTree;

    /**
     * A file's bytes at $ref (branch, tag or SHA), or null when it doesn't exist.
     *
     * @throws RepositoryFileUnavailable
     */
    public function fileAtRef(RepositoryRef $repository, string $path, string $ref): ?string;

    /**
     * The URL builds clone (no credentials in it).
     *
     * @throws RuntimeException
     */
    public function cloneUrl(RepositoryRef $repository): string;

    /**
     * Fresh credentials for one build, sealed at lease time.
     *
     * @throws RuntimeException
     */
    public function cloneCredentials(RepositoryRef $repository): GitCredentials;

    /**
     * Files a pull request changes (null: unknown, every service rebuilds).
     *
     * @return list<string>|null
     */
    public function changedFiles(RepositoryRef $repository, int $number, string $baseRef, string $headSha): ?array;

    /**
     * Whether the preview's pull request is still open (null: unknown).
     */
    public function pullRequestOpen(Preview $preview): ?bool;

    /**
     * A link comparing two commits, if the provider has one.
     */
    public function compareUrl(RepositoryRef $repository, string $from, string $to): ?string;

    /**
     * The repository's web page, at `$branch` when given, if the provider
     * has one (the service panel's source link).
     */
    public function repositoryUrl(RepositoryRef $repository, ?string $branch = null): ?string;

    /**
     * A file's web page at $ref (branch or SHA), at `$line` when given, if
     * the provider has one (config-file problems, investigation reports).
     */
    public function fileUrl(RepositoryRef $repository, string $path, string $ref, ?int $line = null): ?string;

    /**
     * Deployment feedback (statuses, check runs, PR comment). Never throws.
     */
    public function reportDeployment(DeploymentStatusReport $report): void;

    /**
     * Preview feedback (aggregate state, teardown). Never throws.
     */
    public function reportPreview(Preview $preview): void;
}

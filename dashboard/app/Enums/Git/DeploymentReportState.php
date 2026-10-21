<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * A launchroom deployment's state as reported to GitHub (docs/06 §5 "GitHub
 * feedback"). Maps onto Deployment statuses, check runs and the PR comment.
 */
enum DeploymentReportState: string
{
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case Success = 'success';
    case Failure = 'failure';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Failure, self::Cancelled], true);
    }

    /**
     * State for `POST /repos/{repo}/deployments/{id}/statuses`.
     */
    public function deploymentStatus(): string
    {
        return match ($this) {
            self::Queued => 'queued',
            self::InProgress => 'in_progress',
            self::Success => 'success',
            self::Failure => 'failure',
            self::Cancelled => 'inactive',
        };
    }

    /**
     * Check run `status` field.
     */
    public function checkRunStatus(): string
    {
        return match ($this) {
            self::Queued => 'queued',
            self::InProgress => 'in_progress',
            self::Success, self::Failure, self::Cancelled => 'completed',
        };
    }

    /**
     * Check run `conclusion`, only for final states.
     */
    public function checkRunConclusion(): ?string
    {
        return match ($this) {
            self::Success => 'success',
            self::Failure => 'failure',
            self::Cancelled => 'cancelled',
            self::Queued, self::InProgress => null,
        };
    }

    /**
     * GitLab commit status `state` (`POST /projects/:id/statuses/:sha`).
     */
    public function gitLabStatus(): string
    {
        return match ($this) {
            self::Queued => 'pending',
            self::InProgress => 'running',
            self::Success => 'success',
            self::Failure => 'failed',
            self::Cancelled => 'canceled',
        };
    }

    /**
     * Gitea/Forgejo commit status `state` (`POST /repos/{o}/{r}/statuses/{sha}`).
     */
    public function giteaStatus(): string
    {
        return match ($this) {
            self::Queued, self::InProgress => 'pending',
            self::Success => 'success',
            self::Failure => 'failure',
            self::Cancelled => 'warning',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::InProgress => 'Deploying',
            self::Success => 'Ready',
            self::Failure => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}

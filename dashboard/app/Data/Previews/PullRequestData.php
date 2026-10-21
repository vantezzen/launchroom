<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\Previews\PreviewProvider;
use App\Events\Git\PullRequestEventReceived;
use Spatie\LaravelData\Data;

/**
 * A pull request event as previews see it (docs/06 §5), from a verified
 * GitHub, GitLab or Gitea webhook ({@see fromEvent()}; `installationId` is the
 * provider's connection row) or the local-dev simulation.
 *
 * `changedFiles` lists repo-relative paths changed by the PR (null: unknown,
 * every service rebuilds). `senderLogin`/`senderPermission` describe who
 * applied `label` on `labeled` events (fork approval by label, docs/09 R20).
 */
final class PullRequestData extends Data
{
    /**
     * @param  list<string>  $labels
     * @param  list<string>|null  $changedFiles
     */
    public function __construct(
        public PreviewProvider $provider,
        public ?int $installationId,
        public int $repositoryId,
        public string $repositoryName,
        public string $action,
        public int $number,
        public string $title,
        public string $headSha,
        public string $headRef,
        public string $baseRef,
        public bool $isFork,
        public string $authorLogin,
        public ?string $authorPermission,
        public bool $merged,
        public array $labels,
        public ?array $changedFiles = null,
        public ?string $label = null,
        public ?string $senderLogin = null,
        public ?string $senderPermission = null,
    ) {}

    /**
     * @param  list<string>|null  $changedFiles
     */
    public static function fromEvent(PullRequestEventReceived $event, string $repositoryName, ?array $changedFiles): self
    {
        return new self(
            provider: PreviewProvider::fromGitProvider($event->installation->gitProvider()),
            installationId: $event->installation->id,
            repositoryId: $event->repositoryId,
            repositoryName: $repositoryName,
            action: $event->action,
            number: $event->number,
            title: $event->title,
            headSha: $event->headSha,
            headRef: $event->headRef,
            baseRef: $event->baseRef,
            isFork: $event->isFork,
            authorLogin: $event->authorLogin,
            authorPermission: $event->authorPermission,
            merged: $event->merged,
            labels: $event->labels,
            changedFiles: $changedFiles,
            label: $event->label,
            senderLogin: $event->senderLogin,
            senderPermission: $event->senderPermission,
        );
    }

    public function isClose(): bool
    {
        return $this->action === 'closed';
    }

    /**
     * Events that carry new code or reopen the PR.
     */
    public function isCodeEvent(): bool
    {
        return in_array($this->action, ['opened', 'reopened', 'synchronize'], true);
    }

    /**
     * `[skip preview]` in the title opts the PR out (case-insensitive).
     */
    public function optedOut(): bool
    {
        return preg_match('/\[(skip preview|preview skip|no preview)\]/i', $this->title) === 1;
    }

    public static function hasWriteAccess(?string $permission): bool
    {
        return in_array($permission, ['admin', 'maintain', 'write'], true);
    }
}

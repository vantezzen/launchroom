<?php

declare(strict_types=1);

namespace App\Enums\Previews;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a pull request's preview (docs/06 §5).
 *
 *     skipped / awaiting_approval / queued → preparing → running → closed | expired
 *
 * - skipped: filtered out (target branch, label, `[skip preview]`, fork policy
 *   off); re-evaluated on the next PR event.
 * - awaiting_approval: a fork PR whose author lacks write access (docs/09 R20).
 * - queued: allowed, but the project has `max_concurrent` previews running.
 * - preparing: the environment exists; its databases start (and are cloned
 *   and sanitized when configured) before the apps deploy.
 * - running: the apps' deployments were created (their own statuses tell
 *   the rest); synchronize events deploy new commits here.
 * - failed: preparation failed (e.g. the clone's restore); the environment
 *   stays for inspection until the PR closes or the preview expires.
 * - closed / expired: torn down (environment deleted). A reopened PR starts over.
 *
 * A preview holds a slot of `max_concurrent` while it has an environment
 * (`environment_id`), whatever its status (e.g. awaiting approval of a fork's
 * new commit while the approved one keeps running).
 */
enum PreviewStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Skipped = 'skipped';
    case AwaitingApproval = 'awaiting_approval';
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Running = 'running';
    case Failed = 'failed';
    case Closed = 'closed';
    case Expired = 'expired';

    public function allowedTransitions(): array
    {
        $open = [self::Skipped, self::AwaitingApproval, self::Queued, self::Preparing, self::Running, self::Failed];

        return match ($this) {
            // Open previews move freely as PR events, approvals and data
            // preparation arrive; they end closed (PR closed/merged) or expired.
            self::Skipped, self::AwaitingApproval, self::Queued, self::Preparing, self::Running, self::Failed => [
                ...array_values(array_filter($open, fn (self $status): bool => $status !== $this)),
                self::Closed,
                self::Expired,
            ],
            // Reopened pull requests start over.
            self::Closed, self::Expired => [self::Skipped, self::AwaitingApproval, self::Queued, self::Preparing],
        };
    }

    /**
     * The pull request is still open from launchroom's point of view.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Closed, self::Expired], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Skipped => 'Skipped',
            self::AwaitingApproval => 'Awaiting approval',
            self::Queued => 'Queued',
            self::Preparing => 'Preparing',
            self::Running => 'Running',
            self::Failed => 'Failed',
            self::Closed => 'Closed',
            self::Expired => 'Expired',
        };
    }
}

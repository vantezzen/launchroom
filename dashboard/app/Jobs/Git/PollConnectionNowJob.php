<?php

declare(strict_types=1);

namespace App\Jobs\Git;

use App\Actions\Git\PollConnectionRef;
use App\Contracts\Git\TrackedRefs;
use App\Models\Git\GitConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A generic git webhook said "something changed" (docs/19 §4.2): polls the
 * connection's tracked refs right away. Unique per connection, so a burst
 * of calls polls once.
 */
#[Timeout(120)]
#[Tries(1)]
#[UniqueFor(30)]
final class PollConnectionNowJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $connectionId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(TrackedRefs $trackedRefs, PollConnectionRef $poll): void
    {
        $connection = GitConnection::query()->where('id', $this->connectionId)->first();

        if (! $connection instanceof GitConnection || ! $connection->isActive()) {
            return;
        }

        foreach ($trackedRefs->all() as $ref) {
            if ($ref->provider === $connection->provider && $ref->installationId === $connection->id) {
                $poll->handle($connection, $ref->repositoryId, $ref->ref, webhook: true);
            }
        }
    }
}

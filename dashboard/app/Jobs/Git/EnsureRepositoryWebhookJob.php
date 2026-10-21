<?php

declare(strict_types=1);

namespace App\Jobs\Git;

use App\Actions\Git\EnsureRepositoryWebhook;
use App\Models\Git\GitRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Off the request path: register the repository's webhook (idempotent,
 * {@see EnsureRepositoryWebhook}).
 */
#[Backoff([30, 120])]
#[Timeout(60)]
#[Tries(3)]
final class EnsureRepositoryWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $repositoryId,
        public readonly int $teamId,
    ) {}

    public function handle(EnsureRepositoryWebhook $ensure): void
    {
        $repository = GitRepository::query()->with('gitConnection')->where('team_id', $this->teamId)->where('id', $this->repositoryId)->first();

        if ($repository instanceof GitRepository && $repository->hook_id === null) {
            $ensure->handle($repository);
        }
    }
}

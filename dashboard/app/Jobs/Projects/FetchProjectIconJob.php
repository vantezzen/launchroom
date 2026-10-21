<?php

declare(strict_types=1);

namespace App\Jobs\Projects;

use App\Actions\Projects\RefreshProjectIcon;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fetches a project's favicon off the request path (docs/08 §13): after a
 * production deploy goes live (at most daily) and when the icon is reset
 * to automatic. Idempotent; a project gone or switched to an upload is a
 * no-op.
 */
#[Timeout(45)]
#[Tries(2)]
#[Backoff(300)]
#[UniqueFor(600)]
final class FetchProjectIconJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $projectId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(RefreshProjectIcon $refresh): void
    {
        $project = Project::query()->whereKey($this->projectId)->first();

        if ($project instanceof Project) {
            $refresh->handle($project);
        }
    }
}

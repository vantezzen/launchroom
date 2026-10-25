<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Actions\Security\EvaluateSecurityFindings;
use App\Models\Team;
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
 * Recomputes one team's findings (docs/22 §5.5) after a sync, an
 * inventory, a live deployment, a domain or protection change or a KEV
 * update. Unique per team while queued: bursts collapse into one run.
 */
#[Timeout(600)]
#[Tries(3)]
#[Backoff(30)]
#[UniqueFor(900)]
final class EvaluateSecurityFindingsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $teamId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    public function handle(EvaluateSecurityFindings $evaluate): void
    {
        if (Team::query()->whereKey($this->teamId)->exists()) {
            $evaluate->handle($this->teamId);
        }
    }
}

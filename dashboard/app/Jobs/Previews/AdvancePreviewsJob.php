<?php

declare(strict_types=1);

namespace App\Jobs\Previews;

use App\Actions\Previews\AdvancePreviews;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Moves one team's previews forward after a deployment, task or teardown
 * changed something (the scheduler tick covers everything else). Unique
 * per team while queued, idempotent ({@see AdvancePreviews}).
 */
#[Timeout(300)]
#[Tries(1)]
#[UniqueFor(60)]
final class AdvancePreviewsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $teamId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    public function handle(AdvancePreviews $advance): void
    {
        $advance->handle($this->teamId);
    }
}

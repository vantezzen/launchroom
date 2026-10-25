<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Actions\Security\SyncKnownExploited;
use App\Actions\Security\SyncLifecycles;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * CISA KEV and endoflife.date (docs/22 §4.3): daily, on start when older
 * than a day, and with "Check now" (KEV only).
 */
#[Timeout(600)]
#[Tries(1)]
#[UniqueFor(900)]
final class SyncFeedsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly bool $lifecycles = true,
    ) {}

    public function uniqueId(): string
    {
        return $this->lifecycles ? 'all' : 'kev';
    }

    public function handle(SyncKnownExploited $kev, SyncLifecycles $lifecycles): void
    {
        $kev->handle();

        if ($this->lifecycles) {
            $lifecycles->handle();
        }
    }
}

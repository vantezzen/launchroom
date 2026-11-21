<?php

declare(strict_types=1);

namespace App\Jobs\AppUpdates;

use App\Actions\AppUpdates\CheckAppUpdates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * Checks one service instance for newer releases (docs/04 §14.1). A
 * registry error is recorded on the check (with its own backoff), so the
 * job only retries when the worker itself failed.
 */
#[Tries(2)]
#[Backoff(60)]
#[Timeout(120)]
#[UniqueFor(600)]
final class CheckAppUpdatesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $instanceId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->instanceId;
    }

    public function handle(CheckAppUpdates $check): void
    {
        $check->handle($this->instanceId);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Backups;

use App\Actions\Backups\TestBackupDestination;
use App\Models\Backups\BackupDestination;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * "Test destination" off the request path (it runs restic).
 */
#[Timeout(600)]
#[Tries(1)]
#[UniqueFor(600)]
final class TestBackupDestinationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $destinationId,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->destinationId;
    }

    public function handle(TestBackupDestination $action): void
    {
        $destination = BackupDestination::query()->whereKey($this->destinationId)->first();

        if ($destination instanceof BackupDestination) {
            $action->handle($destination);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Logs;

use App\Models\DaemonTask;
use App\Models\Environment;
use App\Models\User;
use App\Queries\LogTails;
use App\Services\Tasks\TaskCancellation;
use Illuminate\Support\Facades\DB;

/**
 * Stops the live runtime log tails $user started in $environment (Live off,
 * explorer closed). Other viewers' tails keep running.
 */
final readonly class StopLogTail
{
    public function __construct(
        private TaskCancellation $cancellation,
    ) {}

    public function handle(Environment $environment, User $user): int
    {
        return DB::transaction(function () use ($environment, $user): int {
            $tasks = LogTails::open($environment, $user)->get();

            $tasks->each(fn (DaemonTask $task) => $this->cancellation->cancel($task));

            return $tasks->count();
        });
    }
}

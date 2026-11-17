<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Data\Protocol\V1\Tasks\CleanupSpec;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\User;
use App\Services\Tasks\DaemonTasks;
use Illuminate\Support\Facades\DB;

/**
 * "Free space now" on the server page (G18): a `cleanup` task pinned to the
 * server. launchroomd runs its disk-pressure cleanup right away (unused
 * images, stopped containers, old build cache; never volumes) and returns
 * what it removed. A cleanup that is still open is returned instead of a
 * second one.
 */
final readonly class FreeDiskSpace
{
    public const int TIMEOUT_SECONDS = 600;

    public function __construct(
        private DaemonTasks $tasks,
    ) {}

    public function handle(Server $server, ?User $user = null): DaemonTask
    {
        if (! $server->supportsDaemonFeature(DaemonFeature::CleanupTask)) {
            throw DomainRuleViolation::conflict("{$server->name} runs a launchroom version that can’t clean up on request yet. Update it first.");
        }

        if ($server->status !== ServerStatus::Online) {
            throw DomainRuleViolation::conflict("{$server->name} isn’t online, so it can’t clean up now.");
        }

        return DB::transaction(function () use ($server, $user): DaemonTask {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();

            $open = DaemonTask::query()
                ->where('server_id', $server->id)
                ->where('type', DaemonTaskType::Cleanup->value)
                ->open()
                ->latest('id')
                ->first();

            return $open ?? $this->tasks->create($server->team_id, new CleanupSpec, self::TIMEOUT_SECONDS, pin: $server, requestedById: $user?->id);
        });
    }
}

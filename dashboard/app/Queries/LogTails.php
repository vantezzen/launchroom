<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared lookups of the live runtime log tails (StartLogTail, StopLogTail,
 * EnvironmentLogs): `logs` tasks with follow, one per running workload,
 * tagged with the user who opened the explorer (`purpose = tail:{user id}`)
 * so one viewer closing the page never stops another viewer's tail.
 */
final class LogTails
{
    /** Each tail runs at most this long; the explorer renews it while open. */
    public const int SECONDS = 300;

    public static function purpose(User $user): string
    {
        return 'tail:'.$user->id;
    }

    /**
     * Unfinished tail tasks of $user in $environment.
     *
     * @return Builder<DaemonTask>
     */
    public static function open(Environment $environment, User $user): Builder
    {
        return DaemonTask::query()
            ->where('team_id', $environment->team_id)
            ->where('type', DaemonTaskType::Logs->value)
            ->where('purpose', self::purpose($user))
            ->whereIn('status', [DaemonTaskStatus::Pending->value, DaemonTaskStatus::Leased->value])
            ->whereIn('deployment_id', Deployment::query()->select('id')->where('environment_id', $environment->id));
    }
}

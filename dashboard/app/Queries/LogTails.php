<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskType;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared lookups of the live runtime log tails (StartLogTail, StopLogTail,
 * EnvironmentLogs): `logs` tasks with follow, one per running workload,
 * tagged with the user who opened the explorer (purpose `log_tail`, `requested_by_id`)
 * so one viewer closing the page never stops another viewer's tail.
 */
final class LogTails
{
    /** Each tail runs at most this long; viewers renew it while open. */
    public const int SECONDS = 300;

    /**
     * How often a viewer renews its tails: after StartLogTail stops keeping
     * a tail (half its run) and well before it ends.
     */
    public const int RENEW_SECONDS = 180;

    /**
     * @return Builder<DaemonTask>
     */
    public static function open(Environment $environment, User $user): Builder
    {
        return DaemonTask::query()
            ->where('team_id', $environment->team_id)
            ->where('type', DaemonTaskType::Logs->value)
            ->where('purpose', DaemonTaskPurpose::LogTail->value)
            ->where('requested_by_id', $user->id)
            ->open()
            ->whereIn('deployment_id', Deployment::query()->select('id')->where('environment_id', $environment->id));
    }
}

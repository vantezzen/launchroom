<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Events\CanvasChanged;
use App\Models\DaemonTask;
use App\Models\ServiceInstance;
use Illuminate\Support\Facades\DB;

/**
 * Stores a finished plan task's outcome on the service instance that asked
 * for it (`service_instances.plan.task`), unless a newer plan replaced it.
 */
final class RecordPlanResult
{
    public function handle(DaemonTask $task): void
    {
        if ($task->type !== DaemonTaskType::Plan || ! $task->status->isTerminal()) {
            return;
        }

        $instance = DB::transaction(function () use ($task): ?ServiceInstance {
            $instance = ServiceInstance::query()
                ->where('team_id', $task->team_id)
                ->whereRaw("plan->>'task' = ?", [$task->public_id])
                ->lockForUpdate()
                ->first();

            if (! $instance instanceof ServiceInstance) {
                return null;
            }

            $plan = $instance->plan ?? [];
            $failure = is_array($task->result['failure'] ?? null) ? $task->result['failure'] : [];
            $planResult = is_array($task->result['plan'] ?? null) ? $task->result['plan'] : [];
            $summary = $planResult['summary'] ?? ($failure['summary'] ?? null);
            $succeeded = $task->status === DaemonTaskStatus::Succeeded;

            $plan['status'] = $succeeded ? 'ready' : 'failed';
            $plan['finishedAt'] = now()->toIso8601ZuluString();
            $plan['summary'] = is_array($summary) ? $summary : ($succeeded ? null : ($plan['summary'] ?? null));
            $plan['error'] = $succeeded ? null : ($task->status === DaemonTaskStatus::Cancelled ? 'The plan was cancelled.' : ($task->error ?? 'The plan failed.'));
            $plan['failureClass'] = $succeeded ? null : (is_string($failure['class'] ?? null) ? $failure['class'] : null);

            $instance->forceFill(['plan' => $plan])->save();

            return $instance;
        });

        // Open Build sections and canvases pick up the detected plan.
        if ($instance instanceof ServiceInstance) {
            $instance->loadMissing(['environment', 'service']);
            broadcast(new CanvasChanged($instance->environment->public_id, $instance->service->public_id));
        }
    }
}

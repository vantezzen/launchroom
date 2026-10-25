<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Enums\DaemonTaskStatus;
use App\Enums\Security\InvestigationStatus;
use App\Jobs\Security\BuildInvestigationReportJob;
use App\Models\DaemonTask;
use App\Models\Security\SecurityInvestigation;
use Illuminate\Support\Facades\DB;

/**
 * An `inspect_container` task finished (docs/22 §7.2): a replica whose
 * task failed is marked unchecked with the server's reason ("The container
 * isn't running"); once no replica is pending, the report is written.
 */
final readonly class AdvanceInvestigation
{
    public function handle(DaemonTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $investigation = SecurityInvestigation::query()
                ->where('team_id', $task->team_id)
                ->where('status', InvestigationStatus::Running->value)
                ->whereRaw('replicas @> ?::jsonb', [json_encode([['task' => $task->public_id]], JSON_THROW_ON_ERROR)])
                ->lockForUpdate()
                ->first();

            if (! $investigation instanceof SecurityInvestigation) {
                return;
            }

            $replicas = $investigation->replicas;

            foreach ($replicas as $i => $replica) {
                if (($replica['task'] ?? null) === $task->public_id && $replica['state'] === 'pending') {
                    $replicas[$i]['state'] = 'failed';
                    $replicas[$i]['message'] = $task->status === DaemonTaskStatus::Failed && is_string($task->error) ? mb_substr($task->error, 0, 300) : null;
                }
            }

            $investigation->forceFill(['replicas' => $replicas])->save();

            if (array_filter($replicas, static fn (array $r): bool => $r['state'] === 'pending') === []) {
                dispatch(new BuildInvestigationReportJob($investigation->id))->afterCommit();
            }
        });
    }
}

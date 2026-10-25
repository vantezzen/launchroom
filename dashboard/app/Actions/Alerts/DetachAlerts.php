<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Enums\Alerts\AlertStatus;
use App\Models\Alerts\Alert;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Lets go of the alerts of a project or environment that is about to be
 * deleted (docs/15 §3.1): firing ones resolve with $reason (channels that
 * heard about them are told, like any resolution), and every one of them
 * loses its links to the rows that go. History stays: the alert keeps its
 * title, summary, facts and team.
 *
 * Why before the delete instead of relying on `ON DELETE SET NULL`: one
 * `DELETE FROM projects` reaches an alert along several cascade paths
 * (projects → alerts.project_id, projects → environments/services →
 * service_instances → alerts.service_instance_id). When the SET NULL of
 * `project_id` (or `environment_id`) rewrites the alert row before the
 * SET NULL of `service_instance_id` has run, Postgres checks the new row
 * version's foreign keys again (a row written by the current transaction
 * gets no "key unchanged" shortcut) and finds the instance already gone:
 * `23503 insert or update on table "alerts" violates foreign key constraint
 * "alerts_service_instance_id_foreign"`, and the whole delete fails. An
 * alert with the project and the instance is enough; deployments,
 * placements and the alert's status don't matter.
 */
final readonly class DetachAlerts
{
    public function __construct(
        private ResolveAlert $resolve,
    ) {}

    /**
     * @param  array<int|string>  $environmentIds
     * @param  array<int|string>  $instanceIds
     * @return int alerts detached
     */
    public function handle(string $reason, ?int $projectId = null, array $environmentIds = [], array $instanceIds = []): int
    {
        if ($projectId === null && $environmentIds === [] && $instanceIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($reason, $projectId, $environmentIds, $instanceIds): int {
            $matching = static fn (): EloquentBuilder => Alert::query()->where(static function (Builder $query) use ($projectId, $environmentIds, $instanceIds): void {
                if ($projectId !== null) {
                    $query->orWhere('project_id', $projectId);
                }

                if ($environmentIds !== []) {
                    $query->orWhereIn('environment_id', $environmentIds);
                }

                if ($instanceIds !== []) {
                    $query->orWhereIn('service_instance_id', $instanceIds);
                }
            });

            foreach ($matching()->where('status', AlertStatus::Firing->value)->get(['id', 'team_id', 'rule', 'subject_key']) as $alert) {
                $this->resolve->handle($alert->team_id, $alert->rule, $alert->subject_key, $reason);
            }

            // One statement, so no cascade path rewrites the row halfway.
            $columns = ['environment_id' => null, 'service_instance_id' => null, 'updated_at' => now()];

            if ($projectId !== null) {
                $columns['project_id'] = null;
            }

            return $matching()->update($columns);
        });
    }
}

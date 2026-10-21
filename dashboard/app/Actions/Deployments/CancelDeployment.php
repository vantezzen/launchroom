<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Contracts\BuildOrchestrator;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a deployment that hasn't touched running workloads yet: queued, or
 * building (the build task is cancelled by solve context, docs/04 §5), or a
 * staged one that is ready but never got traffic (its workloads are removed,
 * docs/06 §6.5). Later
 * steps already replace workloads; they run to success or failure, and a
 * failure keeps the old version serving (docs/04 §8). The next queued
 * deployment of the service instance, if any, starts.
 */
final readonly class CancelDeployment
{
    public function __construct(
        private BuildOrchestrator $builds,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Deployment $deployment, AuditActor $actor): void
    {
        $servers = [];

        $next = DB::transaction(function () use ($deployment, $actor, &$servers): ?int {
            $deployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();

            if (! $deployment->status->isCancellable()) {
                throw DomainRuleViolation::on('deployment', 'Only queued, building or staged (ready) deployments can be cancelled.');
            }

            if ($deployment->status === DeploymentStatus::Building) {
                $this->builds->cancel($deployment);
            }

            if ($deployment->status === DeploymentStatus::Ready) {
                /** @var list<int> $ids */
                $ids = $deployment->placements()->pluck('server_id')->unique()->values()->all();
                $servers = $ids;
                $deployment->placements()->delete();
            }

            $deployment->transitionTo(DeploymentStatus::Cancelled);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DeploymentCancelled,
                actor: $actor,
                teamId: $deployment->team_id,
                subjectType: 'deployment',
                subjectId: $deployment->public_id,
                meta: [],
            ));

            $next = Deployment::query()
                ->where('service_instance_id', $deployment->service_instance_id)
                ->where('status', DeploymentStatus::Queued->value)
                ->orderBy('id')
                ->value('id');

            return is_int($next) ? $next : null;
        });

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }

        if ($next !== null) {
            dispatch(new AdvanceDeploymentJob($next));
        }
    }
}

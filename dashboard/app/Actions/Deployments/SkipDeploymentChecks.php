<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Git\CommitCheckData;
use App\Enums\AuditAction;
use App\Enums\CheckGateState;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Deploy now" on a push deployment waiting for its commit's checks (D111,
 * docs/06 §2.5): the gate settles as skipped, recording who, and the
 * deployment starts. Audited with the checks still pending or failed.
 */
final readonly class SkipDeploymentChecks
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Deployment $deployment, User $user, AuditActor $actor): Deployment
    {
        $deployment = DB::transaction(function () use ($deployment, $user, $actor): Deployment {
            $deployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();

            if (! $deployment->waitsForChecks() || $deployment->checks === null) {
                throw DomainRuleViolation::on('deployment', 'This deployment isn’t waiting for checks anymore.');
            }

            $pending = array_map(static fn (CommitCheckData $check): string => $check->name, $deployment->checks->pendingChecks());
            $deployment->checks = $deployment->checks->settle(CheckGateState::Skipped, $user->name);
            $deployment->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DeploymentChecksSkipped,
                actor: $actor,
                teamId: $deployment->team_id,
                subjectType: 'deployment',
                subjectId: $deployment->public_id,
                meta: ['commit' => $deployment->commit_sha, 'pending' => $pending],
            ));

            return $deployment;
        });

        dispatch(new AdvanceDeploymentJob($deployment->id));

        return $deployment;
    }
}

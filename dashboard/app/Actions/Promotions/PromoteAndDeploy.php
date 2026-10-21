<?php

declare(strict_types=1);

namespace App\Actions\Promotions;

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Deployments\DeployResult;
use App\Data\Promotions\PromoteChangesData;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Promote and deploy" (flows §12, maintainer decision 5): stages the
 * selected changes in the target (PromoteChanges) and deploys the target in
 * the same transaction, so a deploy that is refused (broken references,
 * production secrets policy) leaves nothing staged. The target's earlier
 * staged changes deploy too, as with any Deploy; the page says so.
 */
final readonly class PromoteAndDeploy
{
    public function __construct(
        private PromoteChanges $promote,
        private DeployEnvironment $deploy,
    ) {}

    /**
     * @return array{EnvironmentPromotion, DeployResult}
     */
    public function handle(Project $project, Environment $to, PromoteChangesData $data, User $user, AuditActor $actor): array
    {
        return DB::transaction(function () use ($project, $to, $data, $user, $actor): array {
            $promotion = $this->promote->handle($project, $data, $user, $actor);
            $from = $project->environments()->whereKey($promotion->from_environment_id)->value('name');
            $result = $this->deploy->handle($to, $user, $actor, 'Promoted from '.(is_string($from) ? $from : 'another environment'));

            return [$promotion, $result];
        });
    }
}

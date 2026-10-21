<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Variables\VariableInput;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use App\Services\Variables\SecretExposureGuard;
use App\Services\Variables\VariableStore;
use App\Services\Variables\VariableTargets;
use Illuminate\Support\Facades\DB;

/**
 * Creates a service variable, or a shared one when $service is null
 * (docs/06 §3). Staged until Deploy. Audit entries carry the key, never the value.
 */
final readonly class CreateVariable
{
    public function __construct(
        private VariableStore $store,
        private SecretExposureGuard $exposure,
        private VariableTargets $targets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, ?Service $service, VariableInput $input, ?User $user, AuditActor $actor): Variable
    {
        if ($service instanceof Service && $service->project_id !== $project->id) {
            throw DomainRuleViolation::forbidden('That service belongs to another project.');
        }

        return DB::transaction(function () use ($project, $service, $input, $user, $actor): Variable {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $targets = $this->targets->resolve($project, $input->targets);
            $this->targets->assertNoOverlap($project, $service?->id, $input->key, $targets);

            $variable = $this->store->create($project, $service, $input->key, $input->value, $input->type, $targets, $input->exposureOverride, actor: $user);
            $this->exposure->assertNoPublicSecrets($project, [$variable->key]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::VariableCreated,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: 'variable',
                subjectId: $variable->public_id,
                meta: ['key' => $variable->key, 'type' => $variable->type->value, 'service' => $service?->public_id],
            ));

            return $variable;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Variables\VariableUpdate;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Services\Variables\SecretExposureGuard;
use App\Services\Variables\VariableStore;
use App\Services\Variables\VariableTargets;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Edits a variable (staged). A new value bumps the version; secrets are
 * replaced, never read back here.
 *
 * `onlyEnvironment`: when the row also targets other environments, the
 * edit applies to that environment alone. The environment leaves the row and
 * gets a row of its own with the edited key, type and value (the current
 * value when none is given); the other environments keep theirs (docs/06
 * §3.1: "when values diverge, the editor splits the row").
 */
final readonly class UpdateVariable
{
    public function __construct(
        private VariableStore $store,
        private SecretExposureGuard $exposure,
        private VariableTargets $targets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Variable $variable, VariableUpdate $update, ?User $user, AuditActor $actor): Variable
    {
        return DB::transaction(function () use ($variable, $update, $user, $actor): Variable {
            $variable = Variable::query()->whereKey($variable->id)->with('project')->lockForUpdate()->firstOrFail();
            $project = $variable->project;

            if ($variable->connection_id !== null) {
                throw DomainRuleViolation::on('variable', 'This variable belongs to a connection. Change or remove the connection instead.');
            }

            if (! $update->onlyEnvironment instanceof Optional && $variable->targets()->count() > 1) {
                return $this->split($variable, $update, $update->onlyEnvironment, $user, $actor);
            }

            $previousKey = $variable->key;
            $key = $update->key instanceof Optional ? $variable->key : $update->key;
            $type = $update->type instanceof Optional ? $variable->type : $update->type;
            $exposure = $update->exposureOverride instanceof Optional ? $variable->exposure_override : $update->exposureOverride;

            $targets = $update->targets instanceof Optional ? null : $this->targets->resolve($project, $update->targets);
            $currentTargets = array_values($variable->targets()->get()->map(static fn ($target): array => [
                'environment_id' => $target->environment_id,
                'all_previews' => $target->all_previews,
                'branch_pattern' => $target->branch_pattern,
            ])->all());
            $this->targets->assertNoOverlap($project, $variable->service_id, $key, $targets ?? $currentTargets, $variable->id);

            $this->store->updateMeta($variable, $key, $type, $exposure, $user);

            if (! $update->value instanceof Optional) {
                $this->store->updateValue($project, $variable, $update->value, $user);
            }

            if ($targets !== null) {
                $this->store->replaceTargets($variable, $targets);
            }

            $this->exposure->assertNoPublicSecrets($project, array_values(array_unique([$previousKey, $key])), $update->value instanceof Optional ? 'type' : 'value');

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::VariableUpdated,
                actor: $actor,
                teamId: $variable->team_id,
                subjectType: 'variable',
                subjectId: $variable->public_id,
                meta: ['key' => $variable->key, 'value_changed' => ! $update->value instanceof Optional],
            ));

            return $variable;
        });
    }

    private function split(Variable $variable, VariableUpdate $update, string $environmentId, ?User $user, AuditActor $actor): Variable
    {
        $project = $variable->project;
        $environment = $project->environments()->where('public_id', $environmentId)->first();

        if (! $environment instanceof Environment || ! $variable->targets()->where('environment_id', $environment->id)->exists()) {
            throw DomainRuleViolation::on('onlyEnvironment', 'This variable doesn’t apply to that environment.');
        }

        $variable->loadMissing('service');
        $key = $update->key instanceof Optional ? $variable->key : $update->key;
        $type = $update->type instanceof Optional ? $variable->type : $update->type;
        $exposure = $update->exposureOverride instanceof Optional ? $variable->exposure_override : $update->exposureOverride;
        $value = $update->value instanceof Optional ? $this->store->plaintext($project, $variable) : $update->value;

        VariableTarget::query()->where('variable_id', $variable->id)->where('environment_id', $environment->id)->delete();
        $target = [['environment_id' => $environment->id, 'all_previews' => false, 'branch_pattern' => null]];
        $this->targets->assertNoOverlap($project, $variable->service_id, $key, $target);

        if ($variable->type === VariableType::Secret && $type === VariableType::Config && ! ($user?->hasTeamPermission($variable->team_id, Permission::RevealSecrets) ?? false)) {
            throw DomainRuleViolation::on('type', 'Changing a secret to config requires permission to reveal secrets.');
        }

        $copy = $this->store->create($project, $variable->service, $key, $value, $type, $target, $exposure, actor: $user);

        if ($variable->service_id === null && $variable->usedBy()->exists()) {
            $copy->usedBy()->syncWithoutDetaching($variable->usedBy()->pluck('services.id')->all());
        }

        $this->exposure->assertNoPublicSecrets($project, array_values(array_unique([$variable->key, $key])), $update->value instanceof Optional ? 'type' : 'value');

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::VariableUpdated,
            actor: $actor,
            teamId: $variable->team_id,
            subjectType: 'variable',
            subjectId: $copy->public_id,
            meta: ['key' => $key, 'value_changed' => ! $update->value instanceof Optional, 'split_from' => $variable->public_id, 'environment' => $environment->public_id],
        ));

        return $copy;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Variables\RawEditResult;
use App\Enums\AuditAction;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Queries\EnvironmentVariableRows;
use App\Queries\ServiceVariablesView;
use App\Services\Variables\SecretExposureGuard;
use App\Services\Variables\VariableStore;
use App\Support\Variables\EnvFileParser;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Saves the raw `.env` editor of a service in one environment (docs/06
 * §3.4, staged like every variable edit). The file replaces the service's
 * variables that target this environment:
 *
 * - new keys are created for this environment with the suggested type;
 * - a changed value updates the row, or, when the row also targets other
 *   environments, splits it (this environment gets its own row, the others
 *   keep theirs);
 * - missing keys are removed from this environment (the row is deleted when
 *   this was its only target);
 * - the secret placeholder keeps an existing secret value as it is.
 *
 * A file with syntax errors is refused as a whole.
 */
final readonly class ApplyRawVariables
{
    public function __construct(
        private VariableStore $store,
        private SecretExposureGuard $exposure,
        private EnvironmentVariableRows $rows,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Environment $environment, Service $service, #[SensitiveParameter] string $content, ?User $user, AuditActor $actor): RawEditResult
    {
        if ($service->project_id !== $environment->project_id) {
            throw DomainRuleViolation::forbidden('That service belongs to another project.');
        }

        $parsed = EnvFileParser::parse($content);

        if ($parsed->errors !== []) {
            throw DomainRuleViolation::on('content', implode(' ', array_map(static fn (array $error): string => "Line {$error['line']}: {$error['message']}", $parsed->errors)));
        }

        return DB::transaction(function () use ($environment, $service, $parsed, $user, $actor): RawEditResult {
            $project = Project::query()->whereKey($environment->project_id)->lockForUpdate()->firstOrFail();

            /** @var array<string, Variable> $existing */
            $existing = $this->rows->get($environment, $service)->keyBy('key')->all();
            $here = [['environment_id' => $environment->id, 'all_previews' => false, 'branch_pattern' => null]];
            $created = [];
            $updated = [];
            $removed = [];

            foreach ($parsed->entries as $entry) {
                $variable = $existing[$entry->key] ?? null;
                unset($existing[$entry->key]);

                if ($entry->value === ServiceVariablesView::SECRET_PLACEHOLDER) {
                    if ($variable?->type !== VariableType::Secret) {
                        throw DomainRuleViolation::on('content', "{$entry->key}: the secret placeholder only keeps the value of an existing secret. Enter a value instead.");
                    }

                    continue;
                }

                if ($variable === null) {
                    $this->store->create($project, $service, $entry->key, $entry->value, $entry->suggestedType, $here, actor: $user);
                    $created[] = $entry->key;

                    continue;
                }

                if ($variable->type !== VariableType::Secret && $this->store->plaintext($project, $variable) === $entry->value) {
                    continue;
                }

                if ($this->onlyHere($variable, $environment)) {
                    $this->store->updateValue($project, $variable, $entry->value, $user);
                } else {
                    $this->removeTarget($variable, $environment);
                    $this->store->create($project, $service, $entry->key, $entry->value, $variable->type, $here, $variable->exposure_override, actor: $user);
                }

                $updated[] = $entry->key;
            }

            foreach ($existing as $key => $variable) {
                if ($this->onlyHere($variable, $environment)) {
                    $variable->delete();
                } else {
                    $this->removeTarget($variable, $environment);
                }

                $removed[] = $key;
            }

            $this->exposure->assertNoPublicSecrets($project, [...$created, ...$updated], 'content');

            if ($created !== [] || $updated !== [] || $removed !== []) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::VariablesRawEdited,
                    actor: $actor,
                    teamId: $project->team_id,
                    subjectType: 'service',
                    subjectId: $service->public_id,
                    meta: ['environment' => $environment->public_id, 'created' => $created, 'updated' => $updated, 'removed' => $removed],
                ));
            }

            return new RawEditResult($created, $updated, $removed, $parsed->warnings);
        });
    }

    private function onlyHere(Variable $variable, Environment $environment): bool
    {
        return $variable->targets->count() === 1
            && $variable->targets->every(static fn (VariableTarget $target): bool => $target->environment_id === $environment->id);
    }

    private function removeTarget(Variable $variable, Environment $environment): void
    {
        $this->store->replaceTargets($variable, array_values($variable->targets
            ->reject(static fn (VariableTarget $target): bool => $target->environment_id === $environment->id)
            ->map(static fn (VariableTarget $target): array => [
                'environment_id' => $target->environment_id,
                'all_previews' => $target->all_previews,
                'branch_pattern' => $target->branch_pattern,
            ])
            ->all()));
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Variables\EnvFileEntry;
use App\Data\Variables\EnvFileResult;
use App\Data\Variables\ImportResult;
use App\Data\Variables\VariableTargetData;
use App\Enums\AuditAction;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Services\Variables\SecretExposureGuard;
use App\Services\Variables\VariableStore;
use App\Services\Variables\VariableTargets;
use App\Support\Variables\EnvFileParser;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Bulk-imports a pasted `.env` (docs/06 §3.4) for the given targets: keys
 * already defined for exactly these targets get a new value, the rest are
 * created with the auto-detected type. A file with syntax errors is refused
 * as a whole.
 */
final readonly class ImportVariables
{
    public function __construct(
        private VariableStore $store,
        private SecretExposureGuard $exposure,
        private VariableTargets $targets,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<VariableTargetData>  $targets
     * @param  list<string>|null  $only  import only these keys (null: all)
     * @param  array<string, VariableType>  $types  type for newly created keys instead of the suggested one
     */
    public function handle(Project $project, ?Service $service, #[SensitiveParameter] string $content, array $targets, ?User $user, AuditActor $actor, ?array $only = null, array $types = []): ImportResult
    {
        if ($service instanceof Service && $service->project_id !== $project->id) {
            throw DomainRuleViolation::forbidden('That service belongs to another project.');
        }

        $parsed = EnvFileParser::parse($content);

        if ($parsed->errors !== []) {
            throw DomainRuleViolation::on('content', implode(' ', array_map(static fn (array $error): string => "Line {$error['line']}: {$error['message']}", $parsed->errors)));
        }

        if ($only !== null) {
            $parsed = new EnvFileResult(
                array_values(array_filter($parsed->entries, static fn (EnvFileEntry $entry): bool => in_array($entry->key, $only, true))),
                $parsed->errors,
                $parsed->warnings,
            );
        }

        return DB::transaction(function () use ($project, $service, $parsed, $targets, $user, $actor, $types): ImportResult {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $rows = $this->targets->resolve($project, $targets);
            $created = [];
            $updated = [];

            $existing = Variable::query()
                ->where('project_id', $project->id)
                ->where('service_id', $service?->id)
                ->whereNull('connection_id')
                ->whereIn('key', $parsed->keys())
                ->with('targets')
                ->get();

            foreach ($parsed->entries as $entry) {
                $match = $existing->first(fn (Variable $variable): bool => $variable->key === $entry->key && $this->sameTargets($variable, $rows));

                if ($match !== null) {
                    $this->store->updateValue($project, $match, $entry->value, $user);
                    $updated[] = $entry->key;

                    continue;
                }

                $this->targets->assertNoOverlap($project, $service?->id, $entry->key, $rows);
                $this->store->create($project, $service, $entry->key, $entry->value, $types[$entry->key] ?? $entry->suggestedType, $rows, actor: $user);
                $created[] = $entry->key;
            }

            $this->exposure->assertNoPublicSecrets($project, [...$created, ...$updated], 'content');

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::VariablesImported,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: $service instanceof Service ? 'service' : 'project',
                subjectId: $service->public_id ?? $project->public_id,
                meta: ['created' => $created, 'updated' => $updated],
            ));

            return new ImportResult($created, $updated, $parsed->warnings);
        });
    }

    /**
     * @param  list<array{environment_id: int|null, all_previews: bool, branch_pattern: string|null}>  $rows
     */
    private function sameTargets(Variable $variable, array $rows): bool
    {
        $current = $variable->targets->map(static fn (VariableTarget $target): array => [
            'environment_id' => $target->environment_id,
            'all_previews' => $target->all_previews,
            'branch_pattern' => $target->branch_pattern,
        ])->sortBy(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))->values()->all();

        $wanted = $rows;
        usort($wanted, static fn (array $a, array $b): int => json_encode($a, JSON_THROW_ON_ERROR) <=> json_encode($b, JSON_THROW_ON_ERROR));

        return $current === $wanted;
    }
}

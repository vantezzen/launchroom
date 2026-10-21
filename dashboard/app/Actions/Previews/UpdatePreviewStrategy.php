<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Previews\PreviewStrategyInput;
use App\Enums\AuditAction;
use App\Enums\EnvironmentKind;
use App\Enums\PreviewStrategy;
use App\Exceptions\DomainRuleViolation;
use App\Models\Previews\PreviewSettings;
use App\Models\ServiceInstance;
use App\Services\Previews\PreviewDatabases;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sets a stateful service's "In previews" strategy on its instance in the
 * preview base environment (docs/06 §5): new empty (default), clone from
 * another database (source checked against the size guard and the
 * production toggle, docs/14 §6; optional sanitize argv) or share the base
 * instance (production only with the same toggle). Applies to previews created afterwards.
 */
final readonly class UpdatePreviewStrategy
{
    public function __construct(
        private PreviewDatabases $databases,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, PreviewStrategyInput $input, AuditActor $actor): ServiceInstance
    {
        return DB::transaction(function () use ($instance, $input, $actor): ServiceInstance {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'environment.project'])->lockForUpdate()->firstOrFail();

            if (! $instance->service->kind->isStateful() || $instance->environment->kind === EnvironmentKind::Preview) {
                throw DomainRuleViolation::on('strategy', 'Only databases outside previews have an “In previews” setting.');
            }

            $source = null;
            $command = null;

            // Sharing production's database needs the same owner/admin
            // opt-in as cloning it (docs/06 §5).
            if ($input->strategy === PreviewStrategy::Share && $instance->environment->kind === EnvironmentKind::Production && ! PreviewSettings::forProject($instance->environment->project)->allow_production_clone) {
                throw DomainRuleViolation::on('strategy', 'Sharing a production database with previews needs “Allow production data in previews” (owners and admins).');
            }

            if ($input->strategy === PreviewStrategy::Clone) {
                $source = $input->cloneSourceId === null ? null : ServiceInstance::query()
                    ->where('public_id', $input->cloneSourceId)
                    ->where('team_id', $instance->team_id)
                    ->whereIn('environment_id', $instance->environment->project->environments()->select('id')->where('kind', '!=', EnvironmentKind::Preview->value))
                    ->whereHas('service', static fn (Builder $query) => $query->where('kind', 'database'))
                    ->first();

                if (! $source instanceof ServiceInstance) {
                    throw DomainRuleViolation::on('cloneSourceId', 'Pick a database of this project to clone from.');
                }

                $refusal = $this->databases->refusal($source, PreviewSettings::forProject($instance->environment->project));

                if ($refusal !== null) {
                    throw DomainRuleViolation::on('cloneSourceId', $refusal);
                }

                $command = $this->argv($input->sanitizeCommand);
            }

            $instance->forceFill([
                'preview_strategy' => $input->strategy,
                'preview_clone_source_id' => $source?->id,
                'preview_sanitize_command' => $command,
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::PreviewStrategyChanged,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['strategy' => $input->strategy->value, 'source' => $source?->public_id, 'sanitize' => $command !== null],
            ));

            return $instance;
        });
    }

    /**
     * @param  list<mixed>|null  $argv
     * @return list<string>|null
     */
    private function argv(?array $argv): ?array
    {
        if ($argv === null || $argv === []) {
            return null;
        }

        $clean = [];

        foreach ($argv as $arg) {
            if (! is_string($arg) || $arg === '' || strlen($arg) > 4096 || str_contains($arg, "\0")) {
                throw DomainRuleViolation::on('sanitizeCommand', 'The sanitize command is a list of non-empty arguments (no NUL bytes, at most 4096 bytes each).');
            }

            $clean[] = $arg;
        }

        return $clean;
    }
}

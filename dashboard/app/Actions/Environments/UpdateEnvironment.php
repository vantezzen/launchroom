<?php

declare(strict_types=1);

namespace App\Actions\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Environments\UpdateEnvironmentData;
use App\Enums\AuditAction;
use App\Enums\EnvironmentKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Renames an environment and/or edits its deploy triggers (docs/06 §2:
 * branch glob, tag glob, or neither for manual only). Triggers apply to the
 * next push at once (they never restart anything); both edits also show in
 * the staged changes (effect "none") like every other intent change. The slug
 * stays: it is part of generated hostnames.
 */
final readonly class UpdateEnvironment
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Environment $environment, UpdateEnvironmentData $data, AuditActor $actor): Environment
    {
        return DB::transaction(function () use ($environment, $data, $actor): Environment {
            $environment = Environment::query()->whereKey($environment->id)->lockForUpdate()->firstOrFail();
            $meta = [];

            if (! $data->name instanceof Optional && trim($data->name) !== $environment->name) {
                $name = trim($data->name);

                if ($name === '') {
                    throw DomainRuleViolation::on('name', 'Enter a name.');
                }

                if (Environment::query()->where('project_id', $environment->project_id)->whereKeyNot($environment->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                    throw DomainRuleViolation::on('name', 'Another environment already has this name.');
                }

                $meta['name'] = $name;
                $meta['previousName'] = $environment->name;
                $environment->name = $name;
            }

            if (! $data->triggers instanceof Optional) {
                if ($environment->kind === EnvironmentKind::Preview) {
                    throw DomainRuleViolation::on('triggers', 'Previews follow their pull request; they have no triggers of their own.');
                }

                $triggers = $data->triggers;
                $triggers->branch = $triggers->branch === null || trim($triggers->branch) === '' ? null : trim($triggers->branch);
                $triggers->tagPattern = $triggers->tagPattern === null || trim($triggers->tagPattern) === '' ? null : trim($triggers->tagPattern);
                $meta['branch'] = $triggers->branch;
                $meta['tagPattern'] = $triggers->tagPattern;
                $environment->triggers = $triggers;
            }

            if ($meta === []) {
                return $environment;
            }

            $environment->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::EnvironmentUpdated,
                actor: $actor,
                teamId: $environment->team_id,
                subjectType: 'environment',
                subjectId: $environment->public_id,
                meta: $meta,
            ));

            return $environment;
        });
    }
}

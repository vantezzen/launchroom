<?php

declare(strict_types=1);

namespace App\Actions\ConfigAsCode;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\ConfigAsCode\ConfigFileProblemData;
use App\Data\ConfigAsCode\ManagedConfigData;
use App\Data\Services\GitSource;
use App\Enums\AuditAction;
use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Enums\ConfigAsCode\ConfigTrust;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Environment;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\ConfigAsCode\ConfigApplier;
use App\Services\ConfigAsCode\ConfigApplyFailed;
use App\Services\ConfigAsCode\ConfigApplyOutcome;
use App\Services\ConfigAsCode\ConfigApplyResult;
use App\Support\Changes\SpecDiff;
use App\Support\ConfigAsCode\ConfigCheck;
use App\Support\ConfigAsCode\ConfigContext;
use App\Support\ConfigAsCode\ConfigFile;
use App\Support\ConfigAsCode\ConfigFileValidator;
use App\Support\ConfigAsCode\Problems;
use Illuminate\Support\Facades\DB;

/**
 * Reads one launchroom.json into one environment (docs/18 §3.3): checks it,
 * applies it as staged changes (nothing at all when it has problems) and
 * records the outcome and what the file now manages. Deploying is the
 * caller's decision (a push deploys only when nothing else was staged,
 * docs/18 §3.4).
 */
final readonly class ApplyConfigFile
{
    public function __construct(
        private ConfigFileValidator $validator,
        private ConfigApplier $applier,
        private CurrentEnvironmentSpec $currentSpec,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  string|null  $content  null: there is no file at that commit
     */
    public function handle(Environment $environment, ?string $content, string $path, GitSource $repository, ?string $commitSha, ConfigTrust $trust, AuditActor $actor): ConfigApplyOutcome
    {
        return DB::transaction(function () use ($environment, $content, $path, $repository, $commitSha, $trust, $actor): ConfigApplyOutcome {
            $environment = Environment::query()->whereKey($environment->id)->with(['project', 'lastAppliedRevision'])->lockForUpdate()->firstOrFail();
            $record = EnvironmentConfigFile::query()->firstOrNew(['environment_id' => $environment->id], ['team_id' => $environment->team_id]);
            $previous = $record->exists ? $record->managed : null;
            $record->fill([
                'team_id' => $environment->team_id,
                'path' => $path,
                'repository' => $repository->fullName,
                'commit_sha' => $commitSha,
                'checked_at' => now(),
                'warnings' => [],
                'ignored' => [],
                'errors' => [],
            ]);

            if ($content === null) {
                $record->status = ConfigFileStatus::Missing;
                $record->fill(['managed' => new ManagedConfigData, 'content_hash' => null])->save();

                return new ConfigApplyOutcome(ConfigFileStatus::Missing, $record);
            }

            $record->content_hash = hash('sha256', $content);
            $check = $this->validator->check($content, ConfigContext::forProject($environment->project));

            if (! $check->valid() || ! $check->file instanceof ConfigFile) {
                return $this->invalid($environment, $record, $check->errors, $check->warnings, $check->summary($path), $commitSha, $actor);
            }

            $base = $environment->lastAppliedRevision?->spec;
            $before = $this->currentSpec->get($environment);
            $otherStaged = $base === null || ! SpecDiff::between($base, $before)->isEmpty();

            try {
                $result = DB::transaction(fn (): ConfigApplyResult => $this->applier->apply($environment, $check->file, $trust, $repository, $actor, $previous));
            } catch (ConfigApplyFailed $e) {
                return $this->invalid($environment, $record, $e->problems, $check->warnings, new ConfigCheck(null, $e->problems)->summary($path), $commitSha, $actor);
            }

            $after = $this->currentSpec->get($environment);
            $diff = SpecDiff::between($before, $after);
            $changed = ! $diff->isEmpty() || $result->domains !== [];

            $record->status = $changed ? ConfigFileStatus::Staged : ConfigFileStatus::Unchanged;
            $record->fill([
                'managed' => $result->managed,
                'warnings' => Problems::rows($check->warnings),
                'ignored' => Problems::rows($result->ignored),
            ]);
            $record->save();

            if ($changed) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::ConfigFileApplied,
                    actor: $actor,
                    teamId: $environment->team_id,
                    subjectType: 'environment',
                    subjectId: $environment->public_id,
                    meta: ['path' => $path, 'commit' => $commitSha, 'trust' => $trust->value, 'changes' => count($diff->items), 'domains' => count($result->domains)],
                ));
            }

            return new ConfigApplyOutcome($record->status, $record, $changed, $otherStaged);
        });
    }

    /**
     * @param  list<ConfigFileProblemData>  $errors
     * @param  list<ConfigFileProblemData>  $warnings
     */
    private function invalid(Environment $environment, EnvironmentConfigFile $record, array $errors, array $warnings, string $summary, ?string $commitSha, AuditActor $actor): ConfigApplyOutcome
    {
        // What the file managed stays managed: the last good file still describes it.
        $record->status = ConfigFileStatus::Invalid;
        $record->fill(['errors' => Problems::rows($errors), 'warnings' => Problems::rows($warnings)]);

        if (! $record->exists) {
            $record->managed = new ManagedConfigData;
        }

        $record->save();

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::ConfigFileInvalid,
            actor: $actor,
            teamId: $environment->team_id,
            subjectType: 'environment',
            subjectId: $environment->public_id,
            meta: ['path' => $record->path, 'commit' => $commitSha, 'problems' => count($errors)],
        ));

        return new ConfigApplyOutcome(ConfigFileStatus::Invalid, $record, errors: $errors, summary: $summary);
    }
}

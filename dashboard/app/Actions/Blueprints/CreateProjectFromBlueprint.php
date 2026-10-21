<?php

declare(strict_types=1);

namespace App\Actions\Blueprints;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Projects\CreateProject;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\ProjectBlueprint;
use App\Data\Environments\EnvironmentTriggers;
use App\Data\Projects\CreateProjectData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\Git\GitRef;
use Illuminate\Support\Facades\DB;

/**
 * A new project from a Compose file or a template (docs/17 §1): the
 * project (production tracking the branch when it builds from a
 * repository) plus the blueprint applied to production, audited as
 * `project.imported` with where it came from (never variable values).
 * Deploying is the caller's next step, as with a repository import.
 */
final readonly class CreateProjectFromBlueprint
{
    public function __construct(
        private CreateProject $createProject,
        private ApplyBlueprint $apply,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array{source: 'compose'|'template', template?: string}  $origin
     *
     * @throws DomainRuleViolation
     */
    public function handle(Team $team, string $name, ProjectBlueprint $blueprint, ?string $repository, ?string $branch, array $origin, User $user, AuditActor $actor): Project
    {
        if ($branch !== null && ! GitRef::isValid('refs/heads/'.$branch)) {
            throw DomainRuleViolation::on('branch', 'Enter a valid branch name.');
        }

        return DB::transaction(function () use ($team, $name, $blueprint, $repository, $branch, $origin, $user, $actor): Project {
            $project = $this->createProject->handle($team, new CreateProjectData($name), $actor);
            $production = $project->production()->sole();

            if ($repository !== null && $branch !== null) {
                $production->forceFill(['triggers' => new EnvironmentTriggers(branch: $branch)])->save();
            }

            $this->apply->handle($project, $production, $blueprint, $repository, $branch, $user, $actor);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ProjectImported,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'project',
                subjectId: $project->public_id,
                meta: [
                    ...$origin,
                    'services' => array_map(static fn (BlueprintService $service): string => $service->kind->value, $blueprint->services),
                ],
            ));

            return $project;
        });
    }
}

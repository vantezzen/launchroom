<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Environments\EnvironmentTriggers;
use App\Data\Projects\CreateProjectData;
use App\Enums\AuditAction;
use App\Enums\EnvironmentKind;
use App\Models\CanvasLayout;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Projects\EnvironmentSubnets;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;

/**
 * Creates a project with its production environment (docs/06 §1: exactly
 * one, always exists), its network subnet and its data-encryption key.
 */
final readonly class CreateProject
{
    public function __construct(
        private ProjectKeyring $keyring,
        private EnvironmentSubnets $subnets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Team $team, CreateProjectData $data, AuditActor $actor): Project
    {
        return DB::transaction(function () use ($team, $data, $actor): Project {
            /** @var list<string> $taken */
            $taken = Project::query()->where('team_id', $team->id)->pluck('slug')->all();

            $project = Project::query()->forceCreate([
                'team_id' => $team->id,
                'name' => $data->name,
                'slug' => Slug::unique(Slug::from($data->name, 'project'), $taken),
                // Its colour in charts, kept for good (direction.md §8.4).
                'color_slot' => Project::nextColorSlot($team->id),
            ]);

            $this->keyring->initialize($project);

            $production = Environment::query()->create([
                'team_id' => $team->id,
                'project_id' => $project->id,
                'name' => 'production',
                'slug' => 'production',
                'kind' => EnvironmentKind::Production,
                'triggers' => new EnvironmentTriggers(branch: 'main'),
                'network_subnet' => $this->subnets->allocate(),
            ]);

            CanvasLayout::query()->create(['environment_id' => $production->id]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ProjectCreated,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'project',
                subjectId: $project->public_id,
            ));

            return $project;
        });
    }
}

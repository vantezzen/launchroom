<?php

declare(strict_types=1);

namespace App\Actions\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Environments\CopyEnvironmentOptions;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Environments\EnvironmentTriggers;
use App\Enums\AuditAction;
use App\Enums\EnvironmentKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\CanvasLayout;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\EnvironmentSubnets;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;

/**
 * Creates a persistent environment as a copy of a base environment
 * (docs/06 §1) through CopyEnvironmentIntent: same services and settings,
 * the base's variables as rows of its own (secrets included, generated
 * values fresh), its own network, empty volumes, generated domains and new
 * database credentials. The copy is the base of the first Compare & promote
 * between the two (docs/06 §6.3). Nothing is deployed.
 */
final readonly class CreateEnvironment
{
    public function __construct(
        private EnvironmentSubnets $subnets,
        private CopyEnvironmentIntent $copy,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, CreateEnvironmentData $data, ?User $user, AuditActor $actor, CopyEnvironmentOptions $options = new CopyEnvironmentOptions): Environment
    {
        return DB::transaction(function () use ($project, $data, $user, $actor, $options): Environment {
            $base = $data->baseEnvironmentId === null
                ? $project->production()->firstOrFail()
                : $project->environments()->where('public_id', $data->baseEnvironmentId)->first();

            if ($base === null || $base->kind === EnvironmentKind::Preview) {
                throw DomainRuleViolation::on('baseEnvironmentId', 'The base environment does not belong to this project.');
            }

            /** @var list<string> $taken */
            $taken = $project->environments()->pluck('slug')->all();
            $slug = Slug::from($data->name, 'environment');

            if (in_array($slug, $taken, true)) {
                throw DomainRuleViolation::on('name', 'An environment with this name already exists.');
            }

            $environment = Environment::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'name' => $data->name,
                'slug' => $slug,
                'kind' => EnvironmentKind::Persistent,
                'base_environment_id' => $base->id,
                'triggers' => $data->triggers ?? new EnvironmentTriggers,
                'network_subnet' => $this->subnets->allocate(),
            ]);
            $environment->setRelation('project', $project);

            CanvasLayout::query()->create(['environment_id' => $environment->id]);

            $this->copy->handle($base, $environment, $options, $user);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::EnvironmentCreated,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: 'environment',
                subjectId: $environment->public_id,
                meta: ['project' => $project->public_id, 'base' => $base->public_id],
            ));

            return $environment;
        });
    }
}

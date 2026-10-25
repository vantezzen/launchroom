<?php

declare(strict_types=1);

namespace App\Actions\Environments;

use App\Data\Environments\CopyEnvironmentOptions;
use App\Enums\EnvironmentKind;
use App\Enums\PromotionKind;
use App\Enums\VariableType;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Variable;
use App\Services\Environments\EnvironmentIntentCopier;
use App\Services\Promotions\PromotionRecorder;
use App\Services\Variables\ProductionSecretPolicy;
use App\Support\Variables\GeneratedValues;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Copies a base environment's intent into another environment of the same
 * project (docs/06 §1, §5, §6.1 "creating a new environment and creating
 * previews also use it"):
 *
 * - every current service instance with its settings (enabled, branch
 *   override, build, deploy, scaling), its volumes (empty) and a generated
 *   domain; database services get fresh credentials from their engine driver;
 * - the variables targeted at the base (service and shared ones) as rows of
 *   their own targeted at the target, with fresh values for generated ones;
 *   secrets only with `secrets` (never from production into a preview while
 *   "production secrets must differ from preview" is on); keys the target
 *   already has are left alone. Shared variable opt-ins come along. Rows
 *   targeted at "All previews" and connection-owned variables need no copy:
 *   they apply by targeting;
 * - custom domains, deployments and volume data are never copied.
 *
 * With `recordBase`, the copy is recorded as an environment promotion of kind
 * `created`, the base of the first Compare & promote between the two.
 * Nothing is deployed. Callers authorize; this runs in a transaction.
 */
final readonly class CopyEnvironmentIntent
{
    public function __construct(
        private EnvironmentIntentCopier $copier,
        private ProductionSecretPolicy $policy,
        private PromotionRecorder $recorder,
    ) {}

    public function handle(Environment $from, Environment $to, CopyEnvironmentOptions $options = new CopyEnvironmentOptions, ?User $user = null): void
    {
        DB::transaction(function () use ($from, $to, $options, $user): void {
            $from->loadMissing('project');
            $to->setRelation('project', $from->project);
            $project = $from->project;

            $instances = ServiceInstance::query()
                ->current()
                ->where('environment_id', $from->id)
                ->with(['service', 'volumes'])
                ->orderBy('id')
                ->get();

            /** @var list<int> $existing */
            $existing = ServiceInstance::query()->current()->where('environment_id', $to->id)->pluck('service_id')->all();
            /** @var array<int, true> $present service ids with an instance in $to */
            $present = array_fill_keys($existing, true);

            $copied = [];

            foreach ($instances as $instance) {
                if (isset($present[$instance->service_id]) || ($options->serviceIds !== null && ! in_array($instance->service->public_id, $options->serviceIds, true))) {
                    continue;
                }

                $this->copier->copyService($instance, $to, $user);
                $present[$instance->service_id] = true;
                $copied[] = $instance->service->public_id;
            }

            $this->copier->copyPositions($from, $to, $copied);

            if ($options->variables) {
                $noSecrets = ! $options->secrets
                    || ($to->kind === EnvironmentKind::Preview && $from->kind === EnvironmentKind::Production && $this->policy->enabled($project));
                // "Production secrets must differ": a preview gets no secret
                // equal to production's, also not through another environment
                // that has production's values (docs/06 §3.1).
                $productionMacs = $to->kind === EnvironmentKind::Preview && ! $noSecrets ? $this->policy->productionSecretMacs($project) : [];

                $rows = Variable::query()
                    ->where('project_id', $project->id)
                    ->whereNull('connection_id')
                    ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $from->id))
                    ->with(['service', 'usedBy:services.id'])
                    ->orderBy('id')
                    ->get()
                    // Values signed with another variable (jwt()) are generated after it.
                    ->sortBy(static fn (Variable $row): int => GeneratedValues::signingKey($row->generator) === null ? 0 : 1, SORT_NUMERIC, false);

                foreach ($rows as $row) {
                    if (($row->service_id !== null && ! isset($present[$row->service_id]))
                        || ($noSecrets && $row->type === VariableType::Secret)
                        || ($row->type === VariableType::Secret && $productionMacs !== [] && $this->policy->equalsProduction($project, $row, $productionMacs))
                        || $this->copier->existing($to, $row->service_id, $row->key) instanceof Variable) {
                        continue;
                    }

                    // Without secrets, a value signed with one is withheld too: it stays empty.
                    $withheld = $noSecrets && GeneratedValues::signingKey($row->generator) !== null;
                    $copy = $this->copier->copyVariable($project, $row, $to, $withheld ? '' : null, $user);

                    if ($row->service_id === null && $row->usedBy->isNotEmpty()) {
                        $copy->usedBy()->syncWithoutDetaching($row->usedBy->modelKeys());
                    }
                }
            }

            if ($options->recordBase) {
                $this->recorder->record($from, $to, PromotionKind::Created, null, [], [], $user);
            }
        });
    }
}

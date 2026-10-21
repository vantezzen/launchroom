<?php

declare(strict_types=1);

namespace App\Services\Environments;

use App\Models\CanvasLayout;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Models\Volume;
use App\Services\Projects\ServiceProvisioner;
use App\Services\Variables\VariableStore;
use Illuminate\Contracts\Database\Query\Builder;
use SensitiveParameter;

/**
 * The building blocks shared by creating an environment from a base
 * (CopyEnvironmentIntent), previews, and Compare & promote (PromoteChanges):
 * copy a service instance into another environment, copy a variable row.
 *
 * Copies get what must be fresh (docs/06 §6.2): database credentials and
 * other generated values are generated anew, volumes start empty, generated
 * domains are generated for the target. Each copied variable becomes its own
 * row targeted at the target environment, so the environments can diverge
 * without touching each other. Callers own the transaction and authorization.
 */
final readonly class EnvironmentIntentCopier
{
    public function __construct(
        private ServiceProvisioner $provisioner,
        private VariableStore $variables,
    ) {}

    /**
     * Creates the service's instance in $to with the settings of $from (or
     * brings back a staged removal there). Domains: a generated one.
     */
    public function copyService(ServiceInstance $from, Environment $to, ?User $actor = null): ServiceInstance
    {
        $from->loadMissing(['service', 'volumes']);
        $existing = ServiceInstance::query()->where('environment_id', $to->id)->where('service_id', $from->service_id)->first();

        if ($existing instanceof ServiceInstance) {
            $existing->forceFill([
                'removed_at' => null,
                'enabled' => $from->enabled,
                'branch_override' => $from->branch_override,
                'build' => $from->build,
                'deploy' => $from->deploy,
                'scaling' => $from->scaling,
            ])->save();
            $instance = $existing;
        } else {
            $instance = $this->provisioner->provision($from->service, $to, $from, actor: $actor);
        }

        foreach ($from->volumes as $volume) {
            $this->addVolume($instance, $volume->mount_path);
        }

        return $instance;
    }

    public function addVolume(ServiceInstance $instance, string $mountPath): void
    {
        if (! Volume::query()->where('service_instance_id', $instance->id)->where('mount_path', $mountPath)->exists()) {
            Volume::query()->create(['team_id' => $instance->team_id, 'service_instance_id' => $instance->id, 'mount_path' => $mountPath]);
        }
    }

    /**
     * Canvas positions of $serviceIds (public ids) from $from's layout into
     * $to's, where $to has none yet: a copied graph looks the same.
     *
     * @param  list<string>  $serviceIds
     */
    public function copyPositions(Environment $from, Environment $to, array $serviceIds): void
    {
        $source = CanvasLayout::query()->where('environment_id', $from->id)->first();

        if (! $source instanceof CanvasLayout || $serviceIds === []) {
            return;
        }

        $target = CanvasLayout::query()->firstOrCreate(['environment_id' => $to->id]);
        $positions = $target->positions;

        foreach ($serviceIds as $serviceId) {
            if (! isset($positions[$serviceId]) && isset($source->positions[$serviceId])) {
                $positions[$serviceId] = $source->positions[$serviceId];
            }
        }

        $target->positions = $positions;
        $target->save();
    }

    /**
     * A new row like $source, targeted at $to only. The value is $value when
     * given, else a fresh one for generated values (re-evaluating their
     * generator), else the source's value.
     */
    public function copyVariable(Project $project, Variable $source, Environment $to, #[SensitiveParameter] ?string $value = null, ?User $actor = null): Variable
    {
        // Generated rows from before generators were recorded keep their value.
        $value ??= $source->generator !== null ? '${{ '.$source->generator.' }}' : $this->variables->plaintext($project, $source);

        $source->loadMissing('service');

        return $this->variables->create(
            $project,
            $source->service,
            $source->key,
            $value,
            $source->type,
            [['environment_id' => $to->id, 'all_previews' => false, 'branch_pattern' => null]],
            $source->exposure_override,
            actor: $actor,
        );
    }

    /**
     * Whether a non-connection row of this key and service already applies
     * to $environment directly (it would overlap).
     */
    public function existing(Environment $environment, ?int $serviceId, string $key): ?Variable
    {
        return Variable::query()
            ->where('project_id', $environment->project_id)
            ->where('service_id', $serviceId)
            ->whereNull('connection_id')
            ->where('key', $key)
            ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $environment->id))
            ->first();
    }

    /**
     * Removes $environment from a row's targets; deletes the row when it was
     * the only one.
     */
    public function detach(Variable $variable, Environment $environment): void
    {
        VariableTarget::query()->where('variable_id', $variable->id)->where('environment_id', $environment->id)->delete();

        if (! $variable->targets()->exists()) {
            $variable->delete();
        }
    }
}

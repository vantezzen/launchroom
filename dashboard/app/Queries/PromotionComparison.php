<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Promotions\PromotionComparisonData;
use App\Data\Promotions\PromotionEnvironmentData;
use App\Data\Promotions\PromotionImageData;
use App\Data\Promotions\PromotionServiceData;
use App\Data\Promotions\PromotionSnapshot;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Enums\DeploymentStatus;
use App\Enums\PromotionKind;
use App\Exceptions\UnresolvedReferences;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Promotions\PromotionPlan;
use App\Services\Promotions\PromotionPlanner;
use App\Services\Variables\ProductionSecretPolicy;
use App\Services\Variables\SpecVariableResolver;
use App\Support\BuildKey;
use App\Support\CanonicalJson;
use App\Support\Changes\SpecDiff;
use App\Support\Promotions\PromotionDiff;
use Illuminate\Support\Facades\Gate;

/**
 * The Compare & promote page (docs/06 §6.1): the plan's items, what the
 * comparison is relative to, and "Also promote images" per built service of
 * the source with an active image (docs/06 §6.4): reused as is when the
 * target's build inputs are equal, else the same commit is rebuilt there.
 */
final readonly class PromotionComparison
{
    public function __construct(
        private PromotionPlanner $planner,
        private SpecVariableResolver $resolver,
        private ProjectKeyring $keyring,
        private ProductionSecretPolicy $policy,
    ) {}

    public function get(Project $project, Environment $from, Environment $to): PromotionComparisonData
    {
        $plan = $this->planner->plan($from, $to);

        $environments = array_values($project->environments()
            ->orderByRaw("case kind when 'production' then 0 when 'persistent' then 1 else 2 end")
            ->orderBy('name')
            ->get()
            ->map(static fn (Environment $environment): PromotionEnvironmentData => self::environment($environment))
            ->all());

        $to->loadMissing('lastAppliedRevision');
        $targetStaged = $to->lastAppliedRevision !== null && ! SpecDiff::between($to->lastAppliedRevision->spec, $plan->targetSpec)->isEmpty();

        return new PromotionComparisonData(
            from: self::environment($from),
            to: self::environment($to),
            environments: $environments,
            baseDescription: $this->baseDescription($plan, $from, $to),
            hasBase: $plan->base instanceof PromotionSnapshot,
            items: $plan->items,
            services: $this->services($plan),
            images: $this->images($project, $plan, $from, $to),
            offered: PromotionDiff::offered($plan->items),
            secretsMustDiffer: $this->policy->enabled($project),
            canPromote: Gate::allows('promote', $to),
            targetHasStagedChanges: $targetStaged,
        );
    }

    /**
     * The switcher hint: how many changes $from has that $to doesn't.
     */
    public function count(Environment $from, Environment $to): int
    {
        return PromotionDiff::offered($this->planner->plan($from, $to)->items);
    }

    /**
     * @return list<PromotionServiceData>
     */
    private function services(PromotionPlan $plan): array
    {
        $services = [];

        foreach ([...$plan->targetSpec->services, ...$plan->sourceSpec->services] as $id => $service) {
            $services[$id] = new PromotionServiceData($service->id, $service->name, $service->slug, $service->kind);
        }

        usort($services, static fn (PromotionServiceData $a, PromotionServiceData $b): int => strcasecmp($a->name, $b->name));

        return $services;
    }

    private static function environment(Environment $environment): PromotionEnvironmentData
    {
        return new PromotionEnvironmentData($environment->public_id, $environment->name, $environment->kind);
    }

    private function baseDescription(PromotionPlan $plan, Environment $from, Environment $to): string
    {
        $last = $plan->lastPromotion;

        if (! $last instanceof EnvironmentPromotion) {
            return "{$from->name} and {$to->name} have never been compared: every difference is listed and nothing counts as a conflict.";
        }

        $when = $last->created_at->toFormattedDateString();
        [$older, $newer] = $last->from_environment_id === $from->id ? [$from->name, $to->name] : [$to->name, $from->name];

        return $last->kind === PromotionKind::Created
            ? "Changes since {$newer} was created from {$older} on {$when}."
            : "Changes since the last promotion from {$older} to {$newer} on {$when}.";
    }

    /**
     * @return list<PromotionImageData>
     */
    private function images(Project $project, PromotionPlan $plan, Environment $from, Environment $to): array
    {
        $instances = ServiceInstance::query()
            ->current()
            ->where('environment_id', $from->id)
            ->whereNotNull('active_deployment_id')
            ->with(['service', 'activeDeployment'])
            ->get();

        $images = [];

        foreach ($instances as $instance) {
            $deployment = $instance->activeDeployment;
            $service = $plan->sourceSpec->service($instance->service->public_id);

            if (! $deployment instanceof Deployment || ! $service instanceof ServiceSpec || ! $service->kind->isBuilt() || $deployment->image === null || $deployment->commit_sha === null || $deployment->status !== DeploymentStatus::Active) {
                continue;
            }

            $live = ServiceInstance::query()->current()
                ->where('environment_id', $to->id)
                ->where('service_id', $instance->service_id)
                ->with('activeDeployment:id,image')
                ->first()?->activeDeployment;

            if ($live instanceof Deployment && $live->image === $deployment->image) {
                // The target already runs this image.
                continue;
            }

            $differences = $this->differences($project, $plan, $service);

            $images[] = new PromotionImageData(
                serviceId: $service->id,
                name: $service->name,
                deploymentId: $deployment->public_id,
                commitSha: $deployment->commit_sha,
                sameInputs: $differences === [],
                differences: $differences,
            );
        }

        usort($images, static fn (PromotionImageData $a, PromotionImageData $b): int => strcasecmp($a->name, $b->name));

        return $images;
    }

    /**
     * Build inputs of $service that differ between source and target
     * (settings, branch override, build-scoped variable values; docs/04 §7).
     * A service the target doesn't have yet is copied with its settings.
     *
     * @return list<string>
     */
    private function differences(Project $project, PromotionPlan $plan, ServiceSpec $source): array
    {
        $target = $plan->targetSpec->service($source->id);

        if (! $target instanceof ServiceSpec) {
            return [];
        }

        $differences = [];

        if (CanonicalJson::encode($source->build->toArray()) !== CanonicalJson::encode($target->build->toArray())) {
            $differences[] = 'build settings';
        }

        if ($source->branchOverride !== $target->branchOverride) {
            $differences[] = 'branch override';
        }

        $sourceValues = $this->buildValues($project, $plan->sourceSpec, $source);
        $targetValues = $this->buildValues($project, $plan->targetSpec, $target);

        if ($sourceValues === null || $targetValues === null) {
            return [...$differences, 'variables with broken references'];
        }

        foreach (array_unique([...array_keys($sourceValues), ...array_keys($targetValues)]) as $key) {
            if (! hash_equals($sourceValues[$key] ?? '', $targetValues[$key] ?? '') || isset($sourceValues[$key]) !== isset($targetValues[$key])) {
                $differences[] = $key;
            }
        }

        return $differences;
    }

    /**
     * MACs of the build-scoped values (never the values).
     *
     * @return array<string, string>|null
     */
    private function buildValues(Project $project, EnvironmentSpec $spec, ServiceSpec $service): ?array
    {
        try {
            $env = $this->resolver->resolve($project, $spec, $service);
        } catch (UnresolvedReferences) {
            return null;
        }

        $values = [];

        foreach (BuildKey::buildScopedKeys($spec, $service) as $key) {
            $value = $env->get($key);

            if ($value !== null) {
                $values[$key] = $this->keyring->mac($project, 'promotion-build', $value);
            }
        }

        return $values;
    }
}

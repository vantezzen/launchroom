<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Specs\ChangeItem;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\StagedChanges\PromotedImageData;
use App\Data\StagedChanges\StagedChangesData;
use App\Data\StagedChanges\StagedServiceData;
use App\Enums\ChangeEffect;
use App\Exceptions\UnresolvedReferences;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Services\ConfigAsCode\ManagedPaths;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Changes\SpecDiff;
use App\Support\Variables\ReferenceError;
use Illuminate\Support\Facades\Gate;

/**
 * The staged changes pill and review page (docs/06 §4): SpecDiff between the
 * last applied revision and the current intent, grouped by service (services
 * first, by name; environment-level items last).
 */
final readonly class StagedChangesView
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private SpecVariableResolver $resolver,
    ) {}

    public function get(Environment $environment, bool $withBlockers = false): StagedChangesData
    {
        $environment->loadMissing(['project', 'lastAppliedRevision']);
        $base = $environment->lastAppliedRevision?->spec;
        $current = $this->currentSpec->get($environment);
        $changes = SpecDiff::between($base, $current);

        /** @var array<string, list<ChangeItem>> $byService */
        $byService = [];
        $other = [];

        foreach ($changes->items as $item) {
            if ($item->serviceId === null) {
                $other[] = $item;
            } else {
                $byService[$item->serviceId][] = $item;
            }
        }

        $groups = [];

        foreach ($byService as $serviceId => $items) {
            $service = $current->service($serviceId) ?? $base?->service($serviceId);
            $groups[] = new StagedServiceData(
                serviceId: $serviceId,
                name: $service->name ?? $serviceId,
                slug: $service?->slug,
                effect: $changes->effectFor($serviceId),
                items: $items,
            );
        }

        // Services restarted only through references (no item of their own).
        foreach ($changes->services as $serviceId => $effect) {
            if (! isset($byService[$serviceId]) && $effect !== ChangeEffect::None && ($service = $current->service($serviceId)) instanceof ServiceSpec) {
                $groups[] = new StagedServiceData($serviceId, $service->name, $service->slug, $effect, []);
            }
        }

        usort($groups, static fn (StagedServiceData $a, StagedServiceData $b): int => strcasecmp($a->name, $b->name));

        if ($other !== []) {
            $groups[] = new StagedServiceData(null, 'Environment and shared variables', null, ChangeEffect::None, $other);
        }

        $images = $this->promotedImages($environment, $current);

        return new StagedChangesData(
            projectId: $environment->project->public_id,
            projectName: $environment->project->name,
            environmentId: $environment->public_id,
            environmentName: $environment->name,
            count: count($changes->items) + count($images),
            neverDeployed: $base === null,
            revision: $environment->lastAppliedRevision?->number,
            groups: $groups,
            blockers: $withBlockers ? $this->blockers($environment, $current) : [],
            canDeploy: Gate::allows('deploy', $environment),
            environmentKind: $environment->kind,
            promotedImages: $images,
            configFile: ManagedPaths::staged($environment, $current, $changes->items),
        );
    }

    /**
     * Commits pinned by "Also promote images" that the next Deploy builds
     * (docs/06 §6.4).
     *
     * @return list<PromotedImageData>
     */
    private function promotedImages(Environment $environment, EnvironmentSpec $current): array
    {
        $promotion = EnvironmentPromotion::query()
            ->where('to_environment_id', $environment->id)
            ->whereNull('images_applied_at')
            ->whereRaw("images <> '[]'::jsonb AND images <> '{}'::jsonb")
            ->with('fromEnvironment:id,name')
            ->latest('id')
            ->first();

        if (! $promotion instanceof EnvironmentPromotion) {
            return [];
        }

        $images = [];

        foreach ($promotion->images as $serviceId => $pin) {
            $service = $current->service($serviceId);

            if ($service instanceof ServiceSpec) {
                $images[] = new PromotedImageData($serviceId, $service->name, $pin['commitSha'], $pin['deployment'], $promotion->fromEnvironment->name);
            }
        }

        return $images;
    }

    /**
     * @return list<string>
     */
    private function blockers(Environment $environment, EnvironmentSpec $spec): array
    {
        try {
            $this->resolver->resolveAll($environment->project, $spec);
        } catch (UnresolvedReferences $e) {
            return array_map(static fn (ReferenceError $error): string => $error->message(), $e->errors);
        }

        return [];
    }
}

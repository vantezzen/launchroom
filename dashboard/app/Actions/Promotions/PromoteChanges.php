<?php

declare(strict_types=1);

namespace App\Actions\Promotions;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Services\RemoveService;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Promotions\PromoteChangesData;
use App\Data\Promotions\PromoteItemInput;
use App\Data\Promotions\PromotionEntry;
use App\Data\Promotions\PromotionItem;
use App\Data\Promotions\PromotionSnapshot;
use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Data\Specs\ServiceSpec;
use App\Enums\AuditAction;
use App\Enums\ChangeKind;
use App\Enums\DeploymentStatus;
use App\Enums\PromotionEntryType;
use App\Enums\PromotionKind;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Variable;
use App\Services\Environments\EnvironmentIntentCopier;
use App\Services\Promotions\PromotionPlan;
use App\Services\Promotions\PromotionPlanner;
use App\Services\Promotions\PromotionRecorder;
use App\Services\Variables\SecretExposureGuard;
use App\Services\Variables\VariableStore;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Data;

/**
 * "Stage in target" of Compare & promote (docs/06 §6.1): applies the selected
 * items of the three-way diff to the target's intent as staged changes.
 * Nothing goes live until the target is deployed.
 *
 * The comparison is recomputed here; a selected item that is no longer
 * offered (someone changed either side meanwhile) refuses the whole request.
 * Dependencies (a service's variables need the service) must be selected
 * together. Secret values come from the request, or are copied from the
 * source on explicit request (blocked by "production secrets must differ
 * from preview"); generated values are generated afresh; added services get
 * fresh credentials, empty volumes and a generated domain. Variables are
 * changed in place when their row only applies to the target, else the
 * target gets a row of its own (the row is split).
 *
 * "Also promote images" pins the source's active commit per service on the
 * promotion; the target's next Deploy builds exactly that commit, which
 * reuses the source's image when the build inputs are equal (docs/06 §6.4).
 *
 * Recorded in `environment_promotions` with the new three-way bases: items
 * left out stay offered next time.
 */
final readonly class PromoteChanges
{
    public function __construct(
        private PromotionPlanner $planner,
        private PromotionRecorder $recorder,
        private EnvironmentIntentCopier $copier,
        private VariableStore $variables,
        private SecretExposureGuard $exposure,
        private RemoveService $removeService,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, PromoteChangesData $data, User $user, AuditActor $actor): EnvironmentPromotion
    {
        return DB::transaction(function () use ($project, $data, $user, $actor): EnvironmentPromotion {
            [$from, $to] = $this->environments($project, $data);
            $plan = $this->planner->plan($from, $to);

            /** @var array<string, PromoteItemInput> $inputs */
            $inputs = [];

            foreach ($data->items as $input) {
                $inputs[$input->path] = $input;
            }

            $selected = $this->validate($plan, $inputs, $from, $to);
            $images = $this->images($from, $data->images);

            if ($selected === [] && $images === []) {
                throw DomainRuleViolation::on('items', 'Select at least one change to promote.');
            }

            $changedKeys = [];

            foreach ($this->ordered($selected) as $item) {
                $key = $this->apply($project, $plan, $from, $to, $item, $inputs[$item->path], $user, $actor);

                if ($key !== null) {
                    $changedKeys[] = $key;
                }
            }

            // Browser-inlined variables never embed secrets; production secrets differ from preview.
            $this->exposure->assertNoPublicSecrets($project, array_values(array_unique($changedKeys)), 'items');

            $promotion = $this->recorder->record($from, $to, PromotionKind::Promoted, $this->nextBase($plan, $selected), array_keys($selected), $images, $user);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ChangesPromoted,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: 'environment',
                subjectId: $to->public_id,
                meta: [
                    'project' => $project->public_id,
                    'from' => $from->public_id,
                    'promotion' => $promotion->public_id,
                    'items' => array_keys($selected),
                    'images' => array_keys($images),
                ],
            ));

            return $promotion;
        });
    }

    /**
     * @return array{Environment, Environment}
     */
    private function environments(Project $project, PromoteChangesData $data): array
    {
        if ($data->from === $data->to) {
            throw DomainRuleViolation::on('to', 'Choose two different environments.');
        }

        $environments = Environment::query()
            ->where('project_id', $project->id)
            ->whereIn('public_id', [$data->from, $data->to])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('public_id');

        $from = $environments->get($data->from);
        $to = $environments->get($data->to);

        if (! $from instanceof Environment || ! $to instanceof Environment) {
            throw DomainRuleViolation::on('to', 'Both environments must belong to this project.');
        }

        $from->setRelation('project', $project);
        $to->setRelation('project', $project);

        return [$from, $to];
    }

    /**
     * @param  array<string, PromoteItemInput>  $inputs
     * @return array<string, PromotionItem>
     */
    private function validate(PromotionPlan $plan, array $inputs, Environment $from, Environment $to): array
    {
        $selected = [];

        foreach ($inputs as $path => $input) {
            $item = $plan->item($path);

            if (! $item instanceof PromotionItem || ! $item->selectable) {
                throw DomainRuleViolation::on('items', "{$to->name} or {$from->name} changed since you opened the comparison. Reload it and review the changes again.");
            }

            $hasValue = $input->value !== null && $input->value !== '';

            if (($hasValue || $input->copyValue) && ! $item->acceptsValue) {
                throw DomainRuleViolation::on('items', "{$item->label}: only secrets take a value here.");
            }

            if ($input->copyValue && ! $item->canCopyValue) {
                throw DomainRuleViolation::on('items', "{$item->label}: ".($item->copyBlockedReason ?? 'its value can’t be copied.'));
            }

            if ($item->needsValue && ! $hasValue && ! $input->copyValue) {
                throw DomainRuleViolation::on('items', "{$item->label}: enter the value for {$to->name}, or copy the value from {$from->name}.");
            }

            $selected[$path] = $item;
        }

        foreach ($selected as $item) {
            foreach ($item->dependsOn as $dependency) {
                if (! isset($selected[$dependency])) {
                    $needed = $plan->item($dependency);

                    throw DomainRuleViolation::on('items', "{$item->label} needs “".($needed->label ?? $dependency).'” too.');
                }
            }
        }

        return $selected;
    }

    /**
     * New services first (their variables depend on them), then shared
     * variables (usages depend on them), removals of services last.
     *
     * @param  array<string, PromotionItem>  $selected
     * @return list<PromotionItem>
     */
    private function ordered(array $selected): array
    {
        $rank = static fn (PromotionItem $item): int => match (true) {
            $item->type === PromotionEntryType::Service && $item->kind !== ChangeKind::Removed => 0,
            $item->type === PromotionEntryType::SharedVariable => 1,
            $item->type === PromotionEntryType::Service => 3,
            default => 2,
        };

        $items = array_values($selected);
        usort($items, static fn (PromotionItem $a, PromotionItem $b): int => [$rank($a), $a->path] <=> [$rank($b), $b->path]);

        return $items;
    }

    /**
     * @return string|null a variable key that changed (for the save-time checks)
     */
    private function apply(Project $project, PromotionPlan $plan, Environment $from, Environment $to, PromotionItem $item, PromoteItemInput $input, User $user, AuditActor $actor): ?string
    {
        $service = $item->serviceId === null ? null : Service::query()->where('project_id', $project->id)->where('public_id', $item->serviceId)->first();

        if ($item->serviceId !== null && ! $service instanceof Service) {
            throw DomainRuleViolation::on('items', "{$item->label}: the service no longer exists.");
        }

        switch ($item->type) {
            case PromotionEntryType::Service:
                $this->applyService($from, $to, $item, $service, $user, $actor);

                return null;

            case PromotionEntryType::Field:
                $this->applyField($plan, $to, $item, $service);

                return null;

            case PromotionEntryType::Variable:
            case PromotionEntryType::SharedVariable:
                return $this->applyVariable($project, $from, $to, $item, $input, $service, $user);

            case PromotionEntryType::SharedUsage:
                $shared = $this->copier->existing($to, null, (string) $item->key);

                if ($shared instanceof Variable && $service instanceof Service) {
                    $item->kind === ChangeKind::Removed
                        ? $shared->usedBy()->detach($service->id)
                        : $shared->usedBy()->syncWithoutDetaching([$service->id]);
                }

                return $item->key;

            case PromotionEntryType::Volume:
                $instance = $this->instance($to, $service);

                if ($instance instanceof ServiceInstance) {
                    $this->copier->addVolume($instance, (string) $item->key);
                }

                return null;

            case PromotionEntryType::Triggers:
                $to->forceFill(['triggers' => $plan->sourceSpec->environment->triggers])->save();

                return null;

            default:
                return null;
        }
    }

    private function applyService(Environment $from, Environment $to, PromotionItem $item, ?Service $service, User $user, AuditActor $actor): void
    {
        if ($item->kind === ChangeKind::Removed) {
            $instance = $this->instance($to, $service);

            if ($instance instanceof ServiceInstance) {
                $this->removeService->handle($instance, $actor);
            }

            return;
        }

        $source = $this->instance($from, $service);

        if (! $source instanceof ServiceInstance) {
            throw DomainRuleViolation::on('items', "{$item->label}: the service is gone from {$from->name}.");
        }

        $this->copier->copyService($source, $to, $user);
        $this->copier->copyPositions($from, $to, [$source->service->public_id]);
    }

    private function applyField(PromotionPlan $plan, Environment $to, PromotionItem $item, ?Service $service): void
    {
        $instance = $this->instance($to, $service);
        $spec = $service instanceof Service ? $plan->sourceSpec->service($service->public_id) : null;

        if (! $instance instanceof ServiceInstance || ! $spec instanceof ServiceSpec || $item->key === null) {
            return;
        }

        if ($item->key === 'enabled') {
            $instance->forceFill(['enabled' => $spec->enabled])->save();

            return;
        }

        if ($item->key === 'branchOverride') {
            $instance->forceFill(['branch_override' => $spec->branchOverride])->save();

            return;
        }

        [$group, $property] = explode('.', $item->key, 2) + [1 => ''];
        $recorded = $spec->{$group} ?? null;
        $current = $instance->{$group};

        if (! $recorded instanceof Data || ! $current instanceof Data) {
            return;
        }

        $values = [...$current->toArray(), $property => $recorded->toArray()[$property] ?? null];

        $instance->forceFill([$group => match ($group) {
            'build' => BuildSettings::from($values),
            'deploy' => DeploySettings::from($values),
            default => ScalingSettings::from($values),
        }])->save();
    }

    private function applyVariable(Project $project, Environment $from, Environment $to, PromotionItem $item, PromoteItemInput $input, ?Service $service, User $user): string
    {
        $key = (string) $item->key;
        $serviceId = $service?->id;
        $target = $this->copier->existing($to, $serviceId, $key);
        $source = $this->copier->existing($from, $serviceId, $key);
        $entered = $input->value !== null && $input->value !== '' ? $input->value : null;

        if ($item->kind === ChangeKind::Removed) {
            if ($target instanceof Variable) {
                $this->copier->detach($target, $to);
            }

            return $key;
        }

        if (! $source instanceof Variable) {
            throw DomainRuleViolation::on('items', "{$item->label}: the variable is gone from {$from->name}.");
        }

        // Fork previews get no secrets (docs/09 R20): a secret promoted into
        // one needs a value entered here; neither the source's value nor a
        // withheld one may become a row of the preview.
        if ($to->secrets_withheld && $entered === null && ($item->secret || $source->type === VariableType::Secret) && ! $source->generated
            && (! $target instanceof Variable || $input->copyValue || $target->targets()->count() !== 1)) {
            throw DomainRuleViolation::on('items', "{$item->label}: previews of forks get no secrets. Enter a value for this preview.");
        }

        if (! $target instanceof Variable) {
            // Added: the entered value, else a fresh generated one or the source's.
            $this->copier->copyVariable($project, $source, $to, $entered, $user);

            return $key;
        }

        // Changed: the source's type and exposure; the value per the item.
        $value = match (true) {
            $entered !== null => $entered,
            $input->copyValue || ! $item->acceptsValue => $this->variables->plaintext($project, $source),
            default => null, // secret: keep the target's value
        };

        if ($target->generated && $source->generated && $entered === null) {
            $value = null; // never promote generated values
        }

        $onlyHere = $target->targets()->count() === 1;

        if (! $onlyHere) {
            $kept = $value ?? $this->variables->plaintext($project, $target);
            $this->copier->detach($target, $to);
            $copy = $this->copier->copyVariable($project, $source, $to, $kept, $user);

            if ($serviceId === null && $target->usedBy()->exists()) {
                $copy->usedBy()->syncWithoutDetaching($target->usedBy()->pluck('services.id')->all());
            }

            return $key;
        }

        $this->variables->updateMeta($target, $source->key, $source->type, $source->exposure_override, $user);

        if ($value !== null && ! hash_equals($this->variables->plaintext($project, $target), $value)) {
            $this->variables->updateValue($project, $target, $value, $user);
        }

        return $key;
    }

    private function instance(Environment $environment, ?Service $service): ?ServiceInstance
    {
        if (! $service instanceof Service) {
            return null;
        }

        $instance = ServiceInstance::query()->current()->where('environment_id', $environment->id)->where('service_id', $service->id)->first();

        $instance?->setRelation('service', $service);

        return $instance;
    }

    /**
     * Pins the source's active commit per service (docs/06 §6.4).
     *
     * @param  list<string>  $serviceIds
     * @return array<string, array{deployment: string, commitSha: string, gitRef: string|null}>
     */
    private function images(Environment $from, array $serviceIds): array
    {
        $pins = [];

        foreach (array_unique($serviceIds) as $serviceId) {
            $instance = ServiceInstance::query()
                ->current()
                ->where('environment_id', $from->id)
                ->whereHas('service', static fn ($query) => $query->where('public_id', $serviceId))
                ->with('activeDeployment')
                ->first();
            $deployment = $instance?->activeDeployment;

            if (! $deployment instanceof Deployment || $deployment->status !== DeploymentStatus::Active || $deployment->image === null || $deployment->commit_sha === null) {
                throw DomainRuleViolation::on('images', "{$from->name} has no active build of that service to promote.");
            }

            $pins[$serviceId] = ['deployment' => $deployment->public_id, 'commitSha' => $deployment->commit_sha, 'gitRef' => $deployment->git_ref];
        }

        return $pins;
    }

    /**
     * The base for the next comparison from → to: the source as it is, except
     * for offered items that weren't promoted, which keep their old base so
     * they are offered again.
     *
     * @param  array<string, PromotionItem>  $selected
     */
    private function nextBase(PromotionPlan $plan, array $selected): PromotionSnapshot
    {
        $entries = $plan->source->entries;
        $old = $plan->base ?? $plan->target;

        foreach ($plan->items as $item) {
            if (isset($selected[$item->path])) {
                continue;
            }

            $previous = $old->get($item->path);

            if ($previous instanceof PromotionEntry) {
                $entries[$item->path] = $previous;
            } else {
                unset($entries[$item->path]);
            }
        }

        return new PromotionSnapshot($entries);
    }
}

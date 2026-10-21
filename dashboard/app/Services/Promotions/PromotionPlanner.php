<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Enums\EnvironmentKind;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Variables\ProductionSecretPolicy;
use App\Support\Promotions\PromotionDiff;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Compares two environments of a project for Compare & promote (docs/06 §6):
 * current intents as snapshots, the base from their last promotion (either
 * direction), the three-way diff, and the copy rules of the "production
 * secrets must differ from preview" policy.
 */
final readonly class PromotionPlanner
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private PromotionSnapshots $snapshots,
        private ProductionSecretPolicy $policy,
    ) {}

    public function plan(Environment $from, Environment $to): PromotionPlan
    {
        $from->loadMissing('project');
        $to->setRelation('project', $from->project);
        $project = $from->project;

        $sourceSpec = $this->currentSpec->get($from);
        $targetSpec = $this->currentSpec->get($to);
        $source = $this->snapshots->of($project, $sourceSpec);
        $target = $this->snapshots->of($project, $targetSpec);

        $last = EnvironmentPromotion::query()
            ->where(static fn (Builder $query) => $query
                ->where(static fn (Builder $pair) => $pair->where('from_environment_id', $from->id)->where('to_environment_id', $to->id))
                ->orWhere(static fn (Builder $pair) => $pair->where('from_environment_id', $to->id)->where('to_environment_id', $from->id)))
            ->latest('id')
            ->first();
        $base = $last === null ? null : ($last->from_environment_id === $from->id ? $last->snapshot : $last->target_snapshot);

        $items = PromotionDiff::between($base, $source, $target);
        $blocked = $this->copyBlockedReason($from, $to);

        if ($blocked !== null) {
            foreach ($items as $item) {
                if ($item->canCopyValue && $item->secret) {
                    $item->canCopyValue = false;
                    $item->copyBlockedReason = $blocked;
                }
            }
        }

        return new PromotionPlan($sourceSpec, $targetSpec, $source, $target, $base, $last, $items);
    }

    /**
     * Copying a secret value between production and a preview would make
     * them equal, which the policy forbids; into a fork preview, which gets
     * no secrets at all.
     */
    public function copyBlockedReason(Environment $from, Environment $to): ?string
    {
        // Fork previews get no secrets (docs/09 R20), also not by promotion.
        if ($to->secrets_withheld) {
            return 'Previews of forks get no secrets: enter a new value.';
        }

        $kinds = [$from->kind, $to->kind];

        if (in_array(EnvironmentKind::Production, $kinds, true) && in_array(EnvironmentKind::Preview, $kinds, true) && $this->policy->enabled($from->project)) {
            return 'Production secrets must differ from preview in this project: enter a new value.';
        }

        return null;
    }
}

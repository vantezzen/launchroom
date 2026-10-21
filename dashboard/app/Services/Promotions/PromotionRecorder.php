<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Data\Promotions\PromotionSnapshot;
use App\Enums\PromotionKind;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;

/**
 * Writes `environment_promotions` rows (docs/06 §6.3) with both bases of the
 * next comparison: the source side (`snapshot`) and the target as it is now
 * (`target_snapshot`). Fingerprints only: readable values are dropped.
 */
final readonly class PromotionRecorder
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private PromotionSnapshots $snapshots,
    ) {}

    public function snapshot(Environment $environment): PromotionSnapshot
    {
        $environment->loadMissing('project');

        return $this->snapshots->of($environment->project, $this->currentSpec->get($environment));
    }

    /**
     * @param  list<string>  $items  promoted paths
     * @param  array<string, array{deployment: string, commitSha: string, gitRef: string|null}>  $images  pinned commits by service public id
     */
    public function record(Environment $from, Environment $to, PromotionKind $kind, ?PromotionSnapshot $sourceBase, array $items, array $images, ?User $actor): EnvironmentPromotion
    {
        $from->loadMissing('project');
        $to->setRelation('project', $from->project);

        return EnvironmentPromotion::query()->create([
            'team_id' => $from->team_id,
            'project_id' => $from->project_id,
            'from_environment_id' => $from->id,
            'to_environment_id' => $to->id,
            'kind' => $kind,
            'source_revision_id' => $from->last_applied_revision_id,
            'snapshot' => ($sourceBase ?? $this->snapshot($from))->withoutDisplays(),
            'target_snapshot' => $this->snapshot($to)->withoutDisplays(),
            'items' => $items,
            'images' => $images,
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }
}

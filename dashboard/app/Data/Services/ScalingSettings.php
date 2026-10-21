<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Data\Attributes\ChangeAffects;
use App\Data\Attributes\Promotion;
use App\Enums\ChangeEffect;
use App\Enums\PlacementStrategy;
use App\Enums\PromotionClass;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * `service_instances.scaling` (docs/07): environment-specific by default
 * (docs/06 §6.2), runtime-only (a change redeploys the existing image, no
 * rebuild). Placement constraints feed the scheduler (docs/02 §2):
 * required server labels, spread or pack, and server allow/deny lists (server
 * public ids; an empty allow-list means any server). Validated against the
 * team's servers on save (App\Services\Deployments\PlacementConstraints).
 */
final class ScalingSettings extends Data
{
    /**
     * @param  array<string, string>  $placementLabels  servers must carry all of these labels
     * @param  list<string>  $allowServers  only these servers (public ids); empty = any
     * @param  list<string>  $denyServers  never these servers (public ids)
     */
    public function __construct(
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::EnvironmentSpecific), Min(0), Max(64)]
        public int $replicas = 1,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::EnvironmentSpecific), Min(0.05), Max(1024)]
        public float $cpus = 1.0,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::EnvironmentSpecific), Min(16), Max(1048576)]
        public int $memoryMb = 512,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::EnvironmentSpecific)]
        public array $placementLabels = [],
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::EnvironmentSpecific)]
        public PlacementStrategy $strategy = PlacementStrategy::Spread,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::EnvironmentSpecific), Max(64)]
        public array $allowServers = [],
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::EnvironmentSpecific), Max(64)]
        public array $denyServers = [],
    ) {}

    /**
     * Empty or omitted lists are valid ("no labels", "any server"). The inferred
     * `required` rule would refuse them and with it every scaling save that
     * has no placement constraints.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'placementLabels' => ['sometimes', 'array'],
            'allowServers' => ['sometimes', 'array', 'max:64'],
            'denyServers' => ['sometimes', 'array', 'max:64'],
        ];
    }

    /** pids limit per container (daemon policy floor requires one). */
    public function pids(): int
    {
        return max(64, min(4096, $this->memoryMb));
    }
}

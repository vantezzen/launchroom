<?php

declare(strict_types=1);

namespace App\Data\Specs;

use App\Data\Attributes\ChangeAffects;
use App\Data\Attributes\Promotion;
use App\Data\Environments\EnvironmentTriggers;
use App\Enums\ChangeEffect;
use App\Enums\EnvironmentKind;
use App\Enums\PromotionClass;
use Spatie\LaravelData\Data;

/**
 * Identity of the environment a revision belongs to. Everything the variable
 * resolver needs for system variables is in the spec, so a revision can be
 * resolved without reading current intent.
 */
final class EnvironmentMetaSpec extends Data
{
    public function __construct(
        public string $id,
        public string $slug,
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::Never)]
        public string $name,
        public EnvironmentKind $kind,
        public string $projectId,
        public string $projectSlug,
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::EnvironmentSpecific)]
        public EnvironmentTriggers $triggers = new EnvironmentTriggers,
        /** The git branch of a preview environment (branch-targeted variables). */
        public ?string $branch = null,
    ) {}
}

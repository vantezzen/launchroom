<?php

declare(strict_types=1);

namespace App\Data\Environments;

use App\Data\Attributes\ChangeAffects;
use App\Data\Attributes\Promotion;
use App\Enums\ChangeEffect;
use App\Enums\PromotionClass;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * `environments.triggers` (docs/06 §2): pushes to a branch matching `branch`
 * and tags matching `tagPattern` deploy here (App\Listeners\Git\DeployOnGitPush);
 * both empty means manual only. Production may track tags only (no branch).
 * They never restart anything by themselves.
 */
final class EnvironmentTriggers extends Data
{
    /** A git ref name with `*` / `?` globs (docs/06 §2), no spaces. */
    public const string GLOB = '/^[A-Za-z0-9._\/*?+@-]{1,255}$/D';

    public function __construct(
        /** Branch (glob) that deploys here on push, e.g. "main" or "release/*". */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::EnvironmentSpecific), Max(255), Regex(self::GLOB)]
        public ?string $branch = null,
        /** Tag glob that deploys here, e.g. "v*". */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::EnvironmentSpecific), Max(255), Regex(self::GLOB)]
        public ?string $tagPattern = null,
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::EnvironmentSpecific)]
        public bool $pullRequests = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Environments;

use App\Data\Attributes\ChangeAffects;
use App\Data\Attributes\Promotion;
use App\Enums\ChangeEffect;
use App\Enums\PromotionClass;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * `environments.triggers` (docs/06 §2): pushes to a branch matching `branch`
 * and tags matching `tagPattern` deploy here (App\Actions\Git\DeployPush);
 * both empty means manual only. Production may track tags only (no branch).
 * They never restart anything by themselves. With `waitForChecks` a push
 * deployment waits for its commit's CI checks first (docs/06 §2.5).
 */
final class EnvironmentTriggers extends Data
{
    /** A git ref name with `*` / `?` globs (docs/06 §2), no spaces. */
    public const string GLOB = '/^[A-Za-z0-9._\/*?+@-]{1,255}$/D';

    public const int DEFAULT_CHECKS_TIMEOUT = 30;

    public const int MIN_CHECKS_TIMEOUT = 5;

    public const int MAX_CHECKS_TIMEOUT = 240;

    public function __construct(
        /** Branch (glob) that deploys here on push, e.g. "main" or "release/*". */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::EnvironmentSpecific), Max(255), Regex(self::GLOB)]
        public ?string $branch = null,
        /** Tag glob that deploys here, e.g. "v*". */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::EnvironmentSpecific), Max(255), Regex(self::GLOB)]
        public ?string $tagPattern = null,
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::EnvironmentSpecific)]
        public bool $pullRequests = false,
        /** Deploy after checks pass (D111, docs/06 §2.5): a push waits for the commit's CI checks. */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::EnvironmentSpecific)]
        public bool $waitForChecks = false,
        /** How long a push waits for its checks before it gives up. */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::EnvironmentSpecific), Min(self::MIN_CHECKS_TIMEOUT), Max(self::MAX_CHECKS_TIMEOUT)]
        public int $checksTimeoutMinutes = self::DEFAULT_CHECKS_TIMEOUT,
    ) {}
}

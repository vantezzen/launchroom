<?php

declare(strict_types=1);

namespace App\Data\Specs;

use App\Data\Attributes\Promotion;
use App\Enums\PromotionClass;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use Spatie\LaravelData\Data;

/**
 * A variable as it applies to one environment. The value is only present as
 * its ciphertext (an "encrypted reference", docs/06 §3.5): bound to the
 * variable id and version, so a revision can restore or resolve it but never
 * exposes it. `version` is what the diff compares.
 */
final class VariableSpec extends Data
{
    public const string LAYER_ENVIRONMENT = 'environment';

    public const string LAYER_BRANCH = 'branch';

    /**
     * @param  list<string>  $references  `svc.KEY`, `shared.KEY` or `KEY` (same scope)
     */
    public function __construct(
        public string $id,
        #[Promotion(PromotionClass::Structural)]
        public string $key,
        #[Promotion(PromotionClass::Structural)]
        public VariableType $type,
        #[Promotion(PromotionClass::Never)]
        public int $version,
        #[Promotion(PromotionClass::Structural)]
        public VariableExposure $exposure,
        #[Promotion(PromotionClass::Never)]
        public string $ciphertext,
        /** `environment` or `branch` (a preview branch override; wins over environment). */
        public string $layer = self::LAYER_ENVIRONMENT,
        public bool $generated = false,
        public array $references = [],
        public ?string $connectionId = null,
        /** Fork previews: a secret withheld from this environment resolves to "" (docs/09 R20). */
        public bool $withheld = false,
    ) {}
}

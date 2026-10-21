<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Data\Attributes\ChangeAffects;
use App\Data\Attributes\Promotion;
use App\Enums\ChangeEffect;
use App\Enums\PromotionClass;
use App\Support\PublicId;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * `service_instances.build` (docs/07): every field is build-affecting
 * (docs/04 §7) except watch paths, which only filter pushes.
 */
final class BuildSettings extends Data
{
    /**
     * @param  list<string>  $watchPaths
     * @param  list<string>  $disabledDecisions  preset decision ids the user removed (plan chips, docs/04 §4)
     */
    public function __construct(
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::Structural), In(['auto', 'dockerfile', 'railpack', 'static'])]
        public string $builder = 'auto',
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::Structural), Max(1024)]
        public ?string $dockerfilePath = null,
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::Structural), Max(4096)]
        public ?string $installCommand = null,
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::Structural), Max(4096)]
        public ?string $buildCommand = null,
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::Structural), Max(64)]
        public ?string $railpackVersion = null,
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::Structural)]
        public array $watchPaths = [],
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::Structural)]
        public array $disabledDecisions = [],
        /** A verified generated Dockerfile stored in launchroom (`dfg_…`, docs/20 §5); used when the builder is `dockerfile`. */
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::Structural), Max(40)]
        public ?string $generatedDockerfile = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'disabledDecisions' => ['array', 'max:64'],
            'disabledDecisions.*' => ['string', 'distinct', 'regex:/^[a-z0-9][a-z0-9-]{0,62}$/D'],
            'generatedDockerfile' => ['nullable', 'string', 'regex:'.PublicId::regex('dfg')],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Data\Specs;

use App\Casts\ServiceSourceCast;
use App\Data\Attributes\ChangeAffects;
use App\Data\Attributes\Promotion;
use App\Data\Protocol\V1\PhpExtension;
use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Data\Services\ServiceSource;
use App\Enums\ChangeEffect;
use App\Enums\PromotionClass;
use App\Enums\ServiceKind;
use Spatie\LaravelData\Data;

/**
 * One service instance in an EnvironmentSpec. Keyed maps are keyed by public
 * id (hostname for domains) so the diff can match items across revisions.
 */
final class ServiceSpec extends Data
{
    /**
     * @param  array<string, mixed>  $source  ServiceSourceCast::toArray() shape
     * @param  array<string, VariableSpec>  $variables  service variables targeted at this environment
     * @param  array<string, VariableSpec>  $connectionVariables  owned by canvas connections
     * @param  list<string>  $sharedVariableIds  shared variables this service opted into
     * @param  array<string, DomainSpec>  $domains  keyed by hostname
     * @param  array<string, VolumeSpec>  $volumes  keyed by volume id
     * @param  list<PhpExtension>  $phpExtensions  database drivers its build adds (docs/04 §3.1): derived
     *                                             from the connections of the app and its processes, only for PHP apps; build-affecting
     */
    public function __construct(
        public string $id,
        public string $instanceId,
        public string $slug,
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::Structural)]
        public string $name,
        public ServiceKind $kind,
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::Structural)]
        public ?string $parentId,
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::Structural)]
        public array $source,
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::Structural)]
        public ?string $icon,
        public bool $enabled,
        #[ChangeAffects(ChangeEffect::Rebuild), Promotion(PromotionClass::EnvironmentSpecific)]
        public ?string $branchOverride,
        public BuildSettings $build,
        public DeploySettings $deploy,
        public ScalingSettings $scaling,
        public array $variables = [],
        public array $connectionVariables = [],
        public array $sharedVariableIds = [],
        public array $domains = [],
        public array $volumes = [],
        public array $phpExtensions = [],
    ) {}

    public function source(): ServiceSource
    {
        return ServiceSourceCast::fromArray($this->source);
    }
}

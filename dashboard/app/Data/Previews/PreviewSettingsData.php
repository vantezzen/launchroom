<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Data\Canvas\EnvironmentData;
use App\Enums\Previews\ForkPolicy;
use Spatie\LaravelData\Data;

/**
 * Project settings → Previews page props (docs/06 §5).
 */
final class PreviewSettingsData extends Data
{
    /**
     * @param  list<EnvironmentData>  $baseOptions
     * @param  list<PreviewServiceData>  $services
     */
    public function __construct(
        public bool $enabled,
        public ?string $baseEnvironmentId,
        /** The base actually used right now (configured, else staging, else production). */
        public ?string $effectiveBaseId,
        public ?string $targetBranches,
        public ?string $requiredLabel,
        public int $maxConcurrent,
        public int $expiryDays,
        public ForkPolicy $forkPolicy,
        public bool $protection,
        public bool $allowProductionClone,
        public bool $hasBypassSecret,
        public string $bypassHeader,
        public int $cloneLimitMb,
        public array $baseOptions,
        public array $services,
        public bool $canManage,
        /** Production as clone source needs an admin (owner/admin). */
        public bool $canAllowProductionClone,
    ) {}
}

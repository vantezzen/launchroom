<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Staged settings change of one service instance; omitted groups stay.
 */
final class UpdateServiceSettingsData extends Data
{
    public function __construct(
        public BuildSettings|Optional $build = new Optional,
        public DeploySettings|Optional $deploy = new Optional,
        public ScalingSettings|Optional $scaling = new Optional,
        public bool|Optional $enabled = new Optional,
        #[Max(255)]
        public string|null|Optional $branchOverride = new Optional,
    ) {}
}

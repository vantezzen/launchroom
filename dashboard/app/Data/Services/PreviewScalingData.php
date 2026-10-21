<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Data;

/**
 * Draft scaling settings for Settings → Scaling's live preview: the same
 * shape and rules as saving them (`scaling` of UpdateServiceSettingsData).
 */
final class PreviewScalingData extends Data
{
    public function __construct(
        public ScalingSettings $scaling,
    ) {}
}

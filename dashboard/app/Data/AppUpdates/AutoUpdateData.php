<?php

declare(strict_types=1);

namespace App\Data\AppUpdates;

use App\Enums\AppUpdates\AutoUpdateMode;
use Spatie\LaravelData\Data;

/**
 * Settings → Source → Update automatically (docs/04 §14.3): which updates
 * launchroom installs by itself, and when.
 */
final class AutoUpdateData extends Data
{
    public function __construct(
        public AutoUpdateMode $mode,
        public ?UpdateWindowData $window = null,
    ) {}
}

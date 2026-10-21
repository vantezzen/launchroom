<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Support\Servers\ServerLabels;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Placement settings of a server (docs/02 §2): its labels (`zone` and
 * `provider` are failure domains; services can require any label) and
 * whether it may host previews and production environments. Applies to new
 * placements; running replicas move with the next deploy. `measureLinks`
 * (null = unchanged) switches the private-network link measurements of this
 * server on or off (docs/05 §2.1).
 */
final class UpdateServerSettingsData extends Data
{
    /**
     * @param  array<string, string>  $labels  grammar checked by ServerLabels
     */
    public function __construct(
        #[Max(ServerLabels::MAX)]
        public array $labels,
        public bool $allowPreviews,
        public bool $allowProduction,
        public ?bool $measureLinks = null,
    ) {}
}

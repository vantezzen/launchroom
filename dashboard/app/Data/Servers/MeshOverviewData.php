<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Mesh\MeshMode;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The instance's mesh (docs/05 §2) for the servers pages. `error` and
 * `loginServer` are only filled for instance owners (canManage).
 */
final class MeshOverviewData extends Data
{
    public function __construct(
        public bool $enabled,
        public ?MeshMode $mode,
        public bool $builtInAvailable,
        public bool $canManage,
        public ?string $loginServer,
        public ?string $error,
        public ?CarbonInterface $errorAt,
        public ?CarbonInterface $enabledAt,
    ) {}
}

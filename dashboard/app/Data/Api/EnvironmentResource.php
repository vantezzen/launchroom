<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * An environment; `revision` is the last deployed revision number (null
 * before the first deploy).
 */
final class EnvironmentResource extends Data
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $name,
        public string $slug,
        public EnvironmentKind $kind,
        public ?int $revision,
        public bool $autoDeployPaused,
        public string $dashboardUrl,
    ) {}
}

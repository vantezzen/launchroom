<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * A project's status in the top bar's project switcher (ProjectStatuses).
 */
final class ProjectStatusData extends Data
{
    public function __construct(
        public string $projectId,
        public NodeStatusData $status,
    ) {}
}

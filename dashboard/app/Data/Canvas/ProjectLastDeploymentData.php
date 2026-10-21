<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\DeploymentStatus;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The newest deployment of a project, for the "last deploy" line on project
 * cards (direction.md §5.2): message, author, short sha and age.
 */
final class ProjectLastDeploymentData extends Data
{
    public function __construct(
        public string $id,
        public string $environmentId,
        public string $serviceName,
        public DeploymentStatus $status,
        public ?string $message,
        public ?string $author,
        /** First 7 characters of the commit sha. */
        public ?string $sha,
        public CarbonInterface $createdAt,
        /** The commit author's GitHub avatar; null → initials. */
        public ?string $authorAvatarUrl = null,
    ) {}
}

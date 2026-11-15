<?php

declare(strict_types=1);

namespace App\Services\Git\Feedback;

use App\Enums\Git\DeploymentReportState;

/**
 * A preview environment's aggregate state as every provider reports it
 * (docs/06 §5): in progress, failure, or success with the primary URL.
 */
final readonly class PreviewFeedback
{
    public function __construct(
        public DeploymentReportState $state,
        public ?string $url,
        public string $description,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\Previews\PreviewStatus;
use Spatie\LaravelData\Data;

/**
 * What the canvas of a preview environment shows about its pull request.
 */
final class PreviewCanvasData extends Data
{
    /**
     * @param  list<PreviewEdgeBadgeData>  $edgeBadges
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $title,
        public string $headRef,
        public string $baseName,
        public PreviewStatus $status,
        public string $statusLabel,
        /** building | live | failed | stopped (the environment's deployments). */
        public string $health,
        public ?string $statusReason,
        public bool $secretsWithheld,
        public ?string $expiresAt,
        public array $edgeBadges,
    ) {}
}

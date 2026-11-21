<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Data\Previews\PreviewUrlData;
use App\Enums\Previews\PreviewHealth;
use App\Enums\Previews\PreviewStatus;
use Spatie\LaravelData\Data;

/**
 * A pull request's preview (docs/06 §7). `health` sums up its current
 * deployments: `none` (no environment), `building`, `live` (every service
 * live), `failed` (one failed), `stopped` (nothing deployed). `deployments`
 * are the current deployment of each service; a preview has settled for
 * `headSha` when each of them is `finished` and built from it.
 */
final class PreviewResource extends Data
{
    /**
     * @param  list<PreviewUrlData>  $urls
     * @param  list<DeploymentResource>  $deployments
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $title,
        public string $author,
        public string $repository,
        public ?string $pullRequestUrl,
        public string $headRef,
        public string $baseRef,
        public string $headSha,
        public bool $isFork,
        public PreviewStatus $status,
        public ?string $statusReason,
        public PreviewHealth $health,
        public ?string $environmentId,
        public array $urls,
        public array $deployments,
        public ?string $expiresAt,
        public string $dashboardUrl,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\Previews\PreviewStatus;
use Spatie\LaravelData\Data;

/**
 * A pull request's preview on the previews list (docs/08).
 */
final class PreviewData extends Data
{
    /**
     * @param  list<PreviewUrlData>  $urls
     * @param  list<PreviewDatabaseData>  $databases
     * @param  list<ShareLinkData>  $shareLinks
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
        /** The full head commit: posted back when approving, so the approval covers exactly the commit shown (docs/09 R20). */
        public string $headCommit,
        public bool $isFork,
        public PreviewStatus $status,
        public string $statusLabel,
        public ?string $statusReason,
        public ?string $environmentId,
        /** Aggregate of the environment's deployments: building | live | failed | stopped | none. */
        public string $health,
        public array $urls,
        public array $databases,
        public bool $secretsWithheld,
        public ?string $approvedBy,
        public ?string $expiresAt,
        public string $lastActivityAt,
        public array $shareLinks,
        public bool $protected,
    ) {}
}

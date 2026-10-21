<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * `POST /environments/{environment}/deployments` (docs/16 §2.2): a commit of
 * a git service (`sha`, else the head of branch `ref`, else the head of the
 * tracked branch), or `redeploy` of the current commit.
 */
final class CreateDeploymentInput extends Data
{
    public function __construct(
        #[Regex('/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public string $service,
        /** Branch name (`main`) or `refs/heads/main`. */
        #[Max(255), Regex('#^(refs/heads/)?[A-Za-z0-9._/+-]+$#D')]
        public ?string $ref = null,
        #[Regex('/^[0-9a-f]{40}$/D')]
        public ?string $sha = null,
        public bool $redeploy = false,
    ) {}
}

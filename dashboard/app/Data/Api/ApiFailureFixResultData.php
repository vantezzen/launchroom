<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\FailureFixOutcome;
use Spatie\LaravelData\Data;

/**
 * What applying a fix did: `deploying` (staged and deployed, `deployment`
 * is the new one), `staged` (other changes were staged too: review and
 * deploy them together), `freeing_disk` (deploy again once the server has
 * freed space).
 */
final class ApiFailureFixResultData extends Data
{
    public function __construct(
        public FailureFixOutcome $outcome,
        public string $message,
        public ?DeploymentResource $deployment,
    ) {}
}

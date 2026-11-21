<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\DeploymentFailureClass;
use Spatie\LaravelData\Data;

/**
 * Why a deployment failed, in short: the failure class, its title and
 * general hint (DeploymentFailureData) and the raw message. The full
 * explanation (cause, the failing step's last lines, fixes) is
 * `GET /deployments/{deployment}/failure`.
 */
final class ApiFailureData extends Data
{
    public function __construct(
        public ?DeploymentFailureClass $class,
        public ?string $message,
        /** "Build failed", "Health check timed out", … */
        public ?string $title = null,
        /** What to do next, for any failure of this class. */
        public ?string $hint = null,
    ) {}
}

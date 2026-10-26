<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The service of a live deployment is stopped (D68): the deployment still
 * holds its version, but nothing of it runs. `by` is the member's name
 * (null: their account is gone).
 */
final class ServiceStoppedData extends Data
{
    public function __construct(
        public CarbonInterface $at,
        public ?string $by,
    ) {}
}

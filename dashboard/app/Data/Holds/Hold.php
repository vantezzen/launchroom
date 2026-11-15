<?php

declare(strict_types=1);

namespace App\Data\Holds;

use App\Enums\HoldKind;

/**
 * One hold on a set of service instances (App\Services\Holds\Holds): why,
 * and what it waits for ("postgres is being imported", "the import").
 */
final readonly class Hold
{
    /**
     * @param  list<int>  $instanceIds
     */
    public function __construct(
        public HoldKind $kind,
        public array $instanceIds,
        public string $reason,
        public string $ends,
    ) {}

    /**
     * The message of a deployment cancelled for this hold.
     */
    public function cancelMessage(): string
    {
        return "The deployment was cancelled because {$this->reason}. Deploy again once {$this->ends} has finished.";
    }
}

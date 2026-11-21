<?php

declare(strict_types=1);

namespace App\Data\Broadcasts;

use Spatie\LaravelData\Data;

/** `service.status`: these services' statuses may have changed; viewers refetch the environment's live status (D108). */
final class ServiceStatusChangedData extends Data
{
    /**
     * @param  list<string>  $serviceIds
     */
    public function __construct(
        public array $serviceIds,
    ) {}
}

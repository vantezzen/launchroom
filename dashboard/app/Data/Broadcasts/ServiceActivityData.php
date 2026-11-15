<?php

declare(strict_types=1);

namespace App\Data\Broadcasts;

use App\Broadcasting\ServiceActivityTopic;
use Spatie\LaravelData\Data;

/** `service.activity`: something of `topic` on one service changed state; the tab showing it refetches. */
final class ServiceActivityData extends Data
{
    public function __construct(
        public string $serviceId,
        public ServiceActivityTopic $topic,
    ) {}
}

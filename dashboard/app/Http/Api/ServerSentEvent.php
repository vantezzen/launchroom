<?php

declare(strict_types=1);

namespace App\Http\Api;

use Spatie\LaravelData\Data;

/**
 * One event of an EventStream; its payload's shape is documented per
 * operation in the OpenAPI document (`x-launchroom-events`).
 */
final readonly class ServerSentEvent
{
    public function __construct(
        public string $event,
        public Data $data,
        public ?int $id = null,
    ) {}
}

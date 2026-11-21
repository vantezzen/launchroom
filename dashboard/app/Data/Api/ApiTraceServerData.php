<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\ServerStatus;
use Spatie\LaravelData\Data;

/** A server on a traced request's path. */
final class ApiTraceServerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ServerStatus $status,
    ) {}
}

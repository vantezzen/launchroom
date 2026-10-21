<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Servers\DeviceData;
use App\Enums\ServerStatus;
use Spatie\LaravelData\Data;

/**
 * A server with the edge role: every edge routes every domain of its team
 * (docs/05 §4), so Settings → Domains shows them as "served by".
 */
final class EdgeServerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ServerStatus $status,
        public ?DeviceData $device,
    ) {}
}

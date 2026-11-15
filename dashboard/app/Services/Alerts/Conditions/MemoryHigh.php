<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Enums\Alerts\AlertRule;
use App\Models\Server;
use App\Support\Metrics\Cast;

final readonly class MemoryHigh extends ServerLoad
{
    public function rule(): AlertRule
    {
        return AlertRule::MemoryHigh;
    }

    public function resolvedReason(): string
    {
        return 'Memory use is back to normal.';
    }

    protected function value(object $sample): ?float
    {
        $used = Cast::float($sample->mem_used_mb ?? null);
        $total = Cast::float($sample->mem_total_mb ?? null);

        return $used === null || $total === null || $total <= 0 ? null : 100 * $used / $total;
    }

    protected function measure(): string
    {
        return 'Memory';
    }

    protected function title(Server $server): string
    {
        return "{$server->name} is low on memory";
    }
}

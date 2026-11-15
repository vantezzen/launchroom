<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Enums\Alerts\AlertRule;
use App\Models\Server;
use App\Support\Metrics\Cast;

final readonly class CpuHigh extends ServerLoad
{
    public function rule(): AlertRule
    {
        return AlertRule::CpuHigh;
    }

    public function resolvedReason(): string
    {
        return 'CPU is back to normal.';
    }

    protected function value(object $sample): ?float
    {
        return Cast::float($sample->cpu_pct ?? null);
    }

    protected function measure(): string
    {
        return 'CPU';
    }

    protected function title(Server $server): string
    {
        return "{$server->name} is busy";
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

/**
 * The condition rules, raised and resolved by the per-minute evaluator. The
 * other rules are raised by the domain that sees the event (deploys, servers,
 * backups, security findings).
 */
final readonly class AlertConditions
{
    /** @var list<AlertCondition> */
    public array $all;

    public function __construct(
        ServiceUnhealthy $serviceUnhealthy,
        ErrorRateHigh $errorRateHigh,
        CpuHigh $cpuHigh,
        MemoryHigh $memoryHigh,
        DiskFull $diskFull,
        CertificateExpiring $certificateExpiring,
        WafUnavailable $wafUnavailable,
    ) {
        $this->all = [$serviceUnhealthy, $errorRateHigh, $cpuHigh, $memoryHigh, $diskFull, $certificateExpiring, $wafUnavailable];
    }
}

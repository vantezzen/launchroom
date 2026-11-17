<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A starting container launchroomd found clearly misconfigured (report.json WorkloadIssue.kind, D68).
 * Port issues share their code with the port hint they are.
 */
enum WorkloadIssueKind: string
{
    case CrashLoop = 'crash_loop';
    case LoopbackOnly = 'loopback_only';
    case WrongPort = 'wrong_port';
    /** launchroomd restarted a serving replica 3 times in an hour for failing its health check and leaves it alone now (HealthSpec.restartAfter). */
    case Unresponsive = 'unresponsive';

    public function portHint(): ?PortHint
    {
        return PortHint::tryFrom($this->value);
    }
}

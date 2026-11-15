<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a workload's listening ports miss its configured port (report.json WorkloadStatus.portHint, Go `state.PortHint`).
 */
enum PortHint: string
{
    case LoopbackOnly = 'loopback_only';
    case NoPort = 'no_port';
    case WrongPort = 'wrong_port';

    /**
     * @param  list<int>  $listening  the app's TCP ports
     */
    public function fact(?int $port, array $listening): string
    {
        return match ($this) {
            self::LoopbackOnly => 'The app listens on 127.0.0.1'.($port === null ? '' : ":{$port}").' only, so nothing outside its container reaches it.',
            self::NoPort => 'The app does not listen on any TCP port'.($port === null ? '' : " (expected {$port})").'.',
            self::WrongPort => 'The app listens on '.($listening === [] ? 'other ports' : implode(', ', $listening)).($port === null ? '' : ", not {$port}").'.',
        };
    }
}

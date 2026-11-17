<?php

declare(strict_types=1);

namespace App\Listeners\Alerts;

use App\Enums\ServerStatus;
use App\Events\NodeStateInvalidated;
use App\Events\ServerStatusChanged;
use App\Services\Alerts\FallbackWatchdog;

/**
 * The watchdog's peer is an online server (FallbackWatchdog): when a server
 * comes online or drops out, the peer may change, so the old and the new
 * one render again (unchanged renders are not published).
 */
final readonly class RenderWatchdogPeer
{
    public function __construct(
        private FallbackWatchdog $watchdog,
    ) {}

    public function handle(ServerStatusChanged $event): void
    {
        if (($event->from === ServerStatus::Online) === ($event->to === ServerStatus::Online)) {
            return;
        }

        NodeStateInvalidated::servers([$event->serverId, ...$this->watchdog->servers()]);
    }
}

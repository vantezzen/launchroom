<?php

declare(strict_types=1);

namespace App\Broadcasting;

/**
 * Names of the browser-facing broadcasts (`broadcastAs`). Echo listens to
 * them with a leading dot (lib/realtime.ts). Payloads are Data classes in
 * App\Data\Broadcasts, nudges only: clients refetch through authorized
 * endpoints (docs/08 §4).
 */
enum BroadcastEvent: string
{
    case CanvasChanged = 'canvas.changed';
    case DeploymentUpdated = 'deployment.updated';
    case LogsAppended = 'logs.appended';
    case ServerMetrics = 'server.metrics';
    /** A session, remote access, backup, restore or import of one service changed state. */
    case ServiceActivity = 'service.activity';
    /** A team's security findings were re-evaluated. */
    case SecurityChanged = 'security.changed';
}

<?php

declare(strict_types=1);

namespace App\Enums\Traces;

/**
 * Where a traced request ended on its path (docs/23 §7): the edge answered
 * itself, the firewall gate on the edge refused it, the way to the app
 * server failed, or the service answered.
 */
enum TraceStop: string
{
    case Edge = 'edge';
    case Firewall = 'firewall';
    case AppServer = 'app_server';
    case Service = 'service';
}

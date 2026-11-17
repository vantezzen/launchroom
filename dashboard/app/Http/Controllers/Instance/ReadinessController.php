<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instance;

use App\Http\Controllers\Controller;
use App\Queries\ControlPlaneHealth;
use Illuminate\Http\JsonResponse;

/**
 * `/up/ready` (G34): 200 when the database and the cache answer within a
 * second, else 503. Constant bodies, no error text. `/up` only says PHP
 * answers; launchroomd's watchdog and `./launchroom doctor` ask this one
 * (a dashboard that answers 503 because Postgres is down is not restarted).
 * Public and sessionless like /up.
 */
final class ReadinessController extends Controller
{
    public function __invoke(ControlPlaneHealth $health): JsonResponse
    {
        $ready = $health->ready();
        $ok = $ready['database'] && $ready['cache'];

        return response()->json(['status' => $ok ? 'ok' : 'unavailable', ...$ready], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }
}

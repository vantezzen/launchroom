<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instance;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The asset version the dashboard serves right now (docs/08 §4): the
 * browser polls it while launchroom restarts and reloads fully once a new
 * build answers. Public and sessionless like /up: the same value is in every
 * Inertia page, including the sign-in page.
 */
final class ClientVersionController extends Controller
{
    public function __invoke(Request $request, HandleInertiaRequests $inertia): JsonResponse
    {
        return response()->json(['version' => $inertia->version($request)])
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }
}

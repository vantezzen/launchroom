<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instance;

use App\Http\Controllers\Controller;
use App\Services\Http\DashboardProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Answers the HTTPS test request of Settings → Dashboard domain (docs/05
 * §4.1): `proof` = HMAC of the caller's nonce under a key derived from
 * APP_KEY, so the dashboard can tell itself apart from anything else
 * answering at the new domain. Public (no session): it reveals nothing but
 * MACs under a key used for nothing else.
 */
final class DashboardProbeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $nonce = $request->query('nonce');

        abort_unless(is_string($nonce) && preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $nonce) === 1, 404);

        return response()->json(['proof' => DashboardProbe::proof($nonce)])
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Actions\Install\RenderServerInstallScript;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * GET /install/server.sh: the daemon installer for new servers (docs/11 §3).
 * Public by necessity (`curl … | sh` on a fresh server); it carries only
 * public values (dashboard URL, public signing key, daemon image), never a
 * join token. No session, rate limited.
 */
final class ServerInstallScriptController extends Controller
{
    private const array HEADERS = [
        'Content-Type' => 'text/x-shellscript; charset=utf-8',
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __invoke(RenderServerInstallScript $renderServerInstallScript): Response
    {
        $script = $renderServerInstallScript->handle();

        if ($script === null) {
            return new Response(
                "echo 'launchroom: this dashboard cannot serve the server installer yet (see its logs).' >&2\nexit 1\n",
                503,
                self::HEADERS,
            );
        }

        return new Response($script, 200, self::HEADERS);
    }
}

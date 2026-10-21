<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Foundation\Application;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Local-only showcase of the visual primitives (charts, steps, logs, device
 * art, map, cards) with built-in sample data, for review and screenshots
 * (docs/design-system.md). Reads nothing from the
 * database; 404 outside the local environment.
 */
final class DesignVisualsController extends Controller
{
    public function __invoke(Application $app): Response
    {
        abort_unless($app->environment('local'), 404);

        return Inertia::render('dev/visuals');
    }
}

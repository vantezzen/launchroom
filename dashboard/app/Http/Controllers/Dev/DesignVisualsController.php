<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Showcase of the visual primitives (charts, steps, logs, device art, map,
 * cards) with built-in sample data, for review and screenshots
 * (docs/design-system.md). Reads nothing from the database.
 */
final class DesignVisualsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('dev/visuals');
    }
}

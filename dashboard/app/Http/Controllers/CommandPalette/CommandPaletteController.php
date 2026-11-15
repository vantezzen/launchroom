<?php

declare(strict_types=1);

namespace App\Http\Controllers\CommandPalette;

use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Queries\CommandPalette;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The ⌘K index of the current team (docs/08 §8).
 */
final class CommandPaletteController extends Controller
{
    public function __invoke(Request $request, #[CurrentUser] User $user, #[CurrentTeam] Team $team, CommandPalette $palette): JsonResponse
    {
        Gate::authorize('viewAny', [Project::class, $team]);

        $environment = $request->query('environment');

        return response()->json($palette->for($team, $user, is_string($environment) ? $environment : null))->header('Cache-Control', 'no-store');
    }
}

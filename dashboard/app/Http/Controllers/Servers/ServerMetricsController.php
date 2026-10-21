<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Data\Metrics\FleetMetricsData;
use App\Data\Metrics\MetricsQueryData;
use App\Data\Metrics\ServerMetricsData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Server;
use App\Models\User;
use App\Queries\ServerMetrics;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Usage metrics JSON for server cards and the server page
 * (docs/design/direction.md §8.2). Realtime: `server.metrics` on the
 * `servers.{team}` channel says when to refetch.
 */
final class ServerMetricsController extends Controller
{
    /**
     * GET /servers/-/metrics?range=: every server of the current team (revoked ones excluded).
     */
    public function index(Request $request, #[CurrentUser] User $user, ServerMetrics $metrics): FleetMetricsData
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [Server::class, $team]);
        $query = MetricsQueryData::validateAndCreate($request->query());

        $servers = $team->servers()
            ->where('status', '!=', 'revoked')
            ->orderByDesc('is_control_plane')
            ->orderBy('name')
            ->get()
            ->all();

        return $metrics->fleet(array_values($servers), $query->range);
    }

    /**
     * GET /servers/{server}/-/metrics?range=.
     */
    public function show(Request $request, Server $server, ServerMetrics $metrics): ServerMetricsData
    {
        Gate::authorize('view', $server);
        $query = MetricsQueryData::validateAndCreate($request->query());

        return $metrics->server($server, $query->range);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ServerResource;
use App\Enums\ServerStatus;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Queries\Api\ApiResources;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ServerController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'listServers', summary: 'Servers of the token\'s team', tag: 'Servers', response: ServerResource::class, list: true)]
    public function index(#[CurrentUser] User $user, ApiResources $resources): JsonResponse
    {
        $team = $this->team($user);
        Gate::authorize('viewAny', [Server::class, $team]);

        $servers = Server::query()
            ->where('team_id', $team->id)
            ->where('status', '!=', ServerStatus::Revoked->value)
            ->orderByDesc('is_control_plane')
            ->orderBy('name')
            ->get();

        return ApiResponse::list($servers->map($resources->server(...)));
    }

    #[ApiOperation(id: 'getServer', summary: 'One server', tag: 'Servers', response: ServerResource::class)]
    public function show(Server $server, ApiResources $resources): JsonResponse
    {
        Gate::authorize('view', $server);

        return ApiResponse::item($resources->server($server));
    }
}

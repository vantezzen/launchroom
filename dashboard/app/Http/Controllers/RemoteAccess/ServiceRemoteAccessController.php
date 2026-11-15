<?php

declare(strict_types=1);

namespace App\Http\Controllers\RemoteAccess;

use App\Actions\RemoteAccess\OpenRemoteAccess;
use App\Data\RemoteAccess\OpenRemoteAccessData;
use App\Data\RemoteAccess\RemoteAccessPanelData;
use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Environment;
use App\Models\Project;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\Service;
use App\Models\User;
use App\Queries\RemoteAccess\RemoteAccessPanel;
use App\Queries\RemoteAccess\RemoteAccessView;
use App\Support\PublicId;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Connect via SSH" (Console tab), "Connect with SFTP" (a volume) and
 * "Temporary access" (Data tab) of a service (D61, D67): the panel's data
 * and starting an access. `{kind}` is `ssh`, `volume` (`?volume=vol_…`) or
 * `database`.
 */
final class ServiceRemoteAccessController extends Controller
{
    public function show(Request $request, Project $project, Environment $environment, Service $service, string $kind, #[CurrentUser] User $user, RemoteAccessPanel $panel): JsonResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        $accessKind = RemoteAccessKind::from($kind);
        Gate::authorize('view', $instance);
        Gate::authorize('viewAny', [RemoteAccess::class, $instance, $accessKind]);

        $volume = $request->query('volume');
        $volume = is_string($volume) && PublicId::isValid($volume, 'vol') ? $volume : null;
        $data = $panel->panel($instance, $accessKind, $user, $request->ip(), $volume);
        abort_if(! $data instanceof RemoteAccessPanelData, 404);

        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, Project $project, Environment $environment, Service $service, string $kind, OpenRemoteAccessData $data, #[CurrentUser] User $user, OpenRemoteAccess $open): JsonResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        $accessKind = RemoteAccessKind::from($kind);
        Gate::authorize('view', $instance);
        Gate::authorize('open', [RemoteAccess::class, $instance, $accessKind]);

        $access = $open->handle($user, $instance, $accessKind, $data, $this->auditActor($request, $user));

        return response()
            ->json(RemoteAccessView::data($access, $user), 201)
            ->header('Cache-Control', 'no-store, private');
    }
}

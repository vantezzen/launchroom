<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sessions;

use App\Actions\Sessions\OpenSession;
use App\Data\Sessions\OpenSessionData;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Queries\Sessions\SessionTabs;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The Console and Data tabs of a service (docs/08 §15): their data (JSON)
 * and opening a session (docs/03 §11.1). `{kind}` is `console` or `data`.
 */
final class ServiceSessionController extends Controller
{
    public function show(Request $request, Project $project, Environment $environment, Service $service, string $kind, #[CurrentUser] User $user, SessionTabs $tabs): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        $sessionKind = InteractiveSessionKind::from($kind);
        Gate::authorize('view', $instance);
        Gate::authorize('viewAny', [InteractiveSession::class, $instance, $sessionKind]);

        $data = $sessionKind === InteractiveSessionKind::Console
            ? $tabs->console($instance, $user)
            : $tabs->data($instance, $user);

        abort_if($data === null, 404);

        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, Project $project, Environment $environment, Service $service, string $kind, OpenSessionData $data, #[CurrentUser] User $user, OpenSession $open): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        $sessionKind = InteractiveSessionKind::from($kind);
        Gate::authorize('view', $instance);
        Gate::authorize('open', [InteractiveSession::class, $instance, $sessionKind]);

        return response()
            ->json($open->handle($user, $instance, $sessionKind, $data, $this->auditActor($request, $user)), 201)
            ->header('Cache-Control', 'no-store, private');
    }
}

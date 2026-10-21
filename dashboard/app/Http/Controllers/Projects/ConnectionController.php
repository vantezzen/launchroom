<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Connections\CreateConnection;
use App\Actions\Connections\DeleteConnection;
use App\Data\Canvas\CreateConnectionData;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Services\Canvas\ConnectionPreview;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Canvas edges: the Connect popover's preview, creating and deleting
 * connections (staged, docs/06 §3.2).
 */
final class ConnectionController extends Controller
{
    public function preview(Request $request, Project $project, Environment $environment, ConnectionPreview $preview): JsonResponse
    {
        Gate::authorize('create', [Connection::class, $project]);
        ProjectScope::environment($project, $environment);

        $data = CreateConnectionData::validateAndCreate([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            ...array_filter(['mode' => $request->query('mode'), 'style' => $request->query('style')], is_string(...)),
        ]);

        [$from, $to] = $this->services($project, $data);

        // Without an explicit choice the preview picks the defaults (framework-aware style).
        return new JsonResponse($preview->preview(
            $from,
            $to,
            $request->query->has('mode') ? $data->mode : null,
            $request->query->has('style') ? $data->style : null,
        ));
    }

    public function store(Request $request, Project $project, Environment $environment, CreateConnectionData $data, #[CurrentUser] User $user, CreateConnection $createConnection): RedirectResponse
    {
        Gate::authorize('create', [Connection::class, $project]);
        ProjectScope::environment($project, $environment);

        [$from, $to] = $this->services($project, $data);
        $createConnection->handle($from, $to, $data->mode, $data->style, $user, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Connected {$from->name} to {$to->name}. Deploy to inject the variables."]);

        return back();
    }

    public function destroy(Request $request, Project $project, Environment $environment, Connection $connection, #[CurrentUser] User $user, DeleteConnection $deleteConnection): RedirectResponse
    {
        ProjectScope::environment($project, $environment);
        ProjectScope::connection($project, $connection);
        Gate::authorize('delete', $connection);

        $deleteConnection->handle($connection, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Connection removed with its variables. Deploy to apply it.']);

        return back();
    }

    /**
     * Both ends, through the project (never a bare lookup by id).
     *
     * @return array{Service, Service}
     */
    private function services(Project $project, CreateConnectionData $data): array
    {
        $from = $project->services()->where('public_id', $data->from)->with('parent')->first();
        $to = $project->services()->where('public_id', $data->to)->first();

        abort_unless($from instanceof Service && $to instanceof Service, 404);

        return [$from, $to];
    }
}

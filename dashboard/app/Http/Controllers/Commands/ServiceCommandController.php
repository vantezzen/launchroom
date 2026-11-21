<?php

declare(strict_types=1);

namespace App\Http\Controllers\Commands;

use App\Actions\Commands\CancelCommandRun;
use App\Actions\Commands\StartCommandRun;
use App\Data\Api\LogCursorQuery;
use App\Data\Commands\ListCommandRunsQuery;
use App\Data\Commands\StartCommandRunInput;
use App\Http\Api\Cursor;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Commands\CommandRun;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Queries\Commands\CommandRunOutput;
use App\Queries\Commands\CommandRuns;
use App\Queries\Commands\CommandsTab;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The Commands tab of a service (D105, docs/08 §22): its data, running a
 * command, one run, its output by cursor and cancelling it. JSON for the
 * panel's client; the API has the same through Api\V1\CommandController.
 */
final class ServiceCommandController extends Controller
{
    public function show(Project $project, Environment $environment, Service $service, ListCommandRunsQuery $query, CommandsTab $tab): JsonResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        Gate::authorize('view', $instance);
        Gate::authorize('viewAny', [CommandRun::class, $instance]);

        return $this->json($tab->for($instance, Cursor::decode($query->cursor)));
    }

    public function store(Request $request, Project $project, Environment $environment, Service $service, StartCommandRunInput $input, #[CurrentUser] User $user, StartCommandRun $start): JsonResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        Gate::authorize('view', $instance);
        Gate::authorize('create', [CommandRun::class, $instance]);

        return $this->json(CommandRuns::data($start->handle($instance, $input, $user, $this->auditActor($request, $user))), 201);
    }

    public function run(CommandRun $commandRun): JsonResponse
    {
        Gate::authorize('view', $commandRun);

        return $this->json(CommandRuns::data($commandRun));
    }

    public function output(CommandRun $commandRun, LogCursorQuery $query, CommandRunOutput $output): JsonResponse
    {
        Gate::authorize('view', $commandRun);

        return $this->json($output->page($commandRun, $query->after));
    }

    public function cancel(Request $request, CommandRun $commandRun, #[CurrentUser] User $user, CancelCommandRun $cancel): JsonResponse
    {
        Gate::authorize('cancel', $commandRun);

        return $this->json(CommandRuns::data($cancel->handle($commandRun, $user, $this->auditActor($request, $user))));
    }

    private function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}

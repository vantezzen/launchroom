<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Commands\CancelCommandRun;
use App\Actions\Commands\StartCommandRun;
use App\Data\Api\Events\CommandEndEvent;
use App\Data\Api\Events\CommandLinesEvent;
use App\Data\Api\Events\CommandStatusEvent;
use App\Data\Api\LogCursorQuery;
use App\Data\Commands\CommandCatalogueData;
use App\Data\Commands\CommandOutputLineData;
use App\Data\Commands\CommandRunData;
use App\Data\Commands\ListCommandRunsQuery;
use App\Data\Commands\StartCommandRunInput;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Api\Cursor;
use App\Http\Api\EventStream;
use App\Http\Api\ServerSentEvent;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Commands\CommandRun;
use App\Models\Environment;
use App\Models\Service;
use App\Models\User;
use App\Queries\Commands\CommandCatalogue;
use App\Queries\Commands\CommandRunOutput;
use App\Queries\Commands\CommandRuns;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One-off commands through the API (D105, docs/16 §2.5): the opt-in `run`
 * ability on top of the exec permission, the same Actions and policy as
 * the Commands tab.
 */
final class CommandController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'listCommands', summary: 'The commands a service lists', tag: 'Commands', response: CommandCatalogueData::class, description: 'The framework\'s standard commands from the live build, then the ones launchroom.json names (`deploy.commands`). Run one with `POST …/command-runs {"name": "migrate"}`.')]
    public function catalogue(Environment $environment, Service $service, CommandCatalogue $catalogue): JsonResponse
    {
        $instance = RouteScope::instanceIn($environment, $service);
        Gate::authorize('viewAny', [CommandRun::class, $instance]);

        return ApiResponse::item($catalogue->for($instance));
    }

    #[ApiOperation(id: 'listCommandRuns', summary: 'Command runs of a service, newest first', tag: 'Commands', response: CommandRunData::class, list: true, query: ListCommandRunsQuery::class, paginated: true)]
    public function index(Environment $environment, Service $service, ListCommandRunsQuery $query, CommandRuns $runs): JsonResponse
    {
        $instance = RouteScope::instanceIn($environment, $service);
        Gate::authorize('viewAny', [CommandRun::class, $instance]);

        [$page, $next] = $runs->page($instance, Cursor::decode($query->cursor), $query->limit);

        return ApiResponse::list($page, $next === null ? null : Cursor::encode($next));
    }

    #[ApiOperation(id: 'createCommandRun', summary: 'Run a one-off command in the live version of a service', tag: 'Commands', response: CommandRunData::class, request: StartCommandRunInput::class, status: 201, description: 'A new container from the live deployment\'s image, with the service\'s variables, network and volumes. `argv` runs without a shell; `name` runs a listed command. Follow it with GET /command-runs/{run}/output/stream.')]
    public function store(Request $request, Environment $environment, Service $service, StartCommandRunInput $input, #[CurrentUser] User $user, StartCommandRun $start): JsonResponse
    {
        $instance = RouteScope::editable(RouteScope::instanceIn($environment, $service));
        Gate::authorize('create', [CommandRun::class, $instance]);

        return ApiResponse::item(CommandRuns::data($start->handle($instance, $input, $user, $this->auditActor($request, $user))), 201);
    }

    #[ApiOperation(id: 'getCommandRun', summary: 'A command run and its outcome', tag: 'Commands', response: CommandRunData::class)]
    public function show(CommandRun $commandRun): JsonResponse
    {
        Gate::authorize('view', $commandRun);

        return ApiResponse::item(CommandRuns::data($commandRun));
    }

    #[ApiOperation(id: 'listCommandRunOutput', summary: 'Output lines of a command run after a cursor', tag: 'Commands', response: CommandOutputLineData::class, list: true, query: LogCursorQuery::class, description: '`meta.cursor` is the cursor for the next page, `meta.hasMore` whether more lines are stored, `meta.finished` whether the run ended.')]
    public function output(CommandRun $commandRun, LogCursorQuery $query, CommandRunOutput $output): JsonResponse
    {
        Gate::authorize('view', $commandRun);

        $page = $output->page($commandRun, $query->after);

        return ApiResponse::list($page->lines, null, [
            'cursor' => $page->cursor,
            'hasMore' => $page->hasMore,
            'finished' => $commandRun->status->isTerminal(),
        ]);
    }

    #[ApiOperation(id: 'streamCommandRunOutput', summary: 'Follow a command run\'s output (server-sent events)', tag: 'Commands', query: LogCursorQuery::class, description: 'Events: `lines` (the event id is the cursor), `status`, `end` (with the exit code), `reconnect`. Resume with `Last-Event-ID`.', events: ['lines' => CommandLinesEvent::class, 'status' => CommandStatusEvent::class, 'end' => CommandEndEvent::class])]
    public function stream(Request $request, CommandRun $commandRun, LogCursorQuery $query, CommandRunOutput $output): StreamedResponse
    {
        Gate::authorize('view', $commandRun);

        $status = null;

        return EventStream::response(
            EventStream::resumeCursor($request->headers->get('Last-Event-ID'), $query->after),
            static function (int $cursor) use ($commandRun, $output, &$status): array {
                $commandRun->refresh();
                // Read after the refresh: a run that just ended has shipped all its lines.
                $finished = $commandRun->status->isTerminal();
                $events = [];

                do {
                    $page = $output->page($commandRun, $cursor);

                    if ($page->lines !== []) {
                        $events[] = new ServerSentEvent('lines', new CommandLinesEvent($page->lines, $page->cursor), $page->cursor);
                    }

                    $cursor = $page->cursor;
                } while ($page->hasMore);

                if ($commandRun->status !== $status) {
                    $status = $commandRun->status;
                    $events[] = new ServerSentEvent('status', new CommandStatusEvent($status, $finished));
                }

                if ($finished) {
                    $events[] = new ServerSentEvent('end', new CommandEndEvent($commandRun->status, $commandRun->exit_code, $commandRun->error));
                }

                return ['events' => $events, 'cursor' => $cursor, 'done' => $finished];
            },
        );
    }

    #[ApiOperation(id: 'cancelCommandRun', summary: 'Cancel a queued or running command', tag: 'Commands', response: CommandRunData::class, description: 'The container gets SIGTERM and 10 seconds. The run reads `cancelled` once the server confirms; an ended run is returned as it is.')]
    public function cancel(Request $request, CommandRun $commandRun, #[CurrentUser] User $user, CancelCommandRun $cancel): JsonResponse
    {
        Gate::authorize('cancel', $commandRun);

        return ApiResponse::item(CommandRuns::data($cancel->handle($commandRun, $user, $this->auditActor($request, $user))));
    }
}

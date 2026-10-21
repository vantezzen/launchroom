<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Logs\StartLogTail;
use App\Data\Api\LogCursorQuery;
use App\Data\Deployments\LogLineData;
use App\Data\Logs\LogEntryData;
use App\Data\Logs\LogQueryData;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Api\EventStream;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\Api\ApiResources;
use App\Queries\DeploymentLogs;
use App\Queries\EnvironmentLogs;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logs through the API (docs/16 §2.2–2.3): the deployment timeline's lines
 * and the log explorer's query, paginated by cursor or followed as
 * server-sent events. Lines are stored masked (docs/09 R16).
 */
final class LogController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'listDeploymentLogs', summary: 'Log lines of a deployment after a cursor', tag: 'Logs', response: LogLineData::class, list: true, query: LogCursorQuery::class, description: '`meta.cursor` is the cursor for the next page, `meta.hasMore` whether more lines are stored, `meta.finished` whether the deployment settled.')]
    public function deployment(Deployment $deployment, LogCursorQuery $query, DeploymentLogs $logs, ApiResources $resources): JsonResponse
    {
        Gate::authorize('view', $deployment);

        $page = $logs->page($deployment, $query->after);

        return ApiResponse::list($page->lines, null, [
            'cursor' => $page->cursor,
            'hasMore' => $page->hasMore,
            'finished' => $resources->finished($deployment->status),
        ]);
    }

    #[ApiOperation(id: 'streamDeploymentLogs', summary: 'Follow a deployment\'s logs (server-sent events)', tag: 'Logs', query: LogCursorQuery::class, description: 'Events: `lines` ({lines, cursor}; the event id is the cursor), `status` ({status, finished}), `end`, `reconnect`. Resume with `Last-Event-ID`.', stream: true)]
    public function deploymentStream(Request $request, Deployment $deployment, LogCursorQuery $query, DeploymentLogs $logs, ApiResources $resources): StreamedResponse
    {
        Gate::authorize('view', $deployment);

        $status = null;

        return EventStream::response(
            EventStream::resumeCursor($request->headers->get('Last-Event-ID'), $query->after),
            static function (int $cursor) use ($deployment, $logs, $resources, &$status): array {
                $deployment->refresh();
                $events = [];

                do {
                    $page = $logs->page($deployment, $cursor);

                    if ($page->lines !== []) {
                        $events[] = ['event' => 'lines', 'data' => ['lines' => array_map(static fn (LogLineData $line): array => $line->toArray(), $page->lines), 'cursor' => $page->cursor], 'id' => $page->cursor];
                    }

                    $cursor = $page->cursor;
                } while ($page->hasMore);

                $finished = $resources->finished($deployment->status);

                if ($deployment->status->value !== $status) {
                    $status = $deployment->status->value;
                    $events[] = ['event' => 'status', 'data' => ['status' => $status, 'finished' => $finished]];
                }

                if ($finished) {
                    $events[] = ['event' => 'end', 'data' => ['status' => $status]];
                }

                return ['events' => $events, 'cursor' => $cursor, 'done' => $finished];
            },
        );
    }

    #[ApiOperation(id: 'listEnvironmentLogs', summary: 'Build and runtime log lines of an environment', tag: 'Logs', response: LogEntryData::class, list: true, query: LogQueryData::class, description: 'The log explorer\'s query: the newest lines of the range, oldest first. `meta.cursor` with `after` returns only newer lines.')]
    public function environment(Environment $environment, LogQueryData $query, #[CurrentUser] User $user, EnvironmentLogs $logs): JsonResponse
    {
        Gate::authorize('view', $environment);
        $this->serviceOf($environment, $query);

        $page = $logs->page($environment, $query, $user);

        return ApiResponse::list($page->entries, null, ['cursor' => $page->cursor, 'truncated' => $page->truncated]);
    }

    #[ApiOperation(id: 'streamEnvironmentLogs', summary: 'Follow an environment\'s logs (server-sent events)', tag: 'Logs', query: LogQueryData::class, description: 'Starts live runtime logs like the explorer\'s Live and renews them while the stream is open. Events: `lines` ({entries, cursor}), `reconnect`. Resume with `Last-Event-ID`.', stream: true)]
    public function environmentStream(Request $request, Environment $environment, LogQueryData $query, #[CurrentUser] User $user, EnvironmentLogs $logs, StartLogTail $tail): StreamedResponse
    {
        Gate::authorize('view', $environment);
        $instance = $this->serviceOf($environment, $query);

        $tail->handle($environment, $user, $instance);
        $renewed = time();
        $cursor = EventStream::resumeCursor($request->headers->get('Last-Event-ID'), $query->after);

        if ($cursor === 0) {
            // Start from now: the newest stored line becomes the cursor.
            $cursor = $logs->page($environment, LogQueryData::from([...$query->toArray(), 'range' => '15m', 'after' => 0]), $user)->cursor;
        }

        return EventStream::response($cursor, static function (int $cursor) use ($environment, $query, $user, $logs, $tail, $instance, &$renewed): array {
            if (time() - $renewed >= 60) {
                $tail->handle($environment, $user, $instance);
                $renewed = time();
            }

            $page = $logs->page($environment, LogQueryData::from([...$query->toArray(), 'after' => $cursor]), $user);
            $events = $page->entries === [] ? [] : [[
                'event' => 'lines',
                'data' => ['entries' => array_map(static fn (LogEntryData $entry): array => $entry->toArray(), $page->entries), 'cursor' => $page->cursor],
                'id' => $page->cursor,
            ]];

            return ['events' => $events, 'cursor' => max($cursor, $page->cursor), 'done' => false];
        });
    }

    private function serviceOf(Environment $environment, LogQueryData $query): ?ServiceInstance
    {
        if ($query->service === null) {
            return null;
        }

        $service = Service::query()->where('project_id', $environment->project_id)->where('public_id', $query->service)->firstOrFail();

        return $this->instance($environment, $service);
    }
}

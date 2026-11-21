<?php

declare(strict_types=1);

namespace App\Queries\Commands;

use App\Data\Commands\CommandRunData;
use App\Models\Commands\CommandRun;
use App\Models\ServiceInstance;

/**
 * A service's one-off commands (D105) as the Commands tab, the API and the
 * CLI show them.
 */
final class CommandRuns
{
    /**
     * Newest first, `limit` after the run with id `before`.
     *
     * @return array{list<CommandRunData>, int|null} the page and the last id when more follow
     */
    public function page(ServiceInstance $instance, ?int $before, int $limit): array
    {
        $runs = CommandRun::query()
            ->where('team_id', $instance->team_id)
            ->where('service_instance_id', $instance->id)
            ->when($before !== null, static fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->with(self::RELATIONS)
            ->get();

        $more = $runs->count() > $limit;
        $page = $runs->take($limit);

        return [array_values($page->map(self::data(...))->all()), $more ? $page->last()?->id : null];
    }

    /** @var list<string> */
    public const array RELATIONS = ['serviceInstance.service', 'environment', 'deployment', 'server', 'user', 'cancelledBy'];

    public static function data(CommandRun $run): CommandRunData
    {
        $run->loadMissing(self::RELATIONS);

        return new CommandRunData(
            id: $run->public_id,
            status: $run->status,
            finished: $run->status->isTerminal(),
            argv: $run->argv,
            name: $run->command_name,
            exitCode: $run->exit_code,
            error: $run->error,
            timeoutSeconds: $run->timeout_seconds,
            outputTruncated: $run->output_truncated,
            service: $run->serviceInstance->service->public_id,
            environment: $run->environment->public_id,
            deployment: $run->deployment?->public_id,
            server: $run->server?->name,
            ranBy: $run->user?->name,
            viaApi: $run->api_token_id !== null,
            cancelledBy: $run->cancelledBy?->name,
            createdAt: ($run->created_at ?? now())->toIso8601String(),
            startedAt: $run->started_at?->toIso8601String(),
            finishedAt: $run->finished_at?->toIso8601String(),
            durationMs: $run->duration_ms,
        );
    }
}

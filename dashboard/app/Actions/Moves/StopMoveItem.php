<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Services\StopService;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\MoveItemStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\ServiceProcesses;
use Illuminate\Support\Facades\DB;

/**
 * Leaves a failed service of a move stopped (docs/14 §13.2): instead of
 * retrying it (its build fails on the new server, it isn't needed any
 * more), it is stopped like from its page (StopService, D68), with its
 * workers and crons, and the move goes on without it. It keeps its
 * settings, domains and deployments; started again later, it starts on the
 * move's target (the source drains).
 *
 * Only services without data on the source: a stateful one would leave its
 * volume behind on a server that is about to be retired, so it can only be
 * retried. Items of its workers and crons in the same move are settled as
 * stopped too.
 */
final readonly class StopMoveItem
{
    public function __construct(
        private StopService $stop,
        private RecordAuditEvent $audit,
        private AdvanceServerMoves $advance,
    ) {}

    public function handle(ServerMove $move, ServerMoveItem $item, User $user, AuditActor $actor): ServerMoveItem
    {
        $instance = DB::transaction(function () use ($move, $item): ServiceInstance {
            $move = ServerMove::query()->whereKey($move->id)->with('source')->lockForUpdate()->firstOrFail();
            $item = ServerMoveItem::query()->whereKey($item->id)->where('server_move_id', $move->id)->lockForUpdate()->firstOrFail();

            if (! $move->status->isUnderway()) {
                throw DomainRuleViolation::conflict('This move has ended.');
            }

            if ($item->status !== MoveItemStatus::Failed) {
                throw DomainRuleViolation::conflict('Only a failed service can be stopped here.');
            }

            $instance = ServiceInstance::query()->whereKey($item->service_instance_id)->where('team_id', $item->team_id)->whereNull('removed_at')->with(['service', 'environment.project'])->first();

            if (! $instance instanceof ServiceInstance) {
                throw DomainRuleViolation::conflict('This service was removed. The move skips it.');
            }

            if ($item->kind !== MoveItemKind::Stateless) {
                throw DomainRuleViolation::conflict("{$instance->service->name} keeps its data on {$move->source->name}, so it can’t be left behind. Retry it instead.");
            }

            return $instance;
        });

        $processes = ServiceProcesses::of($instance);

        // Stopped like from its page, so starting it again works the same way.
        if ($this->runs($instance)) {
            $this->stop->handle($instance, true, $user, $actor);
        } else {
            foreach ($processes as $process) {
                if ($this->runs($process)) {
                    $this->stop->handle($process, false, $user, $actor);
                }
            }
        }

        DB::transaction(function () use ($move, $item, $instance, $processes, $actor): void {
            $move = ServerMove::query()->whereKey($move->id)->with('target')->lockForUpdate()->firstOrFail();
            $ids = [$instance->id, ...$processes->modelKeys()];
            $names = implode(', ', $processes->map(static fn (ServiceInstance $process): string => $process->service->name)->all());
            $stopped = ServiceInstance::query()->whereKey($instance->id)->value('stopped_at') !== null;

            // Rows of containers launchroomd already removed would block retiring the source.
            Placement::query()->leftover()->whereIn('service_instance_id', $ids)->where('server_id', $move->source_server_id)->delete();

            $items = ServerMoveItem::query()->where('server_move_id', $move->id)->whereIn('service_instance_id', $ids)->lockForUpdate()->get();

            foreach ($items as $entry) {
                if (! in_array($entry->status, [MoveItemStatus::Pending, MoveItemStatus::Starting, MoveItemStatus::Failed], true) || $entry->kind !== MoveItemKind::Stateless) {
                    continue;
                }

                if ($entry->id === $item->id && ! $stopped) {
                    // It ran nowhere (never deployed): nothing was stopped.
                    $entry->transitionTo(MoveItemStatus::Skipped, 'It doesn’t run on any server, so there is nothing to stop or move.');

                    continue;
                }

                $entry->transitionTo(MoveItemStatus::Stopped, $move->stoppedItemMessage($entry->id === $item->id ? $names : ''));
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerMoveItemStopped,
                actor: $actor,
                teamId: $move->team_id,
                subjectType: 'server_move',
                subjectId: $move->public_id,
                meta: ['item' => $item->public_id, 'service' => $instance->service->public_id, 'processes' => count($processes)],
            ));
        });

        $this->advance->handle($move->id);

        return $item->refresh();
    }

    /**
     * Something to stop: not stopped, and running or about to.
     */
    private function runs(ServiceInstance $instance): bool
    {
        return $instance->stopped_at === null
            && ($instance->active_deployment_id !== null || Deployment::query()->where('service_instance_id', $instance->id)->unfinished()->exists());
    }
}

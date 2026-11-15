<?php

declare(strict_types=1);

namespace App\Actions\Daemons;

use App\Actions\Security\CompleteContainerInspection;
use App\Actions\Security\CompleteInventory;
use App\Data\Protocol\V1\ContainerInspectionResult;
use App\Data\Protocol\V1\InventoryResult;
use App\Data\Protocol\V1\TaskCompletion;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

/**
 * Records a task outcome. Idempotent by (task id, attempt) (docs/03 §7): a
 * repeated completion of the attempt that already completed is a no-op.
 * The terminal transition emits DaemonTaskFinished, which advances the
 * deployment the task belongs to and records plan previews.
 *
 * `released` (launchroomd shut down before the task finished, e.g. a restart
 * or upgrade) is not an outcome of the task: it goes back to the queue for
 * another attempt right away, like an expired lease, without using up an
 * attempt. Only its deadline bounds it (a passed deadline fails it).
 */
final readonly class CompleteTask
{
    public function __construct(
        private CompleteInventory $inventories,
        private CompleteContainerInspection $inspections,
    ) {}

    public function handle(Server $server, DaemonTask $task, TaskCompletion $completion): void
    {
        DB::transaction(function () use ($server, $task, $completion): void {
            $task = DaemonTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($task->server_id !== $server->id) {
                throw DomainRuleViolation::conflict('This server does not hold this task.');
            }

            if ($task->completed_attempt === $completion->attempt && $task->status->isTerminal()) {
                return;
            }

            if ($task->status !== DaemonTaskStatus::Leased || $task->attempt !== $completion->attempt) {
                throw DomainRuleViolation::conflict('This server does not hold a lease for this task attempt.');
            }

            if ($completion->outcome === 'released') {
                $this->release($task, $completion);

                return;
            }

            $outcome = $completion->outcome;
            $error = $completion->error instanceof Optional ? null : $completion->error;

            // Security results live in their own tables (docs/22 §8): an
            // inventory's components and an inspection, encrypted. A result
            // that fails its grammar fails the task instead.
            if ($outcome === 'succeeded') {
                $refusal = $this->securityResult($task, $completion);

                if ($refusal !== null) {
                    $outcome = 'failed';
                    $error = $refusal;
                }
            }

            $task->forceFill([
                'completed_attempt' => $completion->attempt,
                'result' => $completion->results->isEmpty() ? null : $completion->results,
                'error' => $error,
                'lease_expires_at' => null,
            ]);

            $task->transitionTo(match ($outcome) {
                'succeeded' => DaemonTaskStatus::Succeeded,
                'cancelled' => DaemonTaskStatus::Cancelled,
                default => DaemonTaskStatus::Failed,
            });
        });
    }

    /**
     * Stores an inventory or a container inspection. Returns why the result
     * was refused, or null.
     */
    private function securityResult(DaemonTask $task, TaskCompletion $completion): ?string
    {
        try {
            if ($task->type === DaemonTaskType::Inventory) {
                if (! is_array($completion->inventory)) {
                    return 'The server reported no inventory.';
                }

                $this->inventories->handle($task, InventoryResult::fromWire($completion->inventory));
            }

            if ($task->type === DaemonTaskType::InspectContainer) {
                if (! is_array($completion->containerInspection)) {
                    return 'The server reported no inspection.';
                }

                $this->inspections->handle($task, ContainerInspectionResult::fromWire($completion->containerInspection));
            }
        } catch (ValidationException $e) {
            $problems = [];

            foreach ($e->errors() as $field => $messages) {
                $problems[] = $field.' '.(is_array($messages) && is_string($messages[0] ?? null) ? $messages[0] : 'is invalid');
            }

            return mb_substr('The result didn’t match the protocol: '.implode('; ', $problems), 0, 4000);
        } catch (DomainRuleViolation $e) {
            return mb_substr($e->getMessage(), 0, 4000);
        }

        return null;
    }

    private function release(DaemonTask $task, TaskCompletion $completion): void
    {
        $error = $completion->error instanceof Optional ? 'launchroomd stopped before the task finished.' : $completion->error;

        // A session or an access can't move to another process: its shell,
        // keys and listener are gone (docs/03 §11.1, §12.1).
        if (in_array($task->type, [DaemonTaskType::Session, DaemonTaskType::Access], true) || ($task->deadline_at?->isPast() ?? false)) {
            $task->forceFill(['completed_attempt' => $completion->attempt, 'error' => $error, 'lease_expires_at' => null]);
            $task->transitionTo(DaemonTaskStatus::Failed);

            return;
        }

        $task->forceFill([
            'lease_expires_at' => null,
            'server_id' => $task->pinned_to_server ? $task->server_id : null,
            // A release is not a failed attempt: keep the attempt budget.
            'max_attempts' => max($task->max_attempts, $task->attempt + 1),
            'error' => null,
        ]);
        $task->transitionTo(DaemonTaskStatus::Pending);
    }
}

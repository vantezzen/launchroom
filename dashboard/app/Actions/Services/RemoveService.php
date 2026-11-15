<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Backups\RequestFinalBackups;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\ActorType;
use App\Enums\AuditAction;
use App\Events\CanvasChanged;
use App\Models\Backups\DeletionRequest;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stages the removal of a service from one environment: excluded from the
 * intent now, torn down by the next Deploy, restorable by Discard.
 * Volumes (data) are never deleted by this.
 *
 * A database or a service with volumes that has backups configured gets a
 * final backup now, while it still runs (docs/14 §9.13): the Deploy that
 * tears it down waits for it (FinalBackups::assertCleared()).
 */
final readonly class RemoveService
{
    public function __construct(
        private RecordAuditEvent $audit,
        private RequestFinalBackups $requestFinalBackups,
    ) {}

    public function handle(ServiceInstance $instance, AuditActor $actor): void
    {
        $removed = DB::transaction(function () use ($instance, $actor): bool {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with('service')->lockForUpdate()->firstOrFail();

            if ($instance->removed_at !== null) {
                return false;
            }

            $instance->forceFill(['removed_at' => now()])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceRemoved,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['instance' => $instance->public_id],
            ));
            CanvasChanged::forInstance($instance);

            return true;
        });

        // Final backup before the service goes away (docs/14 §9.13); none
        // when it holds no data, has no backups configured or never ran.
        if ($removed) {
            DB::transaction(function () use ($instance, $actor): void {
                $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'volumes'])->lockForUpdate()->firstOrFail();
                $user = $actor->type === ActorType::User && $actor->id !== null ? User::query()->find($actor->id) : null;

                $this->requestFinalBackups->handle(
                    DeletionRequest::SERVICE_INSTANCE,
                    $instance->id,
                    $instance->public_id,
                    $instance->service->name,
                    $instance->team_id,
                    new Collection([$instance]),
                    $user,
                    $actor,
                );
            });
        }
    }
}

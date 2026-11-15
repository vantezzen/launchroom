<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Enums\Backups\DeletionRequestStatus;
use App\Models\Backups\DeletionRequest;
use App\Models\ServiceInstance;
use Illuminate\Support\Facades\DB;

/**
 * Keeps an instance whose removal was staged (Discard, or a launchroom.json
 * that lists it again). The removal's final backup no longer gates anything
 * (docs/14 §9.13).
 */
final readonly class CancelServiceRemoval
{
    public function handle(ServiceInstance $instance): void
    {
        if ($instance->removed_at === null) {
            return;
        }

        DB::transaction(function () use ($instance): void {
            $instance->forceFill(['removed_at' => null])->save();
            DeletionRequest::openFor(DeletionRequest::SERVICE_INSTANCE, $instance->id, lock: true)?->transitionTo(DeletionRequestStatus::Cancelled);
        });
    }
}

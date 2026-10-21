<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\RenameServiceData;
use App\Enums\AuditAction;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Renames a service in every environment of its project. The slug stays: it
 * is the private hostname, part of generated domains and of host-side names,
 * which must not move under running apps. The new name shows as a staged
 * change (no restart) until the next deploy of each environment.
 */
final readonly class RenameService
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Service $service, RenameServiceData $data, AuditActor $actor): Service
    {
        return DB::transaction(function () use ($service, $data, $actor): Service {
            $name = trim($data->name);
            $before = $service->name;

            if ($name === $before) {
                return $service;
            }

            $service->forceFill(['name' => $name])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceRenamed,
                actor: $actor,
                teamId: $service->team_id,
                subjectType: 'service',
                subjectId: $service->public_id,
                meta: ['from' => $before, 'to' => $name],
            ));

            return $service;
        });
    }
}

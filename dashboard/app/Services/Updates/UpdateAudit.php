<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;

/**
 * Audit entries for finished updates (docs/09 R26): recorded by the system,
 * since updates finish in the background.
 */
final readonly class UpdateAudit
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function controlPlaneFinished(ControlPlaneUpdate $update): void
    {
        $this->audit->handle(new AuditEntryData(
            action: AuditAction::ControlPlaneUpdateFinished,
            actor: AuditActor::system(),
            subjectType: 'control_plane_update',
            subjectId: $update->public_id,
            meta: ['from' => $update->from_version, 'to' => $update->to_version, 'status' => $update->status->value],
        ));
    }

    public function fleetFinished(FleetUpdate $update): void
    {
        $this->audit->handle(new AuditEntryData(
            action: AuditAction::FleetUpdateFinished,
            actor: AuditActor::system(),
            subjectType: 'fleet_update',
            subjectId: $update->public_id,
            meta: ['to' => $update->to_version, 'status' => $update->status->value],
        ));
    }
}

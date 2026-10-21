<?php

declare(strict_types=1);

namespace App\Actions\Connections;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Removes a canvas edge and the variables it owns (staged).
 */
final readonly class DeleteConnection
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Connection $connection, AuditActor $actor): void
    {
        DB::transaction(function () use ($connection, $actor): void {
            $connection->variables()->delete();
            $connection->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ConnectionDeleted,
                actor: $actor,
                teamId: $connection->team_id,
                subjectType: 'connection',
                subjectId: $connection->public_id,
            ));
        });
    }
}

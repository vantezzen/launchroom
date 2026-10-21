<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ApproveServer
{
    public function __construct(
        private RenderNodeState $renderNodeState,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Server $server, User $approver, AuditActor $actor): void
    {
        DB::transaction(function () use ($server, $approver, $actor): void {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            $server->forceFill(['approved_at' => now(), 'approved_by' => $approver->id]);
            $server->transitionTo(ServerStatus::Online);

            $this->renderNodeState->handle($server);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerApproved,
                actor: $actor,
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
            ));
        });
    }
}

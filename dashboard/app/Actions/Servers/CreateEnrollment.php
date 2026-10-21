<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Servers\CreateEnrollmentData;
use App\Data\Servers\IssuedEnrollment;
use App\Enums\AuditAction;
use App\Enums\ServerRole;
use App\Models\ServerEnrollment;
use App\Models\Team;
use App\Models\User;
use App\Support\Crypto\OpaqueToken;
use Illuminate\Support\Facades\DB;

/**
 * Issues a join token (docs/03 §3, docs/09 R21): 256-bit, shown once, hashed,
 * single use, 30-minute TTL, bound to the team and roles. The token grammar
 * matches the daemon's (`^[A-Za-z0-9_.-]{16,256}$`).
 */
final readonly class CreateEnrollment
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Team $team, CreateEnrollmentData $data, ?User $createdBy, AuditActor $actor, bool $controlPlane = false): IssuedEnrollment
    {
        return DB::transaction(function () use ($team, $data, $createdBy, $actor, $controlPlane): IssuedEnrollment {
            $token = OpaqueToken::generate('lr_jt_');
            $roles = $data->serverRoles();

            $enrollment = ServerEnrollment::query()->create([
                'team_id' => $team->id,
                'token_hash' => OpaqueToken::hash($token),
                'roles' => $roles,
                'name' => $data->name,
                'is_control_plane' => $controlPlane,
                'created_by' => $createdBy?->id,
                'expires_at' => now()->addMinutes(config()->integer('launchroom.daemon.enrollment_ttl_minutes')),
            ]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerEnrollmentCreated,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'server_enrollment',
                subjectId: $enrollment->public_id,
                meta: [
                    'roles' => array_map(static fn (ServerRole $role): string => $role->value, $roles),
                    'control_plane' => $controlPlane,
                ],
            ));

            return new IssuedEnrollment($enrollment, $token);
        });
    }
}

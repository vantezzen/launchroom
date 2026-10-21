<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

final class InvitationData extends Data
{
    public function __construct(
        public string $id,
        public string $email,
        public TeamRole $role,
        public ?string $invitedBy,
        public CarbonInterface $expiresAt,
        public bool $expired,
    ) {}

    public static function fromInvitation(TeamInvitation $invitation): self
    {
        return new self(
            $invitation->public_id,
            $invitation->email,
            $invitation->role,
            $invitation->inviter?->name,
            $invitation->expires_at,
            ! $invitation->expires_at->isFuture(),
        );
    }
}

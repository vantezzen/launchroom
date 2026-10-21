<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

/**
 * What an invitee sees before accepting.
 */
final class InvitationPreviewData extends Data
{
    public function __construct(
        public string $teamName,
        public string $email,
        public TeamRole $role,
        public ?string $invitedBy,
        public bool $hasAccount,
    ) {}
}

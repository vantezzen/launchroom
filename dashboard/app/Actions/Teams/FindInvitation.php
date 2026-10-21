<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Support\Crypto\OpaqueToken;
use SensitiveParameter;

/**
 * Looks up a pending invitation by its token (hash lookup, confirmed with
 * hash_equals). Null for unknown, used or expired tokens.
 */
final class FindInvitation
{
    public function handle(#[SensitiveParameter] string $token, bool $lock = false): ?TeamInvitation
    {
        if (preg_match('/^lr_inv_[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return null;
        }

        $query = TeamInvitation::query()->with(['team', 'inviter'])->where('token_hash', OpaqueToken::hash($token));

        $invitation = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($invitation === null || ! OpaqueToken::matches($token, $invitation->token_hash) || ! $invitation->isPending()) {
            return null;
        }

        return $invitation;
    }
}

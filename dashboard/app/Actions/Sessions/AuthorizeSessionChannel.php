<?php

declare(strict_types=1);

namespace App\Actions\Sessions;

use App\Data\Sessions\AuthorizeSessionData;
use App\Exceptions\DomainRuleViolation;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Services\Sessions\SessionRelay;
use App\Services\Sessions\SessionSecrets;
use Illuminate\Support\Facades\DB;

/**
 * Lets the browser join `private-session.<ses>` (docs/03 §11.2): only the
 * user who opened the session, only with its unexpired, unused ticket, which
 * is consumed here (single use). Returns the Pusher auth for that socket.
 */
final readonly class AuthorizeSessionChannel
{
    public function __construct(
        private SessionRelay $relay,
    ) {}

    public function handle(User $user, InteractiveSession $session, AuthorizeSessionData $data): string
    {
        return DB::transaction(function () use ($user, $session, $data): string {
            $session = InteractiveSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            $valid = $session->user_id === $user->id
                && $session->status->isLive()
                && $session->ticket_used_at === null
                && $session->ticket_expires_at->isFuture()
                && hash_equals($session->ticket_hash, SessionSecrets::hash($data->ticket));

            if (! $valid) {
                throw DomainRuleViolation::forbidden('This session link has expired. Open a new session.');
            }

            $session->forceFill(['ticket_used_at' => now()])->save();
            $auth = $this->relay->authorize($session, $data->socketId);

            if ($auth === null) {
                throw DomainRuleViolation::conflict('Live sessions are unavailable right now.');
            }

            return $auth;
        });
    }
}

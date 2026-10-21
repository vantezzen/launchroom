<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Data\Protocol\ProtocolDocument;
use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\NudgeConfig;
use App\Data\Sessions\SessionCommandData;
use App\Models\Sessions\InteractiveSession;
use App\Services\Crypto\Signer;
use App\Services\Daemon\DaemonNudger;
use App\Support\PublicId;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The dashboard's side of the session relay (docs/03 §11.2): Pusher channel
 * authorizations for `private-session.<ses>` and Ed25519-signed
 * `session.command` / `session.end` server events. Only the holder of the
 * Reverb app secret can send events not named `client-*`, and launchroomd
 * verifies the signature anyway, so Reverb never decides anything.
 *
 * Sessions need the Pusher-protocol broadcaster (the same switch as daemon
 * nudges): without it the tabs say live sessions are unavailable.
 */
final readonly class SessionRelay
{
    public const string EVENT_COMMAND = 'session.command';

    public const string EVENT_END = 'session.end';

    public function __construct(
        private DaemonNudger $nudger,
        private Signer $signer,
        private BroadcastFactory $broadcast,
    ) {}

    public function available(): bool
    {
        return config('launchroom.sessions.enabled') === true && $this->nudger->config() instanceof NudgeConfig;
    }

    /**
     * Pusher auth ("<key>:<hex HMAC>") of $socketId on the session's channel.
     */
    public function authorize(InteractiveSession $session, string $socketId): ?string
    {
        return $this->nudger->authorize($socketId, 'private-'.$session->channelName());
    }

    /**
     * Sends one signed command to the session's server; returns its op id.
     *
     * @throws RuntimeException when Reverb can't be reached
     */
    public function send(InteractiveSession $session, SessionCommandData $command, ?string $op = null): string
    {
        $op ??= PublicId::generate('op');
        $payload = json_encode([
            'apiVersion' => ProtocolDocument::API_VERSION,
            'kind' => 'SessionCommand',
            'session' => $session->public_id,
            'op' => $op,
            'issuedAt' => ProtocolJson::time(now()->toImmutable()),
            ...$command->toWire(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $this->broadcast->connection()->broadcast(
                [new PrivateChannel($session->channelName())],
                $command->command === 'end' ? self::EVENT_END : self::EVENT_COMMAND,
                ['payload' => $payload, 'signature' => $this->signer->signatureHeader($payload)],
            );
        } catch (Throwable $e) {
            Log::warning('Session command could not be relayed.', ['session' => $session->public_id, 'command' => $command->command, 'error' => $e->getMessage()]);

            throw new RuntimeException('The live connection to the server is unavailable right now.', $e->getCode(), previous: $e);
        }

        return $op;
    }

    /**
     * Best-effort end (the next heartbeat cancels the session anyway).
     */
    public function end(InteractiveSession $session, string $reason): void
    {
        try {
            $this->send($session, SessionCommandData::end($reason));
        } catch (RuntimeException) {
            // Logged in send(); heartbeats carry the cancellation.
        }
    }
}

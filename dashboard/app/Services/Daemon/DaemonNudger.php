<?php

declare(strict_types=1);

namespace App\Services\Daemon;

use App\Data\Protocol\V1\NudgeConfig;
use App\Enums\DaemonNudge;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends realtime nudges to daemons (docs/03 §4) on their private channel
 * `daemon.{server public id}` ("private-daemon.srv_…" on the wire).
 *
 * Nudges carry no data: they only make the daemon fetch now through the
 * signed HTTP channel, so they are safe to lose, duplicate or reorder. They
 * are sent after the surrounding transaction commits (the daemon must find
 * the new state or task) and synchronously, without the queue, because
 * queue latency is what they exist to remove. A broadcaster failure is
 * logged and swallowed: daemons keep polling.
 *
 * Only Reverb/Pusher connections can nudge; with any other default
 * broadcaster (tests: null) nudges are off and config() returns null.
 */
final readonly class DaemonNudger
{
    /** Pusher accepts at most 100 channels per trigger. */
    private const int CHANNELS_PER_TRIGGER = 100;

    public function __construct(
        private BroadcastFactory $broadcast,
    ) {}

    /**
     * @param  iterable<string>  $serverPublicIds
     */
    public function nudge(iterable $serverPublicIds, DaemonNudge $nudge): void
    {
        $ids = [];

        foreach ($serverPublicIds as $id) {
            $ids[$id] = true;
        }

        if ($ids === [] || ! $this->enabled()) {
            return;
        }

        DB::afterCommit(fn () => $this->send(array_keys($ids), $nudge));
    }

    /**
     * The nudge settings daemons get from GET /daemon/v1/config and the enroll
     * response; null when nudges are off.
     */
    public function config(): ?NudgeConfig
    {
        if (! $this->enabled()) {
            return null;
        }

        $url = config('launchroom.daemon.nudge_url');

        return new NudgeConfig(
            key: $this->credential('key'),
            url: is_string($url) && $url !== '' ? rtrim($url, '/') : null,
        );
    }

    /**
     * Pusher channel authorization ("<key>:<hex HMAC-SHA256>") of socket
     * $socketId for $channel. The caller decides whether the daemon may join.
     */
    public function authorize(string $socketId, string $channel): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->credential('key').':'.hash_hmac('sha256', $socketId.':'.$channel, $this->credential('secret'));
    }

    public static function channel(string $serverPublicId): string
    {
        return 'daemon.'.$serverPublicId;
    }

    private function enabled(): bool
    {
        $connection = $this->connection();

        return config('launchroom.daemon.nudges') === true
            && $connection !== null
            && $this->credential('key') !== ''
            && $this->credential('secret') !== '';
    }

    /**
     * @return array<array-key, mixed>|null the default broadcaster's config when it speaks the Pusher protocol
     */
    private function connection(): ?array
    {
        $name = config('broadcasting.default');
        $connection = is_string($name) ? config("broadcasting.connections.{$name}") : null;

        return is_array($connection) && in_array($connection['driver'] ?? null, ['reverb', 'pusher'], true) ? $connection : null;
    }

    private function credential(string $key): string
    {
        $value = $this->connection()[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  list<string>  $ids
     */
    private function send(array $ids, DaemonNudge $nudge): void
    {
        foreach (array_chunk($ids, self::CHANNELS_PER_TRIGGER) as $chunk) {
            try {
                $this->broadcast->connection()->broadcast(
                    array_map(static fn (string $id): PrivateChannel => new PrivateChannel(self::channel($id)), $chunk),
                    $nudge->value,
                );
            } catch (Throwable $e) {
                Log::warning('Daemon nudge failed; daemons fall back to polling.', ['nudge' => $nudge->value, 'servers' => count($chunk), 'error' => $e->getMessage()]);
            }
        }
    }
}

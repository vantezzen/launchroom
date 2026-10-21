<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use RuntimeException;

/**
 * Records broadcasts instead of sending them. install() makes it the
 * default "reverb" connection (daemon nudges need a Pusher-protocol driver).
 */
final class SpyBroadcaster implements Broadcaster
{
    /** @var list<array{channels: list<string>, event: string, payload: array<mixed>}> */
    public array $sent = [];

    public bool $fail = false;

    public static function install(): self
    {
        $spy = new self;

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test'],
            'launchroom.daemon.nudges' => true,
        ]);
        Broadcast::extend('reverb', static fn (): Broadcaster => $spy);
        Broadcast::purge('reverb');

        return $spy;
    }

    public function auth($request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        if ($this->fail) {
            throw new RuntimeException('reverb is down');
        }

        $this->sent[] = ['channels' => array_map(static fn (mixed $channel): string => (string) $channel, $channels), 'event' => (string) $event, 'payload' => $payload];
    }

    /**
     * @return list<string> "event channel" pairs
     */
    public function pairs(): array
    {
        $out = [];

        foreach ($this->sent as $sent) {
            foreach ($sent['channels'] as $channel) {
                $out[] = "{$sent['event']} {$channel}";
            }
        }

        sort($out);

        return $out;
    }
}

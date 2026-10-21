<?php

declare(strict_types=1);

namespace App\Http\Api;

use Closure;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-sent events by polling stored rows (docs/16 §2.3). `$tick` gets the
 * cursor and returns the events to send, the new cursor and whether the
 * stream is done. Pings every few seconds; after the configured maximum the
 * stream ends with `reconnect` so a client resumes with `Last-Event-ID`.
 */
final class EventStream
{
    /**
     * @param  Closure(int): array{events: list<array{event: string, data: array<string, mixed>, id?: int}>, cursor: int, done: bool}  $tick
     */
    public static function response(int $cursor, Closure $tick): StreamedResponse
    {
        return new StreamedResponse(static function () use ($cursor, $tick): void {
            $deadline = microtime(true) + config()->integer('api.streams.max_seconds');
            $pollMicros = config()->integer('api.streams.poll_milliseconds') * 1000;
            $pingEvery = (float) config()->integer('api.streams.ping_seconds');
            $lastWrite = microtime(true);

            echo "retry: 2000\n\n";
            self::flush();

            while (true) {
                $result = $tick($cursor);
                $cursor = $result['cursor'];

                foreach ($result['events'] as $event) {
                    self::write($event['event'], $event['data'], $event['id'] ?? null);
                    $lastWrite = microtime(true);
                }

                if ($result['done']) {
                    return;
                }

                if (microtime(true) >= $deadline || connection_aborted() === 1) {
                    self::write('reconnect', ['cursor' => $cursor], $cursor);

                    return;
                }

                if (microtime(true) - $lastWrite >= $pingEvery) {
                    echo ": ping\n\n";
                    self::flush();
                    $lastWrite = microtime(true);
                }

                Sleep::usleep($pollMicros);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The cursor to resume from: `Last-Event-ID`, else `?after=`.
     */
    public static function resumeCursor(?string $lastEventId, int $after): int
    {
        return $lastEventId !== null && preg_match('/^[0-9]{1,18}$/D', $lastEventId) === 1 ? (int) $lastEventId : $after;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function write(string $event, array $data, ?int $id): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n\n";
        self::flush();
    }

    private static function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}

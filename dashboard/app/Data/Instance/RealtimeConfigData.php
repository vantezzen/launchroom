<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * Public Reverb client settings for the browser (docs/08 §8), shared as an
 * Inertia prop so the production image (built without VITE_REVERB_*) can
 * connect. Only public values: the app key identifies the Reverb app, it is
 * not a secret (the secret signs server-side broadcasts and channel auth).
 * Null host/port/scheme mean "the dashboard's own origin".
 */
final class RealtimeConfigData extends Data
{
    public function __construct(
        public string $key,
        public ?string $host,
        public ?int $port,
        /** `http` or `https`. */
        public ?string $scheme,
    ) {}

    public static function current(): ?self
    {
        $key = config('broadcasting.connections.reverb.key');

        if (config('broadcasting.default') !== 'reverb' || ! is_string($key) || $key === '') {
            return null;
        }

        $host = config('launchroom.realtime.host');
        $port = config('launchroom.realtime.port');
        $scheme = config('launchroom.realtime.scheme');

        return new self(
            key: $key,
            host: is_string($host) && $host !== '' ? $host : null,
            port: is_numeric($port) && (int) $port > 0 && (int) $port < 65536 ? (int) $port : null,
            scheme: in_array($scheme, ['http', 'https'], true) ? $scheme : null,
        );
    }
}

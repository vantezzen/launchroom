<?php

declare(strict_types=1);

namespace App\Actions\Traces;

use App\Exceptions\DecryptionFailed;
use App\Models\Team;
use App\Models\Traces\TraceKey;
use App\Services\Traces\TraceKeys;
use App\Support\Traces\DecodedTrace;
use App\Support\Traces\TraceCodec;
use App\Support\Traces\TraceEnvelope;

/**
 * Opens a pasted `Launchroom-Trace` value (docs/23 §6): parse, find the key
 * by its id among the team's edges, decrypt, check the key's server is the
 * edge the payload names, decode the fields. Null for anything else,
 * without saying why: a malformed value, an unknown or deleted key, another
 * team's edge and a failed authentication all look the same. Whether the
 * member may see the service is App\Queries\Traces\RequestTraceView's check.
 */
final readonly class DecodeTrace
{
    /** The one answer for a trace that can't be shown, whatever the reason (docs/23 §6). */
    public const string NOT_READABLE = "This trace isn't from one of your services.";

    public function __construct(
        private TraceKeys $keys,
    ) {}

    public function handle(string $input, Team $team): ?DecodedTrace
    {
        $value = TraceCodec::value($input);
        $envelope = $value === null ? null : TraceCodec::envelope($value);
        $key = $envelope instanceof TraceEnvelope ? $this->keys->find($envelope->keyId) : null;

        if (! $envelope instanceof TraceEnvelope || ! $key instanceof TraceKey || $key->server->team_id !== $team->id
            || $key->retired_at?->lessThanOrEqualTo(now()->subDays(ProvisionTraceKeys::READABLE_DAYS)) === true) {
            return null;
        }

        try {
            $payload = TraceCodec::open($envelope, $this->keys->reveal($key));
        } catch (DecryptionFailed) {
            return null;
        }

        $trace = $payload === null ? null : TraceCodec::fields($payload);

        return $trace instanceof DecodedTrace && hash_equals($key->server->public_id, $trace->edge) ? $trace : null;
    }
}

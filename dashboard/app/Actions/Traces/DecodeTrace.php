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
 * Opens a pasted ray, the `X-Launchroom-Ray` value (docs/23 §6): parse, find the key
 * by its id among the team's edges, decrypt, check the key's server is the
 * edge the payload names, decode the fields. Null for anything else,
 * without saying why: a malformed value, an unknown or deleted key, another
 * team's edge and a failed authentication all look the same. Whether the
 * member may see the service is App\Queries\Traces\RequestTraceView's check.
 */
final readonly class DecodeTrace
{
    /** The one answer for a ray that can't be shown, whatever the reason (docs/23 §6). */
    public const string NOT_READABLE = "This ray isn't from one of your services.";

    /** The answer for a ray in a newer format (`lr2.` and up): its prefix is public, so saying so leaks nothing. */
    public const string NEWER = 'This ray was made by a newer version of launchroom. Update launchroom to read it.';

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

    /**
     * Why a ray handle() refused can't be shown: NEWER for a newer format,
     * NOT_READABLE for everything else.
     */
    public function refusal(string $input): string
    {
        $value = TraceCodec::value($input);

        return $value !== null && TraceCodec::newer($value) ? self::NEWER : self::NOT_READABLE;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Traces;

use App\Enums\Traces\TraceFailure;
use App\Enums\Traces\TraceFirewallOutcome;
use App\Enums\Traces\TraceOrigin;

/**
 * The fields of a decrypted `Launchroom-Trace` payload (docs/23 §2), as the
 * edge wrote them: ids are public ids (`srv_…`, `rt_…`, `sin_…`), optional
 * fields are null when absent. Nothing here is looked up or checked against a team;
 * App\Queries\Traces\RequestTraceView does that.
 */
final readonly class DecodedTrace
{
    /**
     * @param  list<int>  $firewallRules
     */
    public function __construct(
        public int $receivedAtMs,
        public string $requestId,
        public string $edge,
        public int $status,
        public TraceOrigin $origin,
        public ?string $route = null,
        public ?string $service = null,
        public ?string $upstreamServer = null,
        public ?string $method = null,
        public ?string $path = null,
        public ?string $protocol = null,
        public ?int $edgeTimeUs = null,
        public ?int $upstreamTimeUs = null,
        public ?int $upstreamStatus = null,
        public ?int $attempts = null,
        public ?TraceFailure $failure = null,
        /** 1 log only, 2 block; null without the firewall field. */
        public ?int $firewallMode = null,
        public ?TraceFirewallOutcome $firewallOutcome = null,
        public ?int $firewallScore = null,
        public array $firewallRules = [],
    ) {}
}

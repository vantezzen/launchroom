<?php

declare(strict_types=1);

namespace App\Data\Traces;

use App\Data\Security\SecurityServiceRefData;
use App\Data\Security\WafExclusionDraftData;
use App\Enums\Traces\TraceFailure;
use App\Enums\Traces\TraceOrigin;
use App\Enums\Traces\TraceStop;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * A decoded request trace as the trace page shows it (docs/23 §6–7): the
 * path from the visitor over the edge (and its firewall) and the app server
 * to the service, where it ended, and what the member can do next.
 */
final class RequestTraceData extends Data
{
    public function __construct(
        /** ISO 8601 UTC with milliseconds. */
        public string $receivedAt,
        /** The UUID the app got as X-Request-Id. */
        public string $requestId,
        public ?string $method,
        /** The normalised path (ids as `:id`), without query string. */
        public ?string $path,
        /** `HTTP/1.0`, `HTTP/1.1`, `HTTP/2` or `HTTP/3`. */
        public ?string $protocol,
        /** The status the edge sent. */
        public int $status,
        /** The status the app answered, only when it differs from `status`. */
        public ?int $upstreamStatus,
        public TraceOrigin $origin,
        public ?TraceFailure $failure,
        /** Microseconds from receipt to the response headers. */
        public ?int $edgeTimeUs,
        /** Microseconds from the last attempt's dial to the app's response headers. */
        public ?int $upstreamTimeUs,
        public ?int $attempts,
        public ?TraceFirewallData $firewall,
        public TraceServerData $edge,
        /** The server the last attempt went to (the edge itself for local replicas). */
        public ?TraceServerData $upstreamServer,
        /** Edge and app server are one server. */
        public bool $sameServer,
        /** The matched route's domain or deployment URL. */
        public ?string $host,
        public ?SecurityServiceRefData $service,
        /** The dashboard's own route. */
        public bool $dashboard,
        public TraceStop $endsAt,
        /** A key of REQUEST_CLASS (request-breakdown.ts): colour and word of the outcome. */
        #[LiteralTypeScriptType("'success' | 'redirect' | 'clientError' | 'blocked' | 'serverError' | 'upgrade'")]
        public string $requestClass,
        /** One sentence of what happened. */
        public string $summary,
        /** The service's logs around the request, searched for its id. */
        public ?string $logsHref,
        /** The service's firewall settings. */
        public ?string $firewallHref,
        /** "Allow requests like this", for members who may add exceptions. */
        public ?WafExclusionDraftData $allow,
    ) {}
}

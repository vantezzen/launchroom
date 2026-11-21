<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Data\Security\SecurityServiceRefData;
use App\Data\Traces\TraceFirewallData;
use App\Enums\Traces\TraceFailure;
use App\Enums\Traces\TraceOrigin;
use App\Enums\Traces\TraceStop;
use Spatie\LaravelData\Data;

/**
 * A decoded ray (docs/23 §6–7), as the trace page shows it: the request,
 * where its time went (edge and app), the firewall's decision, the servers
 * on its path, where it ended and the window of the service's logs around
 * it (`logsFrom`/`logsTo`, epoch seconds, for `listEnvironmentLogs` with
 * `q` = `requestId`).
 */
final class ApiTraceData extends Data
{
    public function __construct(
        /** ISO 8601 UTC with milliseconds. */
        public string $receivedAt,
        /** The UUID the app got as X-Request-Id: search the logs for it. */
        public string $requestId,
        public ?string $method,
        /** The normalised path (ids as `:id`), without query string. */
        public ?string $path,
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
        public ApiTraceServerData $edge,
        /** The server the last attempt went to (the edge itself for local replicas). */
        public ?ApiTraceServerData $upstreamServer,
        public bool $sameServer,
        /** The matched route's domain or deployment URL. */
        public ?string $host,
        public ?SecurityServiceRefData $service,
        /** The request went to the dashboard itself. */
        public bool $dashboard,
        public TraceStop $endsAt,
        /** One sentence of what happened. */
        public string $summary,
        public ?int $logsFrom,
        public ?int $logsTo,
        /** The trace page for this ray. */
        public string $dashboardUrl,
    ) {}
}

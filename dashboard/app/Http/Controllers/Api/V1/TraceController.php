<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Traces\DecodeTrace;
use App\Data\Api\ApiTraceData;
use App\Data\Api\ApiTraceServerData;
use App\Data\Api\DecodeTraceInput;
use App\Data\Security\SecurityServiceRefData;
use App\Data\Traces\RequestTraceData;
use App\Data\Traces\TraceServerData;
use App\Exceptions\DomainRuleViolation;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Queries\Traces\RequestTraceView;
use App\Support\Traces\DecodedTrace;
use App\Support\Traces\TraceCodec;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Rays through the API (docs/23 §6, D111): the trace page's decode for
 * `launchroom ray` and scripts. Same check and the same single answer for
 * anything that can't be shown (TraceCodec::refusal), whatever the reason.
 */
final class TraceController extends Controller
{
    use ScopesApiRoutes;

    /** The service's logs around the request (RequestTraceView). */
    private const int LOG_WINDOW_SECONDS = 60;

    #[ApiOperation(id: 'decodeTrace', summary: 'Decode a ray (the X-Launchroom-Ray response header)', tag: 'Traces', response: ApiTraceData::class, request: DecodeTraceInput::class, description: '`value` is the header value or the whole header line. A ray that isn\'t from one of the team\'s services, or that the token\'s owner may not see, is one `rule_violation` answer, whatever the reason; a ray from a newer launchroom says so.')]
    public function decode(DecodeTraceInput $input, #[CurrentUser] User $user, DecodeTrace $decode, RequestTraceView $view): JsonResponse
    {
        $team = $this->team($user);
        Gate::authorize('view', $team);

        $decoded = $decode->handle($input->value, $team);
        $trace = $decoded instanceof DecodedTrace ? $view->handle($decoded, $team, $user) : null;

        if (! $trace instanceof RequestTraceData) {
            throw DomainRuleViolation::on('value', TraceCodec::refusal($input->value));
        }

        $receivedAt = CarbonImmutable::parse($trace->receivedAt);
        $server = static fn (TraceServerData $server): ApiTraceServerData => new ApiTraceServerData($server->id, $server->name, $server->status);

        return ApiResponse::item(new ApiTraceData(
            receivedAt: $trace->receivedAt,
            requestId: $trace->requestId,
            method: $trace->method,
            path: $trace->path,
            protocol: $trace->protocol,
            status: $trace->status,
            upstreamStatus: $trace->upstreamStatus,
            origin: $trace->origin,
            failure: $trace->failure,
            edgeTimeUs: $trace->edgeTimeUs,
            upstreamTimeUs: $trace->upstreamTimeUs,
            attempts: $trace->attempts,
            firewall: $trace->firewall,
            edge: $server($trace->edge),
            upstreamServer: $trace->upstreamServer instanceof TraceServerData ? $server($trace->upstreamServer) : null,
            sameServer: $trace->sameServer,
            host: $trace->host,
            service: $trace->service,
            dashboard: $trace->dashboard,
            endsAt: $trace->endsAt,
            summary: $trace->summary,
            logsFrom: $trace->service instanceof SecurityServiceRefData ? $receivedAt->subSeconds(self::LOG_WINDOW_SECONDS)->getTimestamp() : null,
            logsTo: $trace->service instanceof SecurityServiceRefData ? $receivedAt->addSeconds(self::LOG_WINDOW_SECONDS)->getTimestamp() : null,
            dashboardUrl: route('trace', ['t' => mb_substr(trim($input->value), 0, TraceCodec::MAX_INPUT)]),
        ));
    }
}

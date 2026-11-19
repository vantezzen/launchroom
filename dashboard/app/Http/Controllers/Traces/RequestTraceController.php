<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traces;

use App\Actions\Traces\DecodeTrace;
use App\Data\Traces\RequestTraceData;
use App\Data\Traces\TraceQueryData;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Models\Team;
use App\Models\User;
use App\Queries\Traces\RequestTraceView;
use App\Support\Traces\DecodedTrace;
use App\Support\Traces\TraceCodec;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/trace` (docs/23 §6): paste a `Launchroom-Trace` header and see the
 * request's path. With `?t=` the trace is decoded here, so a trace link can
 * be shared with a teammate; anything the member can't see, or that isn't
 * a trace, gets one answer (DecodeTrace::NOT_READABLE).
 */
final class RequestTraceController extends Controller
{
    public function show(TraceQueryData $query, #[CurrentTeam] Team $team, #[CurrentUser] User $user, DecodeTrace $decode, RequestTraceView $view): Response
    {
        Gate::authorize('view', $team);
        $input = trim($query->t ?? '');

        if ($input === '') {
            return Inertia::render('trace', ['team' => ['name' => $team->name]]);
        }

        $decoded = $decode->handle($input, $team);
        $trace = $decoded instanceof DecodedTrace ? $view->handle($decoded, $team, $user) : null;

        return Inertia::render('trace', [
            'team' => ['name' => $team->name],
            'input' => mb_substr($input, 0, TraceCodec::MAX_INPUT),
            ...($trace instanceof RequestTraceData ? ['trace' => $trace] : ['error' => DecodeTrace::NOT_READABLE]),
        ]);
    }
}

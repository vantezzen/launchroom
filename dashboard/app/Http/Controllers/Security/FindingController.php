<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\DismissFinding;
use App\Actions\Security\ReopenFinding;
use App\Actions\Security\StartInvestigation;
use App\Data\Security\DismissFindingData;
use App\Data\Security\FindingDetailData;
use App\Http\Controllers\Controller;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Models\User;
use App\Queries\Security\FindingDetail;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A finding (docs/22 §9.3): its page (also opened as a sheet from the
 * Security page, from `…/-/data`), "Not affected…", "Reopen" and
 * "Investigate again". `{finding}` is bound through the user's teams.
 */
final class FindingController extends Controller
{
    public function show(SecurityFinding $finding, #[CurrentUser] User $user, FindingDetail $detail): Response
    {
        Gate::authorize('view', $finding);

        return Inertia::render('security/finding', [
            'detail' => $detail->handle($finding, $user),
        ]);
    }

    public function data(SecurityFinding $finding, #[CurrentUser] User $user, FindingDetail $detail): FindingDetailData
    {
        Gate::authorize('view', $finding);

        return $detail->handle($finding, $user);
    }

    public function dismiss(Request $request, SecurityFinding $finding, DismissFindingData $data, #[CurrentUser] User $user, DismissFinding $dismiss): JsonResponse
    {
        Gate::authorize('dismiss', $finding);
        $dismiss->handle($finding, $data, $user, $this->auditActor($request, $user));

        return new JsonResponse(['status' => 'dismissed']);
    }

    public function reopen(Request $request, SecurityFinding $finding, #[CurrentUser] User $user, ReopenFinding $reopen): JsonResponse
    {
        Gate::authorize('reopen', $finding);
        $reopen->handle($finding, $this->auditActor($request, $user));

        return new JsonResponse(['status' => 'open']);
    }

    public function investigate(Request $request, SecurityFinding $finding, #[CurrentUser] User $user, StartInvestigation $start): JsonResponse
    {
        Gate::authorize('view', $finding);
        $instance = $finding->serviceInstance;
        Gate::authorize('create', [SecurityInvestigation::class, $instance]);

        $investigation = $start->handle($instance, $finding, $user, actor: $this->auditActor($request, $user));

        return new JsonResponse(['investigation' => $investigation?->public_id], 202);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\DismissFinding;
use App\Actions\Security\DismissFindings;
use App\Actions\Security\ReopenFinding;
use App\Actions\Security\StartInvestigation;
use App\Data\Security\DismissFindingData;
use App\Data\Security\DismissFindingsData;
use App\Data\Security\FindingDetailData;
use App\Data\Security\FixGroupData;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Models\Team;
use App\Models\User;
use App\Queries\Security\FindingDetail;
use App\Queries\Security\FixGroups;
use App\Queries\Security\SecurityServiceRefs;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A finding (docs/22 §9.3): its page (also opened as a sheet from the
 * Security page, from `…/-/data`), "Not affected…" (one finding or a fix
 * group's), "Reopen" and "Investigate again". `{finding}` is bound through
 * the user's teams.
 */
final class FindingController extends Controller
{
    public function __construct(
        private readonly FixGroups $fixGroups,
    ) {}

    public function show(SecurityFinding $finding, #[CurrentUser] User $user, FindingDetail $detail): Response
    {
        Gate::authorize('view', $finding);

        return Inertia::render('security/finding', [
            'detail' => $detail->handle($finding, $user),
            'siblings' => $this->siblings($finding),
        ]);
    }

    /**
     * Previous and next thing to fix in the Security page's "Action needed"
     * order (docs/22 §9.2, FixGroups): one step per package, linking to the
     * group's lead finding. Within the team.
     *
     * @return array{previous: string|null, next: string|null, position: int|null, total: int}
     */
    private function siblings(SecurityFinding $finding): array
    {
        $groups = $this->fixGroups->handle($finding->team_id, new SecurityServiceRefs);
        $index = null;

        foreach ($groups as $i => $group) {
            foreach ($group->services as $service) {
                if (in_array($finding->public_id, $service->findings, true)) {
                    $index = $i;
                }
            }
        }

        $link = static fn (?FixGroupData $group): ?string => $group instanceof FixGroupData ? route('security.findings.show', ['finding' => $group->lead], false) : null;

        return [
            'previous' => $index === null ? null : $link($groups[$index - 1] ?? null),
            'next' => $index === null ? null : $link($groups[$index + 1] ?? null),
            'position' => $index === null ? null : $index + 1,
            'total' => count($groups),
        ];
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

    /**
     * "Not affected…" for a fix group (docs/22 §5.5): the findings of the
     * services it applies to, resolved inside the current team.
     */
    public function dismissMany(Request $request, DismissFindingsData $data, #[CurrentUser] User $user, #[CurrentTeam] Team $team, DismissFindings $dismiss): JsonResponse
    {
        Gate::authorize('manage', [SecurityFinding::class, $team]);
        $count = $dismiss->handle($team, $data, $user, $this->auditActor($request, $user));

        return new JsonResponse(['status' => 'dismissed', 'count' => $count]);
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

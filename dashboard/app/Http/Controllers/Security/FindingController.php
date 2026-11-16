<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\DismissFinding;
use App\Actions\Security\ReopenFinding;
use App\Actions\Security\StartInvestigation;
use App\Data\Security\DismissFindingData;
use App\Data\Security\FindingDetailData;
use App\Enums\Security\FindingLevel;
use App\Enums\Security\SecurityFindingStatus;
use App\Http\Controllers\Controller;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Models\User;
use App\Queries\Security\FindingDetail;
use App\Support\Links;
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
            'siblings' => $this->siblings($finding),
        ]);
    }

    /**
     * Previous and next vulnerability in the Security page's "Action needed"
     * order (docs/22 §9.2): open findings grouped by advisory, groups by
     * known exploited, severity, number of services, then advisory id, as
     * `groupActionNeeded` does; a group links to its first finding (known
     * exploited, severity, oldest first). Within the team.
     *
     * @return array{previous: string|null, next: string|null, position: int|null, total: int}
     */
    private function siblings(SecurityFinding $finding): array
    {
        $open = SecurityFinding::query()
            ->where('team_id', $finding->team_id)
            ->where('status', SecurityFindingStatus::Open->value)
            ->where('level', FindingLevel::ActionNeeded->value)
            ->get(['id', 'public_id', 'group_key', 'known_exploited', 'severity', 'first_seen_at'])
            ->sort(static fn (SecurityFinding $a, SecurityFinding $b): int => [$b->known_exploited, $b->severity->rank(), $a->first_seen_at->getTimestamp()]
                <=> [$a->known_exploited, $a->severity->rank(), $b->first_seen_at->getTimestamp()]);

        /** @var array<string, array{key: string, exploited: bool, severity: int, count: int, lead: SecurityFinding, ids: list<int>}> $groups */
        $groups = [];

        // `$open` is already in lead order: the first of each advisory leads.
        foreach ($open as $item) {
            $group = $groups[$item->group_key] ?? ['key' => $item->group_key, 'exploited' => false, 'severity' => 0, 'count' => 0, 'lead' => $item, 'ids' => []];
            $group['exploited'] = $group['exploited'] || $item->known_exploited;
            $group['severity'] = max($group['severity'], $item->severity->rank());
            $group['count']++;
            $group['ids'][] = $item->id;
            $groups[$item->group_key] = $group;
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => [$b['exploited'], $b['severity'], $b['count'], $a['key']] <=> [$a['exploited'], $a['severity'], $a['count'], $b['key']]);
        $index = null;

        foreach ($groups as $i => $group) {
            if (in_array($finding->id, $group['ids'], true)) {
                $index = $i;
            }
        }

        if ($index === null) {
            return ['previous' => null, 'next' => null, 'position' => null, 'total' => count($groups)];
        }

        $previous = $groups[$index - 1] ?? null;
        $next = $groups[$index + 1] ?? null;

        return [
            'previous' => $previous === null ? null : Links::finding($previous['lead']),
            'next' => $next === null ? null : Links::finding($next['lead']),
            'position' => $index + 1,
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

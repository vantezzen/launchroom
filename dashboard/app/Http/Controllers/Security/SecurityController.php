<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\CheckNow;
use App\Data\Api\AnalyticsQuery;
use App\Data\Security\InventorySearchData;
use App\Data\Security\SecurityPageData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Security\SecurityFinding;
use App\Models\User;
use App\Queries\Security\InventorySearch;
use App\Queries\Security\SecurityOverview;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/security` (sidebar; docs/22 §9): the Security page of the current
 * team. The numbers come from `GET /security/-/data?range=` (JSON,
 * refetched every minute); the inventory search from
 * `GET /security/inventory/-/search?q=`; `/security/inventory?q=` opens the
 * page with a search. Every member sees it; "Check now" is for owners and
 * admins.
 */
final class SecurityController extends Controller
{
    public function show(Request $request, #[CurrentUser] User $user): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [SecurityFinding::class, $team]);

        $service = $request->query('service');

        return Inertia::render('security', [
            'team' => ['name' => $team->name],
            'inventoryQuery' => null,
            'service' => is_string($service) && preg_match('/^svc_[0-9A-HJKMNP-TV-Z]{26}$/D', $service) === 1 ? $service : null,
        ]);
    }

    public function inventory(Request $request, #[CurrentUser] User $user): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [SecurityFinding::class, $team]);
        $query = $request->query('q');

        return Inertia::render('security', [
            'team' => ['name' => $team->name],
            'inventoryQuery' => is_string($query) ? mb_substr($query, 0, 200) : '',
            'service' => null,
        ]);
    }

    public function data(AnalyticsQuery $query, #[CurrentUser] User $user, SecurityOverview $overview): SecurityPageData
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [SecurityFinding::class, $team]);

        return $overview->handle($team, $user, $query->range);
    }

    public function search(Request $request, #[CurrentUser] User $user, InventorySearch $search): InventorySearchData
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [SecurityFinding::class, $team]);
        $query = $request->query('q');

        return $search->handle($team, is_string($query) ? $query : '');
    }

    public function check(#[CurrentUser] User $user, CheckNow $check): JsonResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('checkNow', [SecurityFinding::class, $team]);
        $check->handle();

        return new JsonResponse(['queued' => true], 202);
    }
}

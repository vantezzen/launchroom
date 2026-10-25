<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\AnalyticsQuery;
use App\Data\Api\InventoryQuery;
use App\Data\Api\ListFindingsQuery;
use App\Data\Api\ServiceInventoryResource;
use App\Data\Api\WafSummaryResource;
use App\Data\Security\FindingCardData;
use App\Data\Security\FindingDetailData;
use App\Data\Security\InventorySearchData;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Api\Cursor;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Security\SecurityFinding;
use App\Models\Service;
use App\Models\User;
use App\Queries\Security\FindingDetail;
use App\Queries\Security\InventorySearch;
use App\Queries\Security\SecurityOverview;
use App\Queries\Security\SecurityServiceRefs;
use App\Queries\Security\ServiceInventory;
use App\Queries\Security\WafOverview;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The security center through the API (docs/22 §11, docs/16 §2): findings,
 * the inventory and firewall totals, read-only (ability `read`). Never
 * process commands or client networks.
 */
final class SecurityController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'listSecurityFindings', summary: 'Findings of the token\'s team', tag: 'Security', response: FindingCardData::class, list: true, query: ListFindingsQuery::class, description: 'Open findings by default, "Action needed" first. `level` is `action_needed` or `worth_knowing`; `reasons` are the codes of the rules that kept a finding from "Action needed".', paginated: true)]
    public function findings(ListFindingsQuery $query, #[CurrentUser] User $user, SecurityOverview $overview): JsonResponse
    {
        $team = $this->team($user);
        Gate::authorize('viewAny', [SecurityFinding::class, $team]);

        $findings = SecurityFinding::query()
            ->where('team_id', $team->id)
            ->where('status', $query->status)
            ->when($query->level !== null, static fn ($q) => $q->where('level', $query->level))
            ->when($query->project !== null, static fn ($q) => $q->whereHas('project', static fn (Builder $p) => $p->where('public_id', $query->project)))
            ->when($query->service !== null, static fn ($q) => $q->whereHas('serviceInstance.service', static fn (Builder $s) => $s->where('public_id', $query->service)))
            ->when(Cursor::decode($query->cursor), static fn ($q, int $cursor) => $q->before($cursor))
            ->with('investigation')
            ->orderByDesc('id')
            ->limit($query->limit + 1)
            ->get();

        $page = $findings->take($query->limit);
        $refs = new SecurityServiceRefs;
        $refs->load($team->id, array_values(array_map(static fn (SecurityFinding $f): int => $f->service_instance_id, $page->all())));
        $titles = $overview->titles(SecurityOverview::advisoryIds($page->all()));
        $items = array_values(array_filter($page->map(static fn (SecurityFinding $f): ?FindingCardData => $overview->card($f, $refs, $titles, $f->investigation))->all()));

        return ApiResponse::list($items, $findings->count() > $query->limit ? Cursor::encode((int) $page->last()?->id) : null);
    }

    #[ApiOperation(id: 'getSecurityFinding', summary: 'One finding with its advisory and latest investigation report', tag: 'Security', response: FindingDetailData::class, description: 'The report has sentences and next steps, never process commands or client networks.')]
    public function finding(SecurityFinding $finding, #[CurrentUser] User $user, FindingDetail $detail): JsonResponse
    {
        abort_unless($finding->team_id === $this->team($user)->id, 404);
        Gate::authorize('view', $finding);

        return ApiResponse::item($detail->handle($finding, $user, networks: false));
    }

    #[ApiOperation(id: 'searchSecurityInventory', summary: 'Packages and runtimes matching a query, with versions and where they run', tag: 'Security', response: InventorySearchData::class, query: InventoryQuery::class)]
    public function inventory(InventoryQuery $query, #[CurrentUser] User $user, InventorySearch $search): JsonResponse
    {
        $team = $this->team($user);
        Gate::authorize('viewAny', [SecurityFinding::class, $team]);

        return ApiResponse::item($search->handle($team, $query->q));
    }

    #[ApiOperation(id: 'getServiceInventory', summary: 'The components of a service\'s live image', tag: 'Security', response: ServiceInventoryResource::class)]
    public function serviceInventory(Environment $environment, Service $service, ServiceInventory $inventory): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('view', $instance);
        $data = $inventory->handle($instance);
        abort_if(! $data instanceof ServiceInventoryResource, 404, 'This service has no inventory yet.');

        return ApiResponse::item($data);
    }

    #[ApiOperation(id: 'getSecurityWaf', summary: 'Web firewall totals per category and service', tag: 'Security', response: WafSummaryResource::class, query: AnalyticsQuery::class)]
    public function waf(AnalyticsQuery $query, #[CurrentUser] User $user, WafOverview $waf): JsonResponse
    {
        $team = $this->team($user);
        Gate::authorize('viewAny', [SecurityFinding::class, $team]);
        $overview = $waf->handle($team, $query->range, new SecurityServiceRefs, false);

        return ApiResponse::item(new WafSummaryResource($overview->range, $overview->blocked, $overview->detected, $overview->categories, $overview->services));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\Analytics\AnalyticsData;
use App\Data\Analytics\TeamAnalyticsData;
use App\Data\Api\AnalyticsQuery;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectAnalyticsController;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Queries\RequestAnalytics;
use App\Queries\TeamAnalytics;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Request analytics (docs/15 §5.4, docs/16 §2): the same numbers as the
 * Analytics tab, read-only, ability `read`.
 */
final class AnalyticsController extends Controller
{
    use ScopesApiRoutes;

    private const string DESCRIPTION = 'Requests per hour (per day for `30d`) by status class, totals against the previous period, the split by server and service, and the top paths (ids as `:id`, no query strings), referrer hosts and client classes. No addresses or user agents are stored.';

    #[ApiOperation(id: 'getTeamAnalytics', summary: 'Request analytics of every project of the token\'s team', tag: 'Analytics', response: TeamAnalyticsData::class, query: AnalyticsQuery::class, description: 'Requests per project and hour (per day for `30d`) over all environments, totals against the previous period, the split by server and the top paths across projects.')]
    public function overview(AnalyticsQuery $query, #[CurrentUser] User $user, TeamAnalytics $analytics): JsonResponse
    {
        $team = $this->team($user);
        Gate::authorize('viewAny', [Project::class, $team]);

        return ApiResponse::item($analytics->handle($team, $query->range));
    }

    #[ApiOperation(id: 'getEnvironmentAnalytics', summary: 'Request analytics of an environment', tag: 'Analytics', response: AnalyticsData::class, query: AnalyticsQuery::class, description: self::DESCRIPTION)]
    public function environment(Environment $environment, AnalyticsQuery $query, RequestAnalytics $analytics): JsonResponse
    {
        Gate::authorize('view', $environment);

        [$instances, $names] = ProjectAnalyticsController::selection($environment, null);

        return ApiResponse::item($analytics->handle($instances, $names, $query->range));
    }

    #[ApiOperation(id: 'getServiceAnalytics', summary: 'Request analytics of one service in an environment', tag: 'Analytics', response: AnalyticsData::class, query: AnalyticsQuery::class, description: self::DESCRIPTION)]
    public function service(Environment $environment, Service $service, AnalyticsQuery $query, RequestAnalytics $analytics): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('view', $instance);

        return ApiResponse::item($analytics->handle([$instance->id], [$instance->id => ['id' => $service->public_id, 'name' => $service->name]], $query->range));
    }
}

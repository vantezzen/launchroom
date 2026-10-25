<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\AddWafExclusion;
use App\Actions\Security\RemoveWafExclusion;
use App\Actions\Security\SetWafSettings;
use App\Actions\Security\StartInvestigation;
use App\Data\Security\AddWafExclusionData;
use App\Data\Security\ServiceFirewallData;
use App\Data\Security\SetWafSettingsData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Security\SecurityInvestigation;
use App\Models\Security\WafExclusion;
use App\Models\Service;
use App\Models\User;
use App\Queries\Security\ServiceFirewall;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A service's web firewall (docs/22 §6, §9.6), in the service settings
 * and from the Security page: mode and paranoia (applied right away),
 * exceptions, and "Check this service" (an investigation). Changes need
 * `projects.manage`.
 */
final class ServiceFirewallController extends Controller
{
    public function show(Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, ServiceFirewall $firewall): ServiceFirewallData
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('viewAny', [WafExclusion::class, $instance]);

        return $firewall->handle($instance, $user);
    }

    public function update(Request $request, Project $project, Environment $environment, Service $service, SetWafSettingsData $data, #[CurrentUser] User $user, SetWafSettings $set): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('update', $instance);
        $instance = $set->handle($instance, $data, $this->auditActor($request, $user));

        return new JsonResponse(['mode' => $instance->waf_mode->value, 'paranoia' => $instance->waf_paranoia, 'inspectBodyKiB' => $instance->waf_inspect_body_kib]);
    }

    public function store(Request $request, Project $project, Environment $environment, Service $service, AddWafExclusionData $data, #[CurrentUser] User $user, AddWafExclusion $add): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('create', [WafExclusion::class, $instance]);
        $exclusion = $add->handle($instance, $data, $user, $this->auditActor($request, $user));

        return new JsonResponse(['id' => $exclusion->public_id], 201);
    }

    public function destroy(Request $request, Project $project, Environment $environment, Service $service, WafExclusion $wafExclusion, #[CurrentUser] User $user, RemoveWafExclusion $remove): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        abort_unless($wafExclusion->service_instance_id === $instance->id, 404);
        Gate::authorize('delete', $wafExclusion);
        $remove->handle($wafExclusion, $this->auditActor($request, $user));

        return new JsonResponse(null, 204);
    }

    public function investigate(Request $request, Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, StartInvestigation $start): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('create', [SecurityInvestigation::class, $instance]);
        $investigation = $start->handle($instance, null, $user, actor: $this->auditActor($request, $user));

        return new JsonResponse(['investigation' => $investigation?->public_id], 202);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Canvas\SaveCanvasLayout;
use App\Data\Canvas\CanvasLayoutData;
use App\Data\Canvas\ServicePanelData;
use App\Data\Deployments\ServiceDeploymentsData;
use App\Data\Previews\PreviewCanvasData;
use App\Data\Specs\EnvironmentSpec;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Enums\ServicePanelTab;
use App\Enums\ServiceSettingsSection;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Commands\CommandRun;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Security\SecurityFinding;
use App\Models\Service;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Queries\AppUpdates\ServiceUpdates;
use App\Queries\CanvasGraph;
use App\Queries\CronRunHistory;
use App\Queries\CurrentEnvironmentSpec;
use App\Queries\Previews\PreviewCanvas;
use App\Queries\ProjectShell;
use App\Queries\ServiceControls;
use App\Queries\ServiceDeployments;
use App\Queries\ServiceOrigin;
use App\Queries\ServiceRuntimeFacts;
use App\Queries\ServiceSettings;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project canvas of an environment (`/p/{project}/{env}`) and the
 * route-backed service panel over it (`…/s/{service}/{tab}`, docs/08 §2).
 */
final class CanvasController extends Controller
{
    public function __construct(
        private readonly DatabaseEngines $engines,
        private readonly ServiceControls $controls,
        private readonly CurrentEnvironmentSpec $currentSpec,
    ) {}

    public function show(Project $project, Environment $environment, CanvasGraph $graph): Response
    {
        Gate::authorize('view', $environment);
        RouteScope::environment($project, $environment);

        return $this->render($project, $environment, $graph, null);
    }

    public function service(Request $request, Project $project, Environment $environment, Service $service, CanvasGraph $graph, ServiceSettings $settings, #[CurrentUser] User $user, ServicePanelTab $tab = ServicePanelTab::Deployments, ?ServiceSettingsSection $section = null): Response|RedirectResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        Gate::authorize('view', $instance);

        // `?tab=variables` / `?tab=backups` from older links (docs/08 §16).
        $legacy = is_string($request->query('tab')) ? ServiceSettingsSection::tryFrom($request->query('tab')) : null;

        if (! $section instanceof ServiceSettingsSection && $legacy !== null && in_array($legacy, ServiceSettingsSection::formerTabs(), true)) {
            return $this->toSection($request, $project, $environment, $service, $legacy, ['tab']);
        }

        abort_if($section === ServiceSettingsSection::Backups && $service->kind !== ServiceKind::Database && ! $instance->volumes()->exists(), 404);
        $canConsole = Gate::allows('viewAny', [InteractiveSession::class, $instance, InteractiveSessionKind::Console]);
        $canData = $service->kind === ServiceKind::Database && Gate::allows('viewAny', [InteractiveSession::class, $instance, InteractiveSessionKind::Data]);
        $canCommands = $service->kind !== ServiceKind::Database && Gate::allows('viewAny', [CommandRun::class, $instance]);
        abort_if(($tab === ServicePanelTab::Console && ! $canConsole) || ($tab === ServicePanelTab::Data && ! $canData) || ($tab === ServicePanelTab::Commands && ! $canCommands), 404);

        // One snapshot of the environment's intent for the whole request: the
        // controls, the settings and the canvas graph each diff against it.
        // Taken only when one of them is asked for.
        $snapshot = null;
        $spec = function () use (&$snapshot, $environment): EnvironmentSpec {
            return $snapshot ??= $this->currentSpec->get($environment);
        };

        // Lazy: realtime refreshes that ask for nodes and edges only don't build it.
        $panel = function () use ($project, $instance, $service, $tab, $section, $settings, $user, $canConsole, $canData, $canCommands, $spec): ServicePanelData {
            // The header chip (docs/22 §9.1): open "Action needed" findings of this instance.
            $findings = array_values(array_filter(SecurityFinding::query()
                ->where('team_id', $project->team_id)
                ->where('service_instance_id', $instance->id)
                ->actionNeeded()
                ->pluck('public_id')
                ->all(), is_string(...)));

            return new ServicePanelData(
                serviceId: $service->public_id,
                tab: $tab,
                settings: $tab === ServicePanelTab::Settings ? $settings->for($instance, $user, $spec()) : null,
                canUpdate: $instance->removed_at === null && Gate::allows('update', $instance),
                canDelete: $instance->removed_at === null && Gate::allows('delete', $instance),
                canConsole: $canConsole,
                canData: $canData,
                canCommands: $canCommands,
                section: $tab === ServicePanelTab::Settings ? $section : null,
                actionNeeded: count($findings),
                actionNeededFinding: count($findings) === 1 ? $findings[0] : null,
                controls: $this->controls->for($instance, $user, $spec()),
                origin: resolve(ServiceOrigin::class)->for($instance),
                runtime: resolve(ServiceRuntimeFacts::class)->for($instance),
                cronRuns: $service->kind === ServiceKind::Cron ? resolve(CronRunHistory::class)->for($instance, 5) : [],
                update: resolve(ServiceUpdates::class)->for($instance, $user, $spec()),
            );
        };

        // A deep link (or any full visit) to the Deployments tab arrives with
        // its list, so the panel draws complete from one request. Opening
        // the panel from the canvas reloads only `panel` and leaves this out:
        // the client fetches the list in parallel (docs/10 §3).
        $deployments = $tab === ServicePanelTab::Deployments
            ? static fn (): ServiceDeploymentsData => resolve(ServiceDeployments::class)->for($instance)
            : null;

        return $this->render($project, $environment, $graph, $panel, $spec, $deployments);
    }

    public function settingsSection(Request $request, Project $project, Environment $environment, Service $service, CanvasGraph $graph, ServiceSettings $settings, #[CurrentUser] User $user, ServiceSettingsSection $section): Response|RedirectResponse
    {
        Gate::authorize('view', RouteScope::instance($project, $environment, $service));

        return $this->service($request, $project, $environment, $service, $graph, $settings, $user, ServicePanelTab::Settings, $section);
    }

    /**
     * The former Variables and Backups tabs (`/s/{service}/variables`,
     * `/s/{service}/backups`): settings pages now. Their links live on in
     * alerts, notifications and bookmarks, so they redirect with the query
     * (`?add=NAME&value=`, `?before=`).
     */
    public function movedTab(Request $request, Project $project, Environment $environment, Service $service, ServiceSettingsSection $tab): RedirectResponse
    {
        Gate::authorize('view', RouteScope::instance($project, $environment, $service));

        return $this->toSection($request, $project, $environment, $service, $tab);
    }

    /**
     * @param  list<string>  $drop  query keys not to carry over
     */
    private function toSection(Request $request, Project $project, Environment $environment, Service $service, ServiceSettingsSection $section, array $drop = []): RedirectResponse
    {
        $query = array_diff_key($request->query(), array_flip($drop));
        $url = route('projects.services.section', [$project, $environment, $service, $section]);

        return redirect()->to($query === [] ? $url : $url.'?'.http_build_query($query), 301);
    }

    /**
     * Node positions are saved instantly (never staged).
     */
    public function layout(Project $project, Environment $environment, CanvasLayoutData $data, SaveCanvasLayout $saveLayout): HttpResponse
    {
        Gate::authorize('update', $project);
        RouteScope::environment($project, $environment);

        $saveLayout->handle($environment, $data);

        return response()->noContent();
    }

    /**
     * @param  (Closure(): ServicePanelData)|null  $panel
     * @param  (Closure(): EnvironmentSpec)|null  $spec
     * @param  (Closure(): ServiceDeploymentsData)|null  $deployments
     */
    private function render(Project $project, Environment $environment, CanvasGraph $graph, ?Closure $panel, ?Closure $spec = null, ?Closure $deployments = null): Response
    {
        $engines = $this->engines;
        // Built once, and only when asked for: opening a service or switching
        // tabs reloads just `panel` (panelVisitOptions), realtime refreshes
        // ask for nodes, edges and panel.
        $built = null;
        $canvas = static function () use ($graph, $environment, $spec, &$built): array {
            return $built ??= $graph->for($environment, $spec instanceof Closure ? $spec() : null);
        };

        return Inertia::render('projects/canvas', [
            ...ProjectShell::props($project, $environment),
            'nodes' => static fn (): array => $canvas()['nodes'],
            'edges' => static fn (): array => $canvas()['edges'],
            // App volumes as their own nodes, shared between services (D73).
            'volumes' => static fn (): array => $canvas()['volumes'],
            'panel' => $panel,
            'deployments' => $deployments,
            'parentCandidates' => fn (): array => $project->services()
                ->where('kind', 'app')
                ->whereHas('instances', fn (Builder $query) => $query->where('environment_id', $environment->id)->whereNull('removed_at'))
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(static fn (Service $service): array => ['id' => $service->public_id, 'name' => $service->name])
                ->all(),
            'isPreview' => $environment->kind === EnvironmentKind::Preview,
            // Pull request context + shared/cloned database badges (docs/06 §5).
            'preview' => static fn (): ?PreviewCanvasData => resolve(PreviewCanvas::class)->for($environment),
            'engines' => $engines->options(...),
        ]);
    }
}

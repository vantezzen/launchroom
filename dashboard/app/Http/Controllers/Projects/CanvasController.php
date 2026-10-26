<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Canvas\SaveCanvasLayout;
use App\Data\Canvas\CanvasLayoutData;
use App\Data\Canvas\DatabaseEngineOptionData;
use App\Data\Canvas\ServiceControlsData;
use App\Data\Canvas\ServicePanelData;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DatabaseEngine;
use App\Enums\EnvironmentKind;
use App\Enums\Security\FindingLevel;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\ServiceKind;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Security\SecurityFinding;
use App\Models\Service;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Queries\CanvasGraph;
use App\Queries\Previews\PreviewCanvas;
use App\Queries\ServiceSettings;
use App\Support\Services\StagedForService;
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
        private readonly StagedForService $staged,
    ) {}

    /** Service panel tabs, in display order (`console`/`data`: docs/08 §15, only with their permission). */
    public const array TABS = ['deployments', 'metrics', 'logs', 'console', 'data', 'settings'];

    /** Pages of the Settings tab (`/s/{service}/settings/{section}`, docs/08 §16; `backups`: databases and services with volumes). */
    public const array SETTINGS_SECTIONS = ['general', 'source', 'variables', 'build', 'deploy', 'schedule', 'domains', 'networking', 'firewall', 'scaling', 'volumes', 'backups', 'advanced', 'danger'];

    /** Former panel tabs that are settings pages now (docs/08 §16): old links redirect. */
    public const array MOVED_TABS = ['variables', 'backups'];

    public function show(Project $project, Environment $environment, CanvasGraph $graph): Response
    {
        Gate::authorize('view', $environment);
        ProjectScope::environment($project, $environment);

        return $this->render($project, $environment, $graph, null);
    }

    public function service(Request $request, Project $project, Environment $environment, Service $service, CanvasGraph $graph, ServiceSettings $settings, #[CurrentUser] User $user, string $tab = 'deployments', ?string $section = null): Response|RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('view', $instance);

        // `?tab=variables` / `?tab=backups` from older links (docs/08 §16).
        $legacy = $request->query('tab');

        if ($section === null && is_string($legacy) && in_array($legacy, self::MOVED_TABS, true)) {
            return $this->toSection($request, $project, $environment, $service, $legacy, ['tab']);
        }

        abort_if($section === 'backups' && $service->kind !== ServiceKind::Database && ! $instance->volumes()->exists(), 404);
        $canConsole = Gate::allows('viewAny', [InteractiveSession::class, $instance, InteractiveSessionKind::Console]);
        $canData = $service->kind === ServiceKind::Database && Gate::allows('viewAny', [InteractiveSession::class, $instance, InteractiveSessionKind::Data]);
        abort_if(($tab === 'console' && ! $canConsole) || ($tab === 'data' && ! $canData), 404);

        // The header chip (docs/22 §9.1): open "Action needed" findings of this instance.
        $findings = array_values(array_filter(SecurityFinding::query()
            ->where('team_id', $project->team_id)
            ->where('service_instance_id', $instance->id)
            ->where('level', FindingLevel::ActionNeeded->value)
            ->where('status', SecurityFindingStatus::Open->value)
            ->pluck('public_id')
            ->all(), is_string(...)));

        $panel = new ServicePanelData(
            serviceId: $service->public_id,
            tab: $tab,
            settings: $tab === 'settings' ? $settings->for($instance, $user) : null,
            canUpdate: $instance->removed_at === null && Gate::allows('update', $instance),
            canDelete: $instance->removed_at === null && Gate::allows('delete', $instance),
            canConsole: $canConsole,
            canData: $canData,
            section: $tab === 'settings' ? $section : null,
            actionNeeded: count($findings),
            actionNeededFinding: count($findings) === 1 ? $findings[0] : null,
            controls: ServiceControlsData::for($instance, $user, $this->staged),
        );

        return $this->render($project, $environment, $graph, $panel);
    }

    /**
     * A page of the Settings tab: the same panel, opened on that page.
     */
    public function settingsSection(Request $request, Project $project, Environment $environment, Service $service, CanvasGraph $graph, ServiceSettings $settings, #[CurrentUser] User $user, string $section): Response|RedirectResponse
    {
        Gate::authorize('view', ProjectScope::instance($project, $environment, $service));

        return $this->service($request, $project, $environment, $service, $graph, $settings, $user, 'settings', $section);
    }

    /**
     * The former Variables and Backups tabs (`/s/{service}/variables`,
     * `/s/{service}/backups`): settings pages now. Their links live on in
     * alerts, notifications and bookmarks, so they redirect with the query
     * (`?add=NAME&value=`, `?before=`).
     */
    public function movedTab(Request $request, Project $project, Environment $environment, Service $service, string $tab): RedirectResponse
    {
        Gate::authorize('view', ProjectScope::instance($project, $environment, $service));

        return $this->toSection($request, $project, $environment, $service, $tab);
    }

    /**
     * @param  list<string>  $drop  query keys not to carry over
     */
    private function toSection(Request $request, Project $project, Environment $environment, Service $service, string $section, array $drop = []): RedirectResponse
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
        ProjectScope::environment($project, $environment);

        $saveLayout->handle($environment, $data);

        return response()->noContent();
    }

    private function render(Project $project, Environment $environment, CanvasGraph $graph, ?ServicePanelData $panel): Response
    {
        $engines = $this->engines;
        $canvas = $graph->for($environment);

        return Inertia::render('projects/canvas', [
            ...ProjectScope::shellProps($project, $environment),
            'nodes' => $canvas['nodes'],
            'edges' => $canvas['edges'],
            'panel' => $panel,
            'parentCandidates' => fn (): array => $project->services()
                ->where('kind', 'app')
                ->whereHas('instances', fn (Builder $query) => $query->where('environment_id', $environment->id)->whereNull('removed_at'))
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(static fn (Service $service): array => ['id' => $service->public_id, 'name' => $service->name])
                ->all(),
            'isPreview' => $environment->kind === EnvironmentKind::Preview,
            // Pull request context + shared/cloned database badges (docs/06 §5).
            'preview' => resolve(PreviewCanvas::class)->for($environment),
            'engines' => fn (): array => array_map(static function (DatabaseEngine $engine) use ($engines): DatabaseEngineOptionData {
                $driver = $engines->for($engine);

                return new DatabaseEngineOptionData($engine, $engine->label(), $driver->versions(), $driver->defaultVersion(), $engine->tagline());
            }, DatabaseEngine::cases()),
        ]);
    }
}

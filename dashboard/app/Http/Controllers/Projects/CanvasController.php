<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Canvas\SaveCanvasLayout;
use App\Data\Canvas\CanvasLayoutData;
use App\Data\Canvas\DatabaseEngineOptionData;
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
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Database\Query\Builder;
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
    ) {}

    /** Service panel tabs built so far, in display order (`backups`: databases and services with volumes; `console`/`data`: docs/08 §15, only with their permission). */
    public const array TABS = ['deployments', 'variables', 'metrics', 'logs', 'console', 'data', 'backups', 'settings'];

    /** Pages of the Settings tab (`/s/{service}/settings/{section}`, docs/08 §16). */
    public const array SETTINGS_SECTIONS = ['general', 'source', 'build', 'deploy', 'schedule', 'domains', 'networking', 'firewall', 'scaling', 'volumes', 'advanced', 'danger'];

    public function show(Project $project, Environment $environment, CanvasGraph $graph): Response
    {
        Gate::authorize('view', $environment);
        ProjectScope::environment($project, $environment);

        return $this->render($project, $environment, $graph, null);
    }

    public function service(Project $project, Environment $environment, Service $service, CanvasGraph $graph, ServiceSettings $settings, #[CurrentUser] User $user, string $tab = 'deployments', ?string $section = null): Response
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('view', $instance);
        abort_if($tab === 'backups' && $service->kind !== ServiceKind::Database && ! $instance->volumes()->exists(), 404);
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
        );

        return $this->render($project, $environment, $graph, $panel);
    }

    /**
     * A page of the Settings tab: the same panel, opened on that page.
     */
    public function settingsSection(Project $project, Environment $environment, Service $service, CanvasGraph $graph, ServiceSettings $settings, #[CurrentUser] User $user, string $section): Response
    {
        Gate::authorize('view', ProjectScope::instance($project, $environment, $service));

        return $this->service($project, $environment, $service, $graph, $settings, $user, 'settings', $section);
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Support\Links;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The former Metrics pages (D75): the project's Metrics tab and the service
 * panel's Metrics tab are views of the project's Analytics tab now. Their
 * links live on in bookmarks, notifications and history, so they redirect
 * with their query (`range`, `service`, `from`/`to`).
 */
final class MetricsRedirectController extends Controller
{
    /**
     * `/p/{project}/{environment}/metrics` → Analytics, Resources.
     */
    public function project(Request $request, Project $project, Environment $environment): RedirectResponse
    {
        ProjectScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        return $this->to(route('projects.resources-page', [$project, $environment], false), $request->query());
    }

    /**
     * `/p/{project}/{environment}/s/{service}/metrics` → Analytics filtered
     * to the service: Traffic when it has a domain, else Resources.
     */
    public function service(Request $request, Project $project, Environment $environment, Service $service): RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('view', $instance);

        $range = $request->query('range');

        return redirect()->to(Links::serviceAnalytics($instance, is_string($range) ? $range : null), 301);
    }

    /**
     * @param  array<array-key, mixed>  $query
     */
    private function to(string $url, array $query): RedirectResponse
    {
        return redirect()->to($query === [] ? $url : $url.'?'.http_build_query($query), 301);
    }
}

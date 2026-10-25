<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\ResetProjectIcon;
use App\Actions\Projects\SetProjectIcon;
use App\Data\Projects\ResetProjectIconData;
use App\Enums\ProjectIconMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectIconRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectIconStore;
use App\Support\Images\IconImage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project icons (docs/08 §13, docs/09 R32): serving the stored file to
 * members, uploading one, and resetting to automatic or none.
 */
final class ProjectIconController extends Controller
{
    /**
     * Streams the icon with a fixed content type, nosniff and a CSP that
     * forbids everything (sandboxed, no scripts, no loads), so even an SVG
     * opened directly can't do anything but draw.
     */
    public function show(Request $request, Project $project, ProjectIconStore $store): Response
    {
        Gate::authorize('view', $project);

        $bytes = $project->icon_type === null ? null : $store->read($project);

        abort_if($bytes === null || ! isset(IconImage::CONTENT_TYPES[$project->icon_type]), 404);

        $immutable = $request->query('v') === $project->icon_version;

        return response($bytes, 200, [
            'Content-Type' => IconImage::CONTENT_TYPES[$project->icon_type],
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="icon.'.$project->icon_type.'"',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => $immutable ? 'private, max-age=31536000, immutable' : 'private, no-cache',
        ]);
    }

    public function store(ProjectIconRequest $request, Project $project, #[CurrentUser] User $user, SetProjectIcon $setIcon): RedirectResponse
    {
        Gate::authorize('update', $project);

        $setIcon->handle($project, $request->iconBytes(), $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Icon updated.']);

        return back();
    }

    public function destroy(Request $request, Project $project, ResetProjectIconData $data, #[CurrentUser] User $user, ResetProjectIcon $resetIcon): RedirectResponse
    {
        Gate::authorize('update', $project);

        $mode = $data->mode;

        $resetIcon->handle($project, $mode, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => $mode === ProjectIconMode::Automatic
            ? 'Icon reset. launchroom uses your app’s favicon if it finds one.'
            : 'Icon removed.']);

        return back();
    }
}

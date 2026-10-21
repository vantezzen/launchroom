<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\ConfigAsCode\ExportEnvironmentConfig;
use App\Actions\ConfigAsCode\SyncConfigFile;
use App\Actions\ConfigAsCode\UpdateConfigFileSettings;
use App\Data\ConfigAsCode\ConfigFileSettingsData;
use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Config as code in the dashboard (docs/18): where the file is read from,
 * "Check now", and downloading an environment as launchroom.json.
 */
final class ConfigFileController extends Controller
{
    public function update(Request $request, Project $project, ConfigFileSettingsData $data, #[CurrentUser] User $user, UpdateConfigFileSettings $update): RedirectResponse
    {
        Gate::authorize('update', $project);

        $update->handle($project, $data, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => $data->enabled ? "launchroom will read {$data->path} on the next push." : 'launchroom.json is no longer read.']);

        return back();
    }

    public function sync(Request $request, Project $project, Environment $environment, #[CurrentUser] User $user, SyncConfigFile $sync): RedirectResponse
    {
        $environment = ProjectScope::environment($project, $environment);
        Gate::authorize('update', $environment);

        $outcome = $sync->handle($environment, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id));

        Inertia::flash('toast', match ($outcome->status) {
            ConfigFileStatus::Invalid => ['type' => 'error', 'message' => $outcome->summary],
            ConfigFileStatus::Missing => ['type' => 'info', 'message' => "There is no {$outcome->record->path} on that branch yet."],
            ConfigFileStatus::Unchanged => ['type' => 'success', 'message' => "{$environment->name} already matches {$outcome->record->path}."],
            default => ['type' => 'success', 'message' => "{$outcome->record->path} changes are staged in {$environment->name}. Review and deploy them."],
        });

        return back();
    }

    public function download(Request $request, Project $project, Environment $environment, #[CurrentUser] User $user, ExportEnvironmentConfig $export): Response
    {
        $environment = ProjectScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        $file = $export->handle($environment, null, $this->auditActor($request, $user));

        return response($file->content, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.basename($file->path).'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

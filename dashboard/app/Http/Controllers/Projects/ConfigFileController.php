<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\ConfigAsCode\ExportEnvironmentConfig;
use App\Actions\ConfigAsCode\SyncConfigFile;
use App\Actions\ConfigAsCode\UpdateConfigFileSettings;
use App\Data\ConfigAsCode\ConfigFileSettingsData;
use App\Data\Inertia\ToastData;
use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Toast::success($data->enabled ? "launchroom will read {$data->path} on the next push." : 'launchroom.json is no longer read.');

        return back();
    }

    public function sync(Request $request, Project $project, Environment $environment, #[CurrentUser] User $user, SyncConfigFile $sync): RedirectResponse
    {
        $environment = RouteScope::environment($project, $environment);
        Gate::authorize('update', $environment);

        $outcome = $sync->handle($environment, $this->auditActor($request, $user));

        Toast::show(match ($outcome->status) {
            ConfigFileStatus::Invalid => new ToastData(ToastType::Error, $outcome->summary),
            ConfigFileStatus::Missing => new ToastData(ToastType::Info, "There is no {$outcome->record->path} on that branch yet."),
            ConfigFileStatus::Unchanged => new ToastData(ToastType::Success, "{$environment->name} already matches {$outcome->record->path}."),
            default => new ToastData(ToastType::Success, "{$outcome->record->path} changes are staged in {$environment->name}. Review and apply them."),
        });

        return back();
    }

    public function download(Request $request, Project $project, Environment $environment, #[CurrentUser] User $user, ExportEnvironmentConfig $export): Response
    {
        $environment = RouteScope::environment($project, $environment);
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Previews;

use App\Actions\Previews\RotateBypassSecret;
use App\Actions\Previews\UpdatePreviewSettings;
use App\Actions\Previews\UpdatePreviewStrategy;
use App\Data\Previews\PreviewSettingsInput;
use App\Data\Previews\PreviewStrategyInput;
use App\Enums\EnvironmentKind;
use App\Http\Controllers\Controller;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Previews\PreviewGate;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Project settings → Previews (docs/06 §5): the settings, the per-service
 * "In previews" strategy and the CI bypass secret. The page itself is a
 * section of App\Http\Controllers\Projects\ProjectSettingsController.
 */
final class PreviewSettingsController extends Controller
{
    public function update(Request $request, Project $project, PreviewSettingsInput $input, #[CurrentUser] User $user, UpdatePreviewSettings $update): RedirectResponse
    {
        Gate::authorize('update', $project);

        $update->handle($project, $input, $user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Preview settings saved. They apply to the next pull request event.']);

        return back();
    }

    public function strategy(Request $request, Project $project, Service $service, PreviewStrategyInput $input, #[CurrentUser] User $user, PreviewGate $gate, UpdatePreviewStrategy $update): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($service->project_id === $project->id, 404);

        $base = $gate->baseEnvironment($project, PreviewSettings::forProject($project))
            ?? $project->environments()->where('kind', EnvironmentKind::Production->value)->first();
        $instance = $base === null ? null : ServiceInstance::query()->where('environment_id', $base->id)->where('service_id', $service->id)->first();

        abort_unless($instance instanceof ServiceInstance, 404);

        $update->handle($instance, $input, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$service->name}: saved for new previews."]);

        return back();
    }

    public function rotateBypass(Request $request, Project $project, #[CurrentUser] User $user, RotateBypassSecret $rotate): RedirectResponse
    {
        Gate::authorize('update', $project);

        $secret = $rotate->handle($project, $this->auditActor($request, $user));
        // Shown once; only a nonce is stored.
        Inertia::flash('previewBypassSecret', $secret);

        return back();
    }
}

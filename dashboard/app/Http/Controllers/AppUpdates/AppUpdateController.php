<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppUpdates;

use App\Actions\AppUpdates\CancelAppUpdate;
use App\Actions\AppUpdates\RequestUpdateCheck;
use App\Actions\AppUpdates\SaveAutoUpdate;
use App\Actions\AppUpdates\StartAppUpdate;
use App\Data\AppUpdates\AutoUpdateData;
use App\Data\AppUpdates\StartAppUpdateData;
use App\Enums\AppUpdates\AutoUpdateMode;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
use App\Models\AppUpdates\AppUpdate;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Support\Refusal;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * App updates of an image service in one environment (D106, docs/04 §14):
 * update to an available release, cancel while it backs up, check now and
 * the automatic-update setting.
 */
final class AppUpdateController extends Controller
{
    public function store(Request $request, Project $project, Environment $environment, Service $service, StartAppUpdateData $data, #[CurrentUser] User $user, StartAppUpdate $start): RedirectResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        Gate::authorize('create', [AppUpdate::class, $instance]);

        $update = Refusal::orThrow($start->handle($instance, $data, $user, $this->auditActor($request, $user)));

        Toast::success($update->backup_run_ids === []
            ? "Updating {$service->name} to {$update->to_version}."
            : "Backing up, then updating {$service->name} to {$update->to_version}.");

        return back();
    }

    public function cancel(Request $request, Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, CancelAppUpdate $cancel): RedirectResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        $update = AppUpdate::query()->where('service_instance_id', $instance->id)->open()->latest('id')->firstOrFail();
        Gate::authorize('cancel', $update);

        $cancel->handle($update, $this->auditActor($request, $user));

        Toast::success("Update cancelled. {$service->name} keeps running {$update->from_version}.");

        return back();
    }

    public function check(Project $project, Environment $environment, Service $service, RequestUpdateCheck $request): RedirectResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        Gate::authorize('create', [AppUpdate::class, $instance]);

        $request->handle($instance);

        Toast::info("Checking {$service->name} for updates.");

        return back();
    }

    public function auto(Request $request, Project $project, Environment $environment, Service $service, AutoUpdateData $data, #[CurrentUser] User $user, SaveAutoUpdate $save): RedirectResponse
    {
        $instance = RouteScope::editable(RouteScope::instance($project, $environment, $service));
        Gate::authorize('create', [AppUpdate::class, $instance]);

        $save->handle($instance, $data, $this->auditActor($request, $user));

        Toast::success($data->mode === AutoUpdateMode::Off
            ? "{$service->name} updates only when you say so."
            : "{$service->name} installs {$this->kinds($data->mode)} in its maintenance window.");

        return back();
    }

    private function kinds(AutoUpdateMode $mode): string
    {
        return $mode === AutoUpdateMode::Minor ? 'patch and minor updates' : 'patch updates';
    }
}

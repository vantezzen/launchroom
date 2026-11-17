<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Domains\RetryCertificate;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * "Retry now" for a domain whose certificate couldn't be issued (G16).
 */
final class DomainCertificateController extends Controller
{
    public function retry(Project $project, Environment $environment, Service $service, Domain $domain, #[CurrentUser] User $user, RetryCertificate $retryCertificate): RedirectResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        RouteScope::domain($instance, $domain);
        Gate::authorize('update', $domain);

        $retryCertificate->handle($domain, $user);
        Toast::flash(ToastType::Info, "Trying again for {$domain->hostname}. The result shows here in a minute.");

        return back();
    }
}

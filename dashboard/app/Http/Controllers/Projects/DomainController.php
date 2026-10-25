<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Domains\AddDomain;
use App\Actions\Domains\RemoveDomain;
use App\Actions\Domains\SetDomainHsts;
use App\Actions\Domains\SetWwwRedirect;
use App\Actions\Domains\VerifyDomain;
use App\Data\Canvas\AddDomainData;
use App\Http\Controllers\Controller;
use App\Jobs\Domains\VerifyDomainJob;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Custom domains of a service instance (Settings → Networking, docs/05 §5):
 * add (with its DNS records), live DNS verification, www redirect, remove.
 */
final class DomainController extends Controller
{
    public function store(Request $request, Project $project, Environment $environment, Service $service, AddDomainData $data, #[CurrentUser] User $user, AddDomain $addDomain): RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('create', [Domain::class, $instance]);

        $domain = $addDomain->handle($instance, $data->hostname, $this->auditActor($request, $user), $data->redirectTo);
        dispatch(new VerifyDomainJob($domain->id));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$domain->hostname} added. It goes live with a certificate once its DNS points at your server."]);

        return back();
    }

    /**
     * www redirect on/off (applies immediately like any domain change), or HSTS on/off
     * (immediate: a response header once the certificate is issued).
     */
    public function update(Request $request, Project $project, Environment $environment, Service $service, Domain $domain, #[CurrentUser] User $user, SetWwwRedirect $setWwwRedirect, SetDomainHsts $setDomainHsts): RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        ProjectScope::domain($instance, $domain);
        Gate::authorize('update', $domain);

        if ($request->has('hsts')) {
            $request->validate(['hsts' => ['required', 'boolean']]);
            $enabled = $request->boolean('hsts');
            $setDomainHsts->handle($domain, $enabled, $this->auditActor($request, $user));

            Inertia::flash('toast', ['type' => 'success', 'message' => $enabled
                ? "Browsers will be told to use HTTPS for {$domain->hostname} once its certificate is issued."
                : "{$domain->hostname} no longer sends HSTS. Browsers that saw it keep HTTPS until it expires."]);

            return back();
        }

        $request->validate(['wwwRedirect' => ['required', 'boolean']]);
        $enabled = $request->boolean('wwwRedirect');

        $setWwwRedirect->handle($domain, $enabled, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => $enabled
            ? "www.{$domain->hostname} now redirects to {$domain->hostname}."
            : "www.{$domain->hostname} no longer redirects."]);

        return back();
    }

    /**
     * Re-check the DNS now (also runs periodically).
     */
    public function verify(Request $request, Project $project, Environment $environment, Service $service, Domain $domain, VerifyDomain $verifyDomain): RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        ProjectScope::domain($instance, $domain);
        Gate::authorize('update', $domain);

        $result = $verifyDomain->handle($domain);

        // The guided add-domain flow re-checks by itself: no toast per check.
        if ($request->boolean('quiet')) {
            return back();
        }

        Inertia::flash('toast', ['type' => $result?->status === 'ok' ? 'success' : 'info', 'message' => $result->message ?? 'Only custom domains are checked.']);

        return back();
    }

    public function destroy(Request $request, Project $project, Environment $environment, Service $service, Domain $domain, #[CurrentUser] User $user, RemoveDomain $removeDomain): RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        ProjectScope::domain($instance, $domain);
        Gate::authorize('delete', $domain);

        $removeDomain->handle($domain, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$domain->hostname} removed. It no longer routes traffic."]);

        return back();
    }
}

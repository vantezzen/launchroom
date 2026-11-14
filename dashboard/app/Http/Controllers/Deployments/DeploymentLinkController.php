<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Support\Links;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `/d/{deployment}`: a deployment by its id alone, for places that know the
 * deployment but not its project and environment (a log line in a
 * project- or team-wide search, docs/08 §12). Redirects to the deployment
 * page after the same `view` check that page makes, so the short link
 * never reveals more than the page.
 */
final class DeploymentLinkController extends Controller
{
    public function __invoke(Deployment $deployment): RedirectResponse
    {
        Gate::authorize('view', $deployment);

        return redirect(Links::deployment($deployment));
    }
}

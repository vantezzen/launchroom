<?php

declare(strict_types=1);

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\RevealSecret;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\ScopesProjectRoutes;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Reveal a variable's value (docs/06 §3.1): secrets need the reveal
 * permission and are audited by RevealSecret. The value is the response
 * body only; never cached.
 */
final class RevealVariableController extends Controller
{
    use ScopesProjectRoutes;

    public function __invoke(Request $request, Project $project, Environment $environment, Variable $variable, #[CurrentUser] User $user, RevealSecret $reveal): JsonResponse
    {
        $this->scopeVariable($project, $environment, $variable);
        Gate::authorize('reveal', $variable);

        return response()
            ->json(['value' => $reveal->handle($variable, $this->auditActor($request, $user))])
            ->header('Cache-Control', 'no-store, private');
    }
}

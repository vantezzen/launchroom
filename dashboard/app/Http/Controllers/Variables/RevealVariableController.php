<?php

declare(strict_types=1);

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\RevealSecret;
use App\Enums\VariableType;
use App\Http\Auth\PasswordConfirmationThrottle;
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
 * permission, a recently confirmed password (docs/09 R10; confirmed inline
 * here because a fetch can't follow the confirm-password page) and are
 * audited by RevealSecret. The value is the response body only; never cached.
 */
final class RevealVariableController extends Controller
{
    use ScopesProjectRoutes;

    public function __invoke(Request $request, Project $project, Environment $environment, Variable $variable, #[CurrentUser] User $user, RevealSecret $reveal): JsonResponse
    {
        $this->scopeVariable($project, $environment, $variable);
        Gate::authorize('reveal', $variable);

        if ($variable->type === VariableType::Secret && ! $this->passwordRecentlyConfirmed($request)) {
            if (! $request->filled('password')) {
                return response()->json(['message' => 'Confirm your password to reveal this value.', 'requiresPassword' => true], 423);
            }

            // Failed attempts share the confirm-password page's budget (5/min per user and IP, review L4).
            PasswordConfirmationThrottle::confirm($request, $user, $request->string('password')->toString());
            $request->session()->put('auth.password_confirmed_at', time());
        }

        return response()
            ->json(['value' => $reveal->handle($variable, $this->auditActor($request, $user))])
            ->header('Cache-Control', 'no-store, private');
    }

    private function passwordRecentlyConfirmed(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at', 0);

        return is_numeric($confirmedAt) && (time() - (int) $confirmedAt) < config()->integer('auth.password_timeout', 10800);
    }
}

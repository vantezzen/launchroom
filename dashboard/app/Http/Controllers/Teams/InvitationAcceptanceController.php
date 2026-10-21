<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptInvitation;
use App\Actions\Teams\FindInvitation;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Data\Audit\AuditActor;
use App\Data\Teams\AcceptInvitationData;
use App\Data\Teams\InvitationPreviewData;
use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accepting an invitation is the only way to get an account besides the
 * first-run setup (docs/09 R10). Public routes: the token is the credential
 * (single use, expiring, bound to the e-mail), and they are rate-limited.
 */
final class InvitationAcceptanceController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function show(Request $request, string $token, FindInvitation $findInvitation): Response
    {
        $invitation = $findInvitation->handle($token);
        abort_if(! $invitation instanceof TeamInvitation, 404);

        $hasAccount = User::query()->whereRaw('lower(email) = ?', [Str::lower($invitation->email)])->exists();

        return Inertia::render('invitations/show', [
            'token' => $token,
            'invitation' => new InvitationPreviewData(
                $invitation->team->name,
                $invitation->email,
                $invitation->role,
                $invitation->inviter?->name,
                $hasAccount,
            ),
            'signedInAs' => $request->user()?->email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, string $token, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        $account = null;

        if (! $user instanceof User) {
            $validated = $request->validate([
                'name' => $this->nameRules(),
                'password' => $this->passwordRules(),
            ]);
            $account = AcceptInvitationData::from($validated);
        }

        $member = $acceptInvitation->handle(
            $token,
            $user,
            $account,
            $user instanceof User
                ? $this->auditActor($request, $user)
                : new AuditActor(ActorType::System, null, $request->ip(), $request->userAgent()),
        );

        if (! $user instanceof User) {
            Auth::login($member);
            $request->session()->regenerate();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'You joined the team.']);

        return to_route('dashboard');
    }
}

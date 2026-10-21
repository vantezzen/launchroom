<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Instance\CreateInstanceOwner;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Data\Audit\AuditActor;
use App\Data\Instance\CreateOwnerData;
use App\Data\Servers\ServerData;
use App\Enums\ActorType;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use App\Support\Crypto\OpaqueToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First run (docs/08 §3.1): only reachable while no user exists, and only
 * with the setup token printed by `launchroom:setup-token`. Public route,
 * guarded by the token and rate-limited.
 */
final class SetupController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function show(Request $request): Response
    {
        abort_if(User::query()->exists(), 404);

        $token = $request->query('token');
        $token = is_string($token) ? $token : '';
        // "Your launchroom is installed" with the server the installer already
        // connected (flows §1). Only for the holder of a valid setup token:
        // the page itself is public until the owner exists.
        $server = $this->tokenIsValid($token)
            ? Server::query()->where('is_control_plane', true)->orderBy('id')->first()
            : null;

        return Inertia::render('setup', [
            'token' => $token,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'server' => $server instanceof Server ? ServerData::fromServer($server) : null,
            'instanceUrl' => rtrim(config()->string('app.url'), '/'),
        ]);
    }

    private function tokenIsValid(string $token): bool
    {
        $settings = InstanceSettings::current();

        return $token !== ''
            && $settings->setup_token_hash !== null
            && $settings->setup_token_expires_at?->isFuture() === true
            && OpaqueToken::matches($token, $settings->setup_token_hash);
    }

    public function store(Request $request, CreateInstanceOwner $createOwner): RedirectResponse
    {
        abort_if(User::query()->exists(), 404);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = $createOwner->handle(
            CreateOwnerData::from($validated),
            new AuditActor(ActorType::System, null, $request->ip(), $request->userAgent()),
        );

        Auth::login($user);
        $request->session()->regenerate();

        // Two-factor is recommended from Get started, never forced (docs/09 R10).
        return to_route('dashboard');
    }
}

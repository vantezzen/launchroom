<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Instance\CreateInstanceOwner;
use App\Data\Audit\AuditActor;
use App\Data\Instance\CreateOwnerData;
use App\Data\Servers\ServerData;
use App\Enums\ActorType;
use App\Models\InstanceSettings;
use App\Models\Server;
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
    public function show(Request $request): Response
    {
        abort_if(InstanceSettings::current()->setupDone(), 404);

        $token = $request->query('token');
        $token = is_string($token) ? $token : '';
        // "Your launchroom is installed" with the server the installer already
        // connected (flows §1). Only for the holder of a valid setup token:
        // the page itself is public until the owner exists.
        $server = InstanceSettings::current()->acceptsSetupToken($token)
            ? Server::currentControlPlane()
            : null;

        return Inertia::render('setup', [
            'token' => $token,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'server' => $server instanceof Server ? ServerData::fromServer($server) : null,
            'instanceUrl' => rtrim(config()->string('app.url'), '/'),
        ]);
    }

    public function store(Request $request, CreateInstanceOwner $createOwner): RedirectResponse
    {
        abort_if(InstanceSettings::current()->setupDone(), 404);

        $user = $createOwner->handle(
            CreateOwnerData::validateAndCreate($request->all()),
            new AuditActor(ActorType::System, null, $request->ip(), $request->userAgent()),
        );

        Auth::login($user);
        $request->session()->regenerate();

        // Two-factor is recommended from Get started, never forced (docs/09 R10).
        return to_route('dashboard');
    }
}

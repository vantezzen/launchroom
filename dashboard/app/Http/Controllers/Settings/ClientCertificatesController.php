<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Security\AllowClientCertificateReissue;
use App\Actions\Security\SetClientCertificateMode;
use App\Enums\ClientCertificateMode;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Settings → Security → Server certificates (mTLS, D50, docs/03 §4.3),
 * instance owners only.
 */
final class ClientCertificatesController extends Controller
{
    public function update(Request $request, #[CurrentUser] User $user, SetClientCertificateMode $setMode): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $request->validate([
            'mode' => ['required', 'string', Rule::enum(ClientCertificateMode::class)],
            'force' => ['sometimes', 'boolean'],
        ]);

        $mode = ClientCertificateMode::from($request->string('mode')->toString());
        $setMode->handle($mode, $this->auditActor($request, $user), $request->boolean('force'));

        return back()->with('success', match ($mode) {
            ClientCertificateMode::Off => 'Client certificates are off.',
            ClientCertificateMode::Verify => 'Servers’ certificates are checked now. Nothing is refused yet.',
            ClientCertificateMode::Require => 'Client certificates are required now.',
        });
    }

    /**
     * Instance-wide (any team's server), so the server is looked up by the
     * action after the instance-owner check, not by the team-scoped binding.
     */
    public function reissue(Request $request, string $serverId, #[CurrentUser] User $user, AllowClientCertificateReissue $allow): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $server = $allow->handle($serverId, $this->auditActor($request, $user));

        return back()->with('success', "{$server->name} may get a new certificate in the next hour.");
    }
}

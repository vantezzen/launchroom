<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Security\AllowClientCertificateReissue;
use App\Actions\Security\SetClientCertificateMode;
use App\Data\Security\ClientCertificateModeData;
use App\Enums\ClientCertificateMode;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → Security → Server certificates (mTLS, D50, docs/03 §4.3),
 * instance owners only.
 */
final class ClientCertificatesController extends Controller
{
    public function update(Request $request, ClientCertificateModeData $data, #[CurrentUser] User $user, SetClientCertificateMode $setMode): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $mode = $data->mode;
        $setMode->handle($mode, $this->auditActor($request, $user), $data->force);

        Toast::success(match ($mode) {
            ClientCertificateMode::Off => 'Client certificates are off.',
            ClientCertificateMode::Verify => 'Server certificates are now checked, but no server is refused yet.',
            ClientCertificateMode::Require => 'Client certificates are required now.',
        });

        return back();
    }

    /**
     * `{serverId}` is any team's server, bound for instance owners only.
     */
    public function reissue(Request $request, Server $serverId, #[CurrentUser] User $user, AllowClientCertificateReissue $allow): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $server = $allow->handle($serverId, $this->auditActor($request, $user));

        Toast::success("{$server->name} may get a new certificate in the next hour.");

        return back();
    }
}

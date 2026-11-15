<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Ingress\SetDnsProvider;
use App\Actions\Ingress\SetSharedEdge;
use App\Actions\Ingress\VerifyDnsProvider;
use App\Data\Ingress\DnsProviderInput;
use App\Data\Ingress\SharedEdgeData;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Ingress & certificates actions (instance owners; the page is Settings → Network, docs/05 §4–5): the DNS
 * provider for DNS-01 wildcard certificates of the platform domain (token
 * stored envelope-encrypted, checked through the provider's API), shared
 * certificate storage and each edge's reported state.
 */
final class IngressController extends Controller
{
    /**
     * The page moved into Settings → Network; the old
     * URL keeps working.
     */
    public function show(): RedirectResponse
    {
        Gate::authorize('manageInstance');

        return to_route('network-settings.edit')->withFragment('public-traffic');
    }

    public function update(Request $request, DnsProviderInput $data, #[CurrentUser] User $user, SetDnsProvider $setDnsProvider, VerifyDnsProvider $verify): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $setDnsProvider->handle($data->provider, $data->secrets(), $this->auditActor($request, $user));
        $result = $verify->handle();

        Toast::flash($result->ok ? ToastType::Success : ToastType::Error, $result->message);

        return back();
    }

    public function destroy(Request $request, #[CurrentUser] User $user, SetDnsProvider $setDnsProvider): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $setDnsProvider->handle(null, [], $this->auditActor($request, $user));

        Toast::success('DNS provider removed. Generated domains get per-host certificates again.');

        return back();
    }

    /**
     * Admit an edge to the shared certificates, or remove it (instance
     * owners only, review M2).
     */
    public function sharedEdge(Request $request, Server $server, SharedEdgeData $data, #[CurrentUser] User $user, SetSharedEdge $setSharedEdge): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $shared = $data->shared;
        $setSharedEdge->handle($server, $shared, $user, $this->auditActor($request, $user));

        Toast::success($shared ? "{$server->name} now shares the certificates." : "{$server->name} no longer gets the shared certificates.");

        return back();
    }

    /**
     * "Verify" (read-only calls to the provider's API).
     */
    public function verify(VerifyDnsProvider $verify): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $result = $verify->handle();

        Toast::flash($result->ok ? ToastType::Success : ToastType::Error, $result->message);

        return back();
    }
}

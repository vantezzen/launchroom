<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Ingress\SetDnsProvider;
use App\Actions\Ingress\SetSharedEdge;
use App\Actions\Ingress\VerifyDnsProvider;
use App\Enums\DnsProvider;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

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

    /**
     * Set the DNS provider (and its credentials), then check them right away.
     * `token` is the single-token providers' field (kept for API callers).
     */
    public function update(Request $request, #[CurrentUser] User $user, SetDnsProvider $setDnsProvider, VerifyDnsProvider $verify): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $request->validate([
            'provider' => ['required', Rule::enum(DnsProvider::class)],
            'token' => ['nullable', 'string', 'max:512'],
            'credentials' => ['nullable', 'array:'.implode(',', $this->credentialKeys())],
            'credentials.*' => ['nullable', 'string', 'max:512'],
        ]);

        /** @var array<string, string|null> $credentials */
        $credentials = array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null, (array) $request->input('credentials', []));

        if ($request->filled('token')) {
            $credentials['token'] = $request->string('token')->toString();
        }

        $setDnsProvider->handle(
            DnsProvider::from($request->string('provider')->toString()),
            $credentials,
            $this->auditActor($request, $user),
        );
        $result = $verify->handle();

        Inertia::flash('toast', ['type' => $result->ok ? 'success' : 'error', 'message' => $result->message]);

        return back();
    }

    public function destroy(Request $request, #[CurrentUser] User $user, SetDnsProvider $setDnsProvider): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $setDnsProvider->handle(null, [], $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'DNS provider removed. Generated domains get per-host certificates again.']);

        return back();
    }

    /**
     * Admit an edge to the shared certificates, or remove it (instance
     * owners only, review M2).
     */
    public function sharedEdge(Request $request, Server $server, #[CurrentUser] User $user, SetSharedEdge $setSharedEdge): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $request->validate(['shared' => ['required', 'boolean']]);
        $shared = $request->boolean('shared');
        $setSharedEdge->handle($server, $shared, $user, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => $shared ? "{$server->name} now shares the certificates." : "{$server->name} no longer gets the shared certificates."]);

        return back();
    }

    /**
     * Every provider's credential field names.
     *
     * @return list<string>
     */
    private function credentialKeys(): array
    {
        return array_values(array_unique(array_merge(...array_map(static fn (DnsProvider $provider): array => $provider->keys(), DnsProvider::cases()))));
    }

    /**
     * "Verify" (read-only calls to the provider's API).
     */
    public function verify(VerifyDnsProvider $verify): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $result = $verify->handle();

        Inertia::flash('toast', ['type' => $result->ok ? 'success' : 'error', 'message' => $result->message]);

        return back();
    }
}

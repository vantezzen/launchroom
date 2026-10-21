<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Mesh\EnableMesh;
use App\Actions\Mesh\UseOwnTailscale;
use App\Data\Servers\UseOwnTailscaleData;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Mesh settings (docs/05 §2): instance owners turn the built-in mesh on
 * before a second server joins, or connect servers through their own
 * Tailscale network instead.
 */
final class MeshController extends Controller
{
    /**
     * The page moved into Settings → Network; the old
     * URL keeps working.
     */
    public function show(): RedirectResponse
    {
        Gate::authorize('manageInstance');

        return to_route('network-settings.edit')->withFragment('private-network');
    }

    public function enable(Request $request, #[CurrentUser] User $user, EnableMesh $enableMesh): RedirectResponse
    {
        Gate::authorize('manageInstance');

        try {
            $enableMesh->handle($this->auditActor($request, $user));
        } catch (HeadscaleUnavailable|DomainRuleViolation $e) {
            return back()->withErrors(['mesh' => $e->getMessage()]);
        }

        return back();
    }

    public function ownTailscale(Request $request, UseOwnTailscaleData $data, #[CurrentUser] User $user, UseOwnTailscale $useOwnTailscale): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $useOwnTailscale->handle($data, $this->auditActor($request, $user));

        return back();
    }
}

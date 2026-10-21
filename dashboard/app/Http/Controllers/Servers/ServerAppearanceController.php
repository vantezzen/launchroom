<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\UpdateServerAppearance;
use App\Data\Servers\UpdateServerAppearanceData;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * PATCH /servers/{server}/appearance: "Looks like" and "Location" overrides
 * (docs/design/direction.md §7.3). Same permission as placement settings.
 */
final class ServerAppearanceController extends Controller
{
    public function __invoke(Request $request, Server $server, UpdateServerAppearanceData $data, #[CurrentUser] User $user, UpdateServerAppearance $update): RedirectResponse
    {
        Gate::authorize('update', $server);

        $update->handle($server, $data, $this->auditActor($request, $user));

        return back();
    }
}

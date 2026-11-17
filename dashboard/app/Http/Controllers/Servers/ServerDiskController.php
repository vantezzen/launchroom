<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\FreeDiskSpace;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * "Free space now" in the server page's Disk section (G18).
 */
final class ServerDiskController extends Controller
{
    public function freeSpace(Server $server, #[CurrentUser] User $user, FreeDiskSpace $freeDiskSpace): RedirectResponse
    {
        Gate::authorize('update', $server);

        $freeDiskSpace->handle($server, $user);
        Toast::flash(ToastType::Info, "{$server->name} is cleaning up. The result shows here in a minute.");

        return back();
    }
}

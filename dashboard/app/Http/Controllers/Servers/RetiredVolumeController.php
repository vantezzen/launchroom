<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\ConfirmVolumeDeletion;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class RetiredVolumeController extends Controller
{
    public function confirm(Request $request, Server $server, string $volume, #[CurrentUser] User $user, ConfirmVolumeDeletion $confirm): RedirectResponse
    {
        Gate::authorize('confirmVolumeDeletion', $server);

        $confirm->handle($server, $volume, $user, $this->auditActor($request, $user));

        return back();
    }
}

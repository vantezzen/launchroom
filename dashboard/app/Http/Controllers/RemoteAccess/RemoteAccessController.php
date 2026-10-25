<?php

declare(strict_types=1);

namespace App\Http\Controllers\RemoteAccess;

use App\Actions\RemoteAccess\EndRemoteAccess;
use App\Http\Controllers\Controller;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\User;
use App\Services\RemoteAccess\RemoteAccessView;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * One temporary access: its details while it runs (polled by the panel)
 * and End now.
 */
final class RemoteAccessController extends Controller
{
    public function show(RemoteAccess $remoteAccess, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('view', $remoteAccess);

        return response()->json(RemoteAccessView::data($remoteAccess, $user))->header('Cache-Control', 'no-store, private');
    }

    public function end(RemoteAccess $remoteAccess, #[CurrentUser] User $user, EndRemoteAccess $end): JsonResponse
    {
        Gate::authorize('end', $remoteAccess);
        $end->handle($remoteAccess, $user);

        return response()->json(RemoteAccessView::data($remoteAccess->refresh(), $user))->header('Cache-Control', 'no-store, private');
    }
}

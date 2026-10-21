<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Http\Auth\PasswordConfirmationThrottle;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exports need a password confirmed in the last 15 minutes (docs/14 §12.5),
 * inline because a fetch can't follow the confirm-password page: the first
 * request answers 423 with `requiresPassword`, the next carries `password`.
 */
trait ConfirmsRecentPassword
{
    protected function passwordRequired(Request $request, User $user, string $message): ?JsonResponse
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at', 0);

        if (is_numeric($confirmedAt) && (time() - (int) $confirmedAt) < config()->integer('launchroom.transfers.password_window_seconds')) {
            return null;
        }

        if (! $request->filled('password')) {
            return response()->json(['message' => $message, 'requiresPassword' => true], 423);
        }

        // Failed attempts share the confirm-password page's budget.
        PasswordConfirmationThrottle::confirm($request, $user, $request->string('password')->toString());
        $request->session()->put('auth.password_confirmed_at', time());

        return null;
    }
}

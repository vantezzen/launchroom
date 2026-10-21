<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Actions\Backups\ConfirmRecoveryKit;
use App\Actions\Backups\DownloadRecoveryKit;
use App\Actions\Backups\SetRecoveryPassphrase;
use App\Http\Controllers\Controller;
use App\Models\Backups\BackupDestination;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recovery passphrase and kit (docs/14 §4). Instance owners only; setting
 * the passphrase and downloading the kit need a recent password.
 */
final class RecoveryKitController extends Controller
{
    public function passphrase(Request $request, #[CurrentUser] User $user, SetRecoveryPassphrase $set): RedirectResponse
    {
        Gate::authorize('manageInstance', BackupDestination::class);

        $request->validate([
            'passphrase' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);

        $set->handle($request->string('passphrase')->toString(), $this->auditActor($request, $user));

        return back()->with('success', 'Recovery passphrase saved. Download the recovery kit next.');
    }

    public function download(Request $request, #[CurrentUser] User $user, DownloadRecoveryKit $download): Response
    {
        Gate::authorize('manageInstance', BackupDestination::class);

        $document = $download->handle($this->auditActor($request, $user));

        return response($document, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="launchroom-recovery-kit.txt"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function confirm(Request $request, #[CurrentUser] User $user, ConfirmRecoveryKit $confirm): RedirectResponse
    {
        Gate::authorize('manageInstance', BackupDestination::class);

        $confirm->handle($this->auditActor($request, $user));

        return back()->with('success', 'Recovery kit confirmed.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Actions\Backups\ConfirmRecoveryKit;
use App\Actions\Backups\DownloadRecoveryKit;
use App\Actions\Backups\SetRecoveryPassphrase;
use App\Data\Backups\RecoveryPassphraseData;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\Backups\BackupDestination;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recovery passphrase and kit (docs/14 §4). Instance owners only.
 */
final class RecoveryKitController extends Controller
{
    public function passphrase(Request $request, RecoveryPassphraseData $data, #[CurrentUser] User $user, SetRecoveryPassphrase $set): RedirectResponse
    {
        Gate::authorize('manageInstance', BackupDestination::class);

        $set->handle($data->passphrase, $this->auditActor($request, $user));

        Toast::success('Recovery passphrase saved. Download the recovery kit next.');

        return back();
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

        Toast::success('Recovery kit confirmed.');

        return back();
    }
}

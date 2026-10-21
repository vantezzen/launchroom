<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Actions\Backups\StartControlPlaneBackup;
use App\Actions\Backups\UpdateBackupSchedule;
use App\Data\Backups\BackupScheduleInput;
use App\Enums\Backups\BackupReason;
use App\Http\Controllers\Controller;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BackupScheduleController extends Controller
{
    public function update(Request $request, BackupSchedule $backupSchedule, BackupScheduleInput $input, #[CurrentUser] User $user, UpdateBackupSchedule $update): RedirectResponse
    {
        Gate::authorize('update', $backupSchedule);

        $update->handle($backupSchedule, $input, $this->auditActor($request, $user));

        return back()->with('success', 'Schedule saved.');
    }

    /**
     * "Back up the control plane now".
     */
    public function runControlPlane(Request $request, #[CurrentUser] User $user, StartControlPlaneBackup $start): RedirectResponse
    {
        Gate::authorize('manageInstance', BackupDestination::class);

        $start->handle(BackupReason::Manual, $user, $this->auditActor($request, $user));

        return back()->with('success', 'Control-plane backup started.');
    }
}

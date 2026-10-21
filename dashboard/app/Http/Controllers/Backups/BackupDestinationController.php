<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Actions\Backups\CreateBackupDestination;
use App\Actions\Backups\DeleteBackupDestination;
use App\Actions\Backups\UpdateBackupDestination;
use App\Data\Backups\BackupDestinationInput;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Jobs\Backups\TestBackupDestinationJob;
use App\Models\Backups\BackupDestination;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Backup destinations (docs/14 §3). `scope=instance` targets the
 * control-plane destination (instance owners only).
 */
final class BackupDestinationController extends Controller
{
    public function store(Request $request, BackupDestinationInput $input, #[CurrentUser] User $user, CreateBackupDestination $create): RedirectResponse
    {
        $team = $request->input('scope') === 'instance' ? null : TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [BackupDestination::class, $team]);

        $create->handle($team, $input, $user, $this->auditActor($request, $user));

        return back()->with('success', 'Destination added. Testing the connection…');
    }

    public function update(Request $request, BackupDestination $backupDestination, BackupDestinationInput $input, #[CurrentUser] User $user, UpdateBackupDestination $update): RedirectResponse
    {
        Gate::authorize('update', $backupDestination);

        $update->handle($backupDestination, $input, $user, $this->auditActor($request, $user));

        return back()->with('success', 'Destination saved.');
    }

    public function destroy(Request $request, BackupDestination $backupDestination, #[CurrentUser] User $user, DeleteBackupDestination $delete): RedirectResponse
    {
        Gate::authorize('delete', $backupDestination);

        $delete->handle($backupDestination, $this->auditActor($request, $user));

        return back()->with('success', 'Destination removed. Its snapshots stay in the bucket.');
    }

    public function test(BackupDestination $backupDestination): RedirectResponse
    {
        Gate::authorize('update', $backupDestination);

        dispatch(new TestBackupDestinationJob($backupDestination->id));

        return back()->with('success', 'Testing the connection…');
    }
}

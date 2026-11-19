<?php

declare(strict_types=1);

namespace App\Console\Commands\Updates;

use App\Actions\Updates\CancelControlPlaneUpdate;
use App\Data\Audit\AuditActor;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Models\Updates\ControlPlaneUpdate;
use App\Support\Refusal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Cancels the running control-plane update from the server, for when the
 * dashboard's pages don't answer (docs/11 §4.3): `./launchroom doctor
 * --fix cancel-update` runs it with `--stalled`, so only an update without
 * a sign of life is touched. Refused once launchroomd took the task, like
 * the button.
 */
#[Signature('launchroom:updates:cancel {--stalled : Only when it has made no progress for a while}')]
#[Description('Cancel the running update before it changed anything')]
final class CancelUpdateCommand extends Command
{
    public function handle(CancelControlPlaneUpdate $cancel): int
    {
        $update = ControlPlaneUpdate::query()->with(['backupRun', 'daemonTask'])
            ->whereIn('status', ControlPlaneUpdateStatus::nonTerminal())
            ->latest('id')
            ->first();

        if (! $update instanceof ControlPlaneUpdate) {
            $this->line('No update is running.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('stalled') && ! $update->stalled()) {
            $this->line("The update to {$update->to_version} is making progress, so it was left running.");

            return self::SUCCESS;
        }

        $result = $cancel->handle($update, AuditActor::system());

        if ($result instanceof Refusal) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $this->line("The update to {$update->to_version} was cancelled. Nothing was changed. Try it again on Settings → Updates.");

        return self::SUCCESS;
    }
}

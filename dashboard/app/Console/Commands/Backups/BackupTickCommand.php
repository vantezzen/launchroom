<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Actions\Backups\RunBackupTick;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:backups:tick')]
#[Description('Start due backups, apply retention, follow backup/restore tasks (every minute)')]
final class BackupTickCommand extends Command
{
    public function handle(RunBackupTick $tick): int
    {
        $result = $tick->handle();

        if ($this->output->isVerbose()) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}

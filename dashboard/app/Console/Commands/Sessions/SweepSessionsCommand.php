<?php

declare(strict_types=1);

namespace App\Console\Commands\Sessions;

use App\Actions\Sessions\SweepSessions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:sessions:sweep')]
#[Description('End interactive sessions no server picked up and close finished ones')]
final class SweepSessionsCommand extends Command
{
    public function handle(SweepSessions $sweep): int
    {
        $sweep->handle();

        return self::SUCCESS;
    }
}

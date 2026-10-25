<?php

declare(strict_types=1);

namespace App\Console\Commands\RemoteAccess;

use App\Actions\RemoteAccess\SweepRemoteAccess;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:remote-access:sweep')]
#[Description('End temporary accesses no server picked up or past their limit, and close finished ones')]
final class SweepRemoteAccessCommand extends Command
{
    public function handle(SweepRemoteAccess $sweep): int
    {
        $sweep->handle();

        return self::SUCCESS;
    }
}

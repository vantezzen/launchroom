<?php

declare(strict_types=1);

namespace App\Console\Commands\Transfers;

use App\Actions\Transfers\SweepTransfers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:transfers:sweep')]
#[Description('Expire old uploads and downloads and delete their chunks (docs/14 §12.1)')]
final class SweepTransfersCommand extends Command
{
    public function handle(SweepTransfers $sweep): int
    {
        $result = $sweep->handle();
        $this->line("Expired {$result['expired']} transfers, removed {$result['removed']} chunk folders.");

        return self::SUCCESS;
    }
}

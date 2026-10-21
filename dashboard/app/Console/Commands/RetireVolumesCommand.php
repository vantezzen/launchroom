<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\NodeStateInvalidated;
use App\Services\Volumes\VolumeRetirements;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:volumes:retire')]
#[Description('Request deletion of retired volumes whose retention passed (docs/05 §1.1)')]
final class RetireVolumesCommand extends Command
{
    public function handle(VolumeRetirements $retirements): int
    {
        $servers = $retirements->requestDue();

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }

        $this->info(count($servers) === 0 ? 'No volumes due.' : 'Requested volume deletions on '.count($servers).' server(s).');

        return self::SUCCESS;
    }
}

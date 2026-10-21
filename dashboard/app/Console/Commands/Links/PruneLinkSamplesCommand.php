<?php

declare(strict_types=1);

namespace App\Console\Commands\Links;

use App\Actions\Servers\PruneLinkSamples;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:links:prune')]
#[Description('Delete private-network link measurements older than 30 days')]
final class PruneLinkSamplesCommand extends Command
{
    public function handle(PruneLinkSamples $prune): int
    {
        $result = $prune->handle();

        $this->info("Deleted {$result['samples']} hourly sample(s) and {$result['links']} stale link(s).");

        return self::SUCCESS;
    }
}

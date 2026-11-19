<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Traces\ProvisionTraceKeys;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Request trace keys (docs/23 §4): a key for every edge that supports
 * traces, rotation after 30 days, deletion after 90.
 */
#[Signature('launchroom:traces:keys')]
#[Description('Create, rotate and delete the edges\' request trace keys (docs/23 §4)')]
final class ProvisionTraceKeysCommand extends Command
{
    public function handle(ProvisionTraceKeys $provision): int
    {
        $created = $provision->handle();
        $this->info(count($created) === 0 ? 'Trace keys are up to date.' : 'New trace keys for '.count($created).' '.(count($created) === 1 ? 'server' : 'servers').'.');

        return self::SUCCESS;
    }
}

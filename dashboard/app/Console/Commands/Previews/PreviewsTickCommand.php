<?php

declare(strict_types=1);

namespace App\Console\Commands\Previews;

use App\Actions\Previews\AdvancePreviews;
use App\Actions\Previews\ExpirePreviews;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every minute: preparing previews continue, queued previews start when a
 * slot is free; previews inactive for their project's expiry are torn down.
 */
#[Signature('launchroom:previews:tick')]
#[Description('Advance preview environments (data preparation, queue) and expire inactive ones')]
final class PreviewsTickCommand extends Command
{
    public function handle(AdvancePreviews $advance, ExpirePreviews $expire): int
    {
        $expired = $expire->handle();
        $advanced = $advance->handle();

        if ($expired + $advanced > 0) {
            $this->components->info("{$advanced} advanced, {$expired} expired.");
        }

        return self::SUCCESS;
    }
}

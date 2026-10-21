<?php

declare(strict_types=1);

namespace App\Console\Commands\Previews;

use App\Actions\Previews\SweepOrphanPreviews;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:previews:sweep-orphans')]
#[Description('Tear down previews whose pull request is closed on GitHub (missed webhooks)')]
final class SweepOrphanPreviewsCommand extends Command
{
    public function handle(SweepOrphanPreviews $sweep): int
    {
        $this->components->info($sweep->handle().' orphaned preview(s) closed.');

        return self::SUCCESS;
    }
}

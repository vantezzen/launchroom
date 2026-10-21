<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Registry\PrunedImages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:registry:check-retained-images')]
#[Description('Mark deployments whose built image the registry pruned as not rollbackable')]
final class CheckRetainedImagesCommand extends Command
{
    public function handle(PrunedImages $prunedImages): int
    {
        $marked = $prunedImages->markPruned();
        $this->info("{$marked} deployment(s) marked as pruned.");

        return self::SUCCESS;
    }
}

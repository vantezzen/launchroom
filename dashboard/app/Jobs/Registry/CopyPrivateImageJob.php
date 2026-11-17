<?php

declare(strict_types=1);

namespace App\Jobs\Registry;

use App\Actions\Registry\RunPrivateImageCopy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * Copies a private image into launchroom's registry (D91) off the request
 * path; runs on the long lane (config/queue.php). Only the copy's id
 * travels. One attempt: a failed copy is retried when a deploy asks for the
 * image again (MirrorPrivateImage).
 */
#[Timeout(3600)]
#[Tries(1)]
#[UniqueFor(3600)]
final class CopyPrivateImageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $copyId,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->copyId;
    }

    public function handle(RunPrivateImageCopy $copy): void
    {
        $copy->handle($this->copyId);
    }
}

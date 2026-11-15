<?php

declare(strict_types=1);

namespace App\Listeners\Previews;

use App\Actions\Git\SyncPullRequestPreviews;
use App\Events\Git\PullRequestEventReceived;

final readonly class SyncPreviewsOnPullRequest
{
    public function __construct(
        private SyncPullRequestPreviews $sync,
    ) {}

    public function handle(PullRequestEventReceived $event): void
    {
        $this->sync->handle($event);
    }
}

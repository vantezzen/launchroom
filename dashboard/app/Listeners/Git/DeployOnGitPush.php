<?php

declare(strict_types=1);

namespace App\Listeners\Git;

use App\Actions\Git\DeployPush;
use App\Events\Git\GitPushReceived;

final readonly class DeployOnGitPush
{
    public function __construct(
        private DeployPush $deploy,
    ) {}

    public function handle(GitPushReceived $event): void
    {
        $this->deploy->handle($event);
    }
}

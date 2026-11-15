<?php

declare(strict_types=1);

namespace App\Listeners\Git;

use App\Events\Git\GitConnectionsChanged;
use App\Services\Git\AvatarOrigins;

final readonly class ForgetAvatarOrigins
{
    public function handle(GitConnectionsChanged $event): void
    {
        AvatarOrigins::forget();
    }
}

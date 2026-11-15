<?php

declare(strict_types=1);

namespace App\Listeners\Previews;

use App\Events\Teams\MemberAccessChanged;
use App\Services\Previews\PreviewAccess;

final class ForgetPreviewAccessOnAccessChange
{
    public function handle(MemberAccessChanged $event): void
    {
        PreviewAccess::forgetMember($event->teamId, $event->userId);
    }
}

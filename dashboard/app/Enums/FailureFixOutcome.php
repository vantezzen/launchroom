<?php

declare(strict_types=1);

namespace App\Enums;

/** What applying a failure fix did (ApiFailureFixResultData). */
enum FailureFixOutcome: string
{
    case Deploying = 'deploying';
    case Staged = 'staged';
    case FreeingDisk = 'freeing_disk';

    public function message(): string
    {
        return match ($this) {
            self::Deploying => 'Fix staged and deploying.',
            self::Staged => 'Fix staged. Other changes are staged too: review and apply them together.',
            self::FreeingDisk => 'launchroom is freeing space on the server. Deploy again once it is done.',
        };
    }
}

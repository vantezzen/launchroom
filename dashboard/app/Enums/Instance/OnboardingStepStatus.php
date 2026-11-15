<?php

declare(strict_types=1);

namespace App\Enums\Instance;

enum OnboardingStepStatus: string
{
    case Done = 'done';
    case Pending = 'pending';
    case Skipped = 'skipped';
}

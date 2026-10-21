<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * How a role hint is drawn (docs/design-system.md §13.9): neutral info, or
 * amber for a real risk (a deploy that would fail, a server too small for
 * builds next to apps).
 */
enum RoleHintTone: string
{
    case Info = 'info';
    case Warning = 'warning';
}

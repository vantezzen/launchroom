<?php

declare(strict_types=1);

namespace App\Enums\Previews;

/**
 * Previews for pull requests from forks (docs/09 R20): none by default; with
 * `approval`, a maintainer approves each PR (button or a label applied by a
 * user with write access) and the preview gets no secrets.
 */
enum ForkPolicy: string
{
    case Off = 'off';
    case Approval = 'approval';
}

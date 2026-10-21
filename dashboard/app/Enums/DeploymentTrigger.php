<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentTrigger: string
{
    case Push = 'push';
    case PullRequest = 'pr';
    case Tag = 'tag';
    case Manual = 'manual';
    case Rollback = 'rollback';
    case Promote = 'promote';
    /** A Deploy of staged configuration changes. */
    case Config = 'config';
    case Api = 'api';
}

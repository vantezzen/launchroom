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
    /** Restart (D68): the running version's containers, recreated. */
    case Restart = 'restart';
    /** Start of a stopped service (D68). */
    case Start = 'start';

    /**
     * Deployments that run an image again as it is: no build and no
     * release command (migrations already ran).
     */
    public function rerunsImage(): bool
    {
        return in_array($this, [self::Rollback, self::Restart, self::Start], true);
    }
}

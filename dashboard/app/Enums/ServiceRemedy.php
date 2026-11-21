<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Canvas\NodeStatus;

/**
 * The one-click fix a service's status offers (D108, docs/08 §23), set by
 * {@see NodeStatus} with the status so every surface offers the same one:
 * the attention popover, the current deployment card. Each runs the same
 * lifecycle action as the service panel (deploy permission).
 */
enum ServiceRemedy: string
{
    /** Stopped in launchroom: `services.lifecycle.start`. */
    case Start = 'start';
    /** Its containers exited or are gone while it should run: new containers of the live version (`services.lifecycle.restart`). */
    case StartContainers = 'start_containers';
    /** It crashed: `services.lifecycle.restart`. */
    case Restart = 'restart';
    /** The latest deploy failed: the same deployment again (`deployments.redeploy`). */
    case RetryDeploy = 'retry_deploy';

    public function label(): string
    {
        return match ($this) {
            self::Start, self::StartContainers => 'Start',
            self::Restart => 'Restart',
            self::RetryDeploy => 'Retry deploy',
        };
    }
}

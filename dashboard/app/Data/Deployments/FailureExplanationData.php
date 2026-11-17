<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\FailureFixAction;
use Spatie\LaravelData\Data;

/**
 * A known deployment failure explained in plain words: what happened, why,
 * and how to fix it, with an optional one-click action (direction.md §9
 * "explained failure"). Matched by App\Support\Deployments\Failures\FailureCatalogue.
 *
 * Action params by action:
 * - set_port: `port` (detected port, when known), `currentPort` (configured)
 * - raise_health_timeout: `currentSeconds`, `suggestedSeconds` (start period)
 * - add_variable: `name`, optional `value` (a suggestion), `buildTime` ("1" when the build needs it), `type` ("secret")
 * - fix_volume_owner: `uid` (the image's user)
 * - free_disk: `server` (public id), `serverName`
 * - use_tcp_healthcheck, accept_4xx, add_capabilities: none
 */
final class FailureExplanationData extends Data
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $cause,
        public string $fix,
        public ?FailureFixAction $action = null,
        public array $params = [],
        /** The start of the app's answer to the health check, masked (ProbeStatus.body). */
        public ?string $answer = null,
    ) {}
}

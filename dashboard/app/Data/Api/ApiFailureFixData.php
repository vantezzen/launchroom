<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\FailureFixAction;
use Spatie\LaravelData\Data;

/**
 * A fix for an explained failure. `oneClick` fixes are applied with
 * `POST /deployments/{deployment}/fixes/{code}` (or `command`, the CLI's
 * line for it); the others need input only a person gives and say where
 * (`command` when the CLI can do it, else `dashboardUrl`).
 */
final class ApiFailureFixData extends Data
{
    public function __construct(
        public FailureFixAction $code,
        /** What the fix does, as the dashboard's button says it. */
        public string $label,
        public bool $oneClick,
        /** The launchroom CLI command that applies it, when there is one. */
        public ?string $command,
        /** Where the dashboard applies it. */
        public string $dashboardUrl,
        /** `set_port`: the port the app listens on. */
        public ?int $port = null,
        /** `raise_health_timeout`: the start period it suggests, in seconds. */
        public ?int $seconds = null,
        /** `free_disk`: the server to free space on. */
        public ?string $server = null,
        /** `add_variable`: the variable to set. */
        public ?string $variable = null,
    ) {}
}

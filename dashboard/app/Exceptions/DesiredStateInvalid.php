<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A server's desired state could not be rendered into a valid NodeState
 * because of the deployment being advanced (App\Services\NodeStates\NodeStateValidator).
 * The previous NodeState stays published; the deployment fails with
 * `config_error` and this message.
 */
final class DesiredStateInvalid extends RuntimeException {}
